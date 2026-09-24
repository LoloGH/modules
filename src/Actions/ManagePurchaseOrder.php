<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\PurchaseOrder;
use Keneya\Pharmacie\Models\PurchaseOrderItem;
use Keneya\Pharmacie\Models\Supplier;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Money;
use Keneya\Pharmacie\Support\Text;

/**
 * Le cycle d'une commande : on la prépare, on l'envoie, on la suit, et on
 * l'annule si elle ne viendra pas.
 *
 * Une commande envoyée ne se réécrit pas, c'est un engagement pris auprès
 * d'un fournisseur. Elle s'annule, avec un motif, et seulement tant que rien
 * n'a été reçu : au-delà, ce qui est arrivé est arrivé.
 */
final class ManagePurchaseOrder
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int, unit_price?: int}>  $lines
     * @param  array{expected_on?: ?string, notes?: ?string}  $details
     */
    public function create(Supplier $supplier, array $lines, Authenticatable $actor, array $details = []): PurchaseOrder
    {
        if ($lines === []) {
            throw new PharmacieRuleViolation('Une commande doit porter au moins une ligne.');
        }

        return DB::transaction(function () use ($supplier, $lines, $actor, $details): PurchaseOrder {
            $order = PurchaseOrder::create([
                'facility_id' => Facility::current(),
                'number' => $this->numbers->next('order'),
                'supplier_id' => $supplier->id,
                'status' => PurchaseOrder::STATUS_DRAFT,
                'ordered_on' => now()->toDateString(),
                'expected_on' => isset($details['expected_on']) && $details['expected_on'] !== null
                    ? Carbon::parse((string) $details['expected_on'])->toDateString()
                    : ($supplier->lead_time_days === null ? null : now()->addDays((int) $supplier->lead_time_days)->toDateString()),
                'notes' => Text::clean($details['notes'] ?? null),
                'created_by_id' => Actor::id($actor),
                'created_by_name' => Actor::name($actor),
            ]);

            $total = 0;

            foreach ($lines as $index => $line) {
                $product = Product::query()->find($line['product_id'] ?? null);

                if ($product === null) {
                    throw new PharmacieRuleViolation(sprintf('Ligne %d : produit introuvable.', $index + 1));
                }

                $quantity = (int) ($line['quantity'] ?? 0);

                if ($quantity <= 0) {
                    throw new PharmacieRuleViolation(sprintf('Ligne %d (%s) : la quantité doit être supérieure à zéro.', $index + 1, $product->name));
                }

                $unitPrice = max(0, (int) ($line['unit_price'] ?? 0));
                $amount = $unitPrice * $quantity;
                $total += $amount;

                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $product->id,
                    // Le libellé est figé ici : si le catalogue change, la
                    // commande passée garde ce qui a été commandé.
                    'label' => $product->label(),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                ]);
            }

            $order->update(['total' => $total]);

            $this->auditor->record(
                'order_created',
                $order,
                sprintf('Commande %s pour %s : %d ligne(s), %s', $order->number, $supplier->name, count($lines), Money::format($total)),
                [],
                ['supplier' => $supplier->code, 'lines' => count($lines), 'total' => $total],
                $actor,
            );

            return $order->refresh();
        });
    }

    /**
     * Envoyer la commande : elle devient un engagement, et n'est plus
     * modifiable.
     */
    public function send(PurchaseOrder $order, Authenticatable $actor): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $actor): PurchaseOrder {
            $fresh = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== PurchaseOrder::STATUS_DRAFT) {
                throw new PharmacieRuleViolation("La commande {$fresh->number} a déjà été envoyée : {$fresh->statusLabel()}.");
            }

            $fresh->update([
                'status' => PurchaseOrder::STATUS_SENT,
                'sent_at' => now(),
                'sent_by_name' => Actor::name($actor),
            ]);

            $this->auditor->record(
                'order_sent',
                $fresh,
                sprintf('Commande %s envoyée au fournisseur', $fresh->number),
                ['status' => PurchaseOrder::STATUS_DRAFT],
                ['status' => PurchaseOrder::STATUS_SENT],
                $actor,
            );

            return $fresh;
        });
    }

    public function cancel(PurchaseOrder $order, string $reason, Authenticatable $actor): PurchaseOrder
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new PharmacieRuleViolation('Une annulation doit avoir un motif.');
        }

        return DB::transaction(function () use ($order, $reason, $actor): PurchaseOrder {
            $fresh = PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status === PurchaseOrder::STATUS_CANCELLED) {
                throw new PharmacieRuleViolation("La commande {$fresh->number} est déjà annulée.");
            }

            if ((int) $fresh->items()->sum('received_quantity') > 0) {
                throw new PharmacieRuleViolation(
                    "La commande {$fresh->number} a déjà reçu des livraisons : elle ne s'annule plus."
                );
            }

            $before = $fresh->status;

            $fresh->update([
                'status' => PurchaseOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            $this->auditor->record(
                'order_cancelled',
                $fresh,
                sprintf('Commande %s annulée : %s', $fresh->number, $reason),
                ['status' => $before],
                ['status' => PurchaseOrder::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );

            return $fresh;
        });
    }
}
