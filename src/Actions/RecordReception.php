<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\PurchaseOrder;
use Keneya\Pharmacie\Models\PurchaseOrderItem;
use Keneya\Pharmacie\Models\Reception;
use Keneya\Pharmacie\Models\ReceptionLine;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Models\Supplier;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * Enregistrer une réception : le moment où des unités entrent réellement.
 *
 * C'est un contrôle avant d'être une saisie. Chaque ligne exige son **lot** et
 * sa **date de péremption** : sans elles, on ne saura plus jamais ce qu'on
 * détient ni jusqu'à quand, et le rappel d'un lot deviendrait impossible.
 *
 * Tout ou rien : les lots naissent, les unités entrent par le grand livre et
 * la commande se met à jour dans une seule transaction. Une réception à moitié
 * écrite serait pire que pas de réception du tout.
 *
 * Ce qui est refusé :
 *
 *   - une ligne sans lot, sans quantité ou sans péremption pour un
 *     médicament ;
 *   - une péremption déjà passée : on ne reçoit pas du périmé sans le dire ;
 *   - une quantité supérieure à ce qui restait à recevoir sur la commande.
 */
final class RecordReception
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StockLedger $ledger,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{product_id: int, batch_number: string, quantity: int, expires_on?: ?string, manufactured_on?: ?string, unit_price?: int, sale_price?: ?int, order_item_id?: ?int}>  $lines
     * @param  array{purchase_order_id?: ?int, delivery_note?: ?string, anomalies?: ?string, notes?: ?string, received_on?: ?string, allow_expired?: bool}  $details
     */
    public function handle(Supplier $supplier, Location $location, array $lines, Authenticatable $actor, array $details = []): Reception
    {
        if ($lines === []) {
            throw new PharmacieRuleViolation('Une réception doit porter au moins une ligne.');
        }

        return DB::transaction(function () use ($supplier, $location, $lines, $actor, $details): Reception {
            $order = isset($details['purchase_order_id'])
                ? PurchaseOrder::query()->whereKey($details['purchase_order_id'])->lockForUpdate()->first()
                : null;

            if ($order !== null && ! $order->isOpen()) {
                throw new PharmacieRuleViolation(
                    "La commande {$order->number} n'attend plus de livraison : {$order->statusLabel()}."
                );
            }

            $receivedOn = isset($details['received_on']) && $details['received_on'] !== null
                ? Carbon::parse((string) $details['received_on'])
                : now();

            $reception = Reception::create([
                'facility_id' => Facility::current(),
                'number' => $this->numbers->next('reception'),
                'supplier_id' => $supplier->id,
                'purchase_order_id' => $order?->id,
                'location_id' => $location->id,
                'received_on' => $receivedOn->toDateString(),
                'delivery_note' => Text::clean($details['delivery_note'] ?? null),
                'anomalies' => Text::clean($details['anomalies'] ?? null),
                'notes' => Text::clean($details['notes'] ?? null),
                'received_by_id' => Actor::id($actor),
                'received_by_name' => Actor::name($actor),
            ]);

            $total = 0;

            foreach ($lines as $index => $line) {
                $total += $this->receiveLine($reception, $supplier, $location, $order, $line, $actor, $index);
            }

            $reception->update(['total' => $total]);

            $order?->refreshStatus();

            $this->auditor->record(
                'reception_recorded',
                $reception,
                sprintf(
                    'Réception %s de %s : %d ligne(s) entrée(s) à %s%s',
                    $reception->number,
                    $supplier->name,
                    count($lines),
                    $location->name,
                    $reception->anomalies === null ? '' : ', anomalies signalées',
                ),
                [],
                [
                    'supplier' => $supplier->code,
                    'location' => $location->code,
                    'order' => $order?->number,
                    'lines' => count($lines),
                    'total' => $total,
                    'anomalies' => $reception->anomalies,
                ],
                $actor,
            );

            return $reception->refresh();
        });
    }

    /**
     * @param  array{product_id: int, batch_number: string, quantity: int, expires_on?: ?string, manufactured_on?: ?string, unit_price?: int, sale_price?: ?int, order_item_id?: ?int}  $line
     */
    private function receiveLine(
        Reception $reception,
        Supplier $supplier,
        Location $location,
        ?PurchaseOrder $order,
        array $line,
        Authenticatable $actor,
        int $index,
    ): int {
        $position = $index + 1;
        $product = Product::query()->find($line['product_id'] ?? null);

        if ($product === null) {
            throw new PharmacieRuleViolation("Ligne {$position} : produit introuvable.");
        }

        $quantity = (int) ($line['quantity'] ?? 0);

        if ($quantity <= 0) {
            throw new PharmacieRuleViolation("Ligne {$position} ({$product->name}) : la quantité doit être supérieure à zéro.");
        }

        $number = Text::clean($line['batch_number'] ?? null);

        if ($number === null) {
            throw new PharmacieRuleViolation(
                "Ligne {$position} ({$product->name}) : le numéro de lot est obligatoire. Sans lui, un rappel serait impossible."
            );
        }

        $expiresOn = isset($line['expires_on']) && $line['expires_on'] !== null
            ? Carbon::parse((string) $line['expires_on'])->startOfDay()
            : null;

        if ($expiresOn === null && $product->kind === Product::KIND_MEDICINE) {
            throw new PharmacieRuleViolation(
                "Ligne {$position} ({$product->name}) : la date de péremption est obligatoire pour un médicament."
            );
        }

        if ($expiresOn !== null && $expiresOn->lt(now()->startOfDay()) && ($line['allow_expired'] ?? false) !== true) {
            throw new PharmacieRuleViolation(sprintf(
                'Ligne %d (%s) : le lot %s est déjà périmé (%s). Il ne peut pas entrer en stock.',
                $position,
                $product->name,
                $number,
                $expiresOn->format('d/m/Y'),
            ));
        }

        $unitPrice = max(0, (int) ($line['unit_price'] ?? 0));
        $amount = $unitPrice * $quantity;

        // Le lot : celui qui existe déjà pour ce produit et ce numéro, ou un
        // nouveau. Deux réceptions du même lot s'additionnent, elles ne se
        // dédoublent pas.
        $batch = Batch::query()
            ->where('facility_id', $reception->facility_id)
            ->where('product_id', $product->id)
            ->where('number', $number)
            ->lockForUpdate()
            ->first();

        if ($batch === null) {
            $batch = Batch::create([
                'facility_id' => $reception->facility_id,
                'product_id' => $product->id,
                'number' => $number,
                'supplier_id' => $supplier->id,
                'supplier_name' => $supplier->name,
                'received_on' => $reception->received_on,
                'manufactured_on' => isset($line['manufactured_on']) && $line['manufactured_on'] !== null
                    ? Carbon::parse((string) $line['manufactured_on'])->toDateString()
                    : null,
                'expires_on' => $expiresOn?->toDateString(),
                'purchase_price' => $unitPrice,
                'sale_price' => isset($line['sale_price']) && $line['sale_price'] !== null
                    ? (int) $line['sale_price']
                    : $product->sale_price,
                'status' => Batch::STATUS_ACTIVE,
            ]);
        } elseif ($expiresOn !== null && $batch->expires_on !== null && ! $batch->expires_on->isSameDay($expiresOn)) {
            // Deux péremptions différentes sous le même numéro : c'est une
            // anomalie, pas un détail. On refuse plutôt que d'écraser.
            throw new PharmacieRuleViolation(sprintf(
                'Ligne %d (%s) : le lot %s existe déjà avec une péremption au %s. Vérifiez le numéro de lot.',
                $position,
                $product->name,
                $number,
                $batch->expires_on->format('d/m/Y'),
            ));
        }

        ReceptionLine::create([
            'reception_id' => $reception->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'amount' => $amount,
        ]);

        $this->ledger->receive($batch, $location, $quantity, StockMovement::KIND_RECEPTION, $actor, [
            'document' => $reception,
            'document_number' => $reception->number,
            'reason' => $reception->delivery_note === null ? null : 'BL '.$reception->delivery_note,
        ]);

        $this->matchOrderItem($order, $line, $product, $quantity, $position);

        return $amount;
    }

    /**
     * Rapproche la ligne reçue de la commande : on ne reçoit pas plus que ce
     * qui restait attendu, sinon la commande ne veut plus rien dire.
     *
     * @param  array{order_item_id?: ?int}  $line
     */
    private function matchOrderItem(?PurchaseOrder $order, array $line, Product $product, int $quantity, int $position): void
    {
        if ($order === null) {
            return;
        }

        $item = isset($line['order_item_id'])
            ? PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->whereKey($line['order_item_id'])->lockForUpdate()->first()
            : PurchaseOrderItem::query()->where('purchase_order_id', $order->id)->where('product_id', $product->id)->lockForUpdate()->first();

        if ($item === null) {
            throw new PharmacieRuleViolation(sprintf(
                'Ligne %d (%s) : ce produit ne figure pas sur la commande %s.',
                $position,
                $product->name,
                $order->number,
            ));
        }

        if ($quantity > $item->outstanding()) {
            throw new PharmacieRuleViolation(sprintf(
                'Ligne %d (%s) : la commande %s n\'attend plus que %d unité(s), %d ne peuvent pas être reçues.',
                $position,
                $product->name,
                $order->number,
                $item->outstanding(),
                $quantity,
            ));
        }

        $item->update(['received_quantity' => (int) $item->received_quantity + $quantity]);
    }
}
