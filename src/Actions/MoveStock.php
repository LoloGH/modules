<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Models\Transfer;
use Keneya\Pharmacie\Models\TransferLine;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Services\StockPicker;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Text;

/**
 * Le cycle d'un transfert : demander, valider, envoyer, recevoir.
 *
 * Chaque étape appartient à quelqu'un, et le stock ne bouge qu'à deux
 * moments : il **sort** à l'envoi, il **entre** à la réception. Entre les
 * deux, les unités sont en transit — visibles, et à personne.
 *
 * Ce qui est refusé :
 *
 *   - transférer vers l'emplacement d'où l'on part ;
 *   - envoyer plus que ce qui est disponible à l'origine ;
 *   - recevoir plus que ce qui est parti ;
 *   - recevoir moins sans justifier l'écart — c'est ainsi qu'on sait où le
 *     stock se perd.
 */
final class MoveStock
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StockLedger $ledger,
        private readonly StockPicker $picker,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int}>  $lines
     */
    public function request(Location $from, Location $to, array $lines, Authenticatable $actor, ?string $reason = null): Transfer
    {
        if ($from->id === $to->id) {
            throw new PharmacieRuleViolation('Un transfert va d\'un emplacement à un autre : choisissez deux emplacements différents.');
        }

        if ($lines === []) {
            throw new PharmacieRuleViolation('Un transfert doit porter au moins une ligne.');
        }

        return DB::transaction(function () use ($from, $to, $lines, $actor, $reason): Transfer {
            $transfer = Transfer::create([
                'facility_id' => Facility::current(),
                'number' => $this->numbers->next('transfer'),
                'from_location_id' => $from->id,
                'to_location_id' => $to->id,
                'status' => Transfer::STATUS_REQUESTED,
                'reason' => Text::clean($reason),
                'requested_by_id' => Actor::id($actor),
                'requested_by_name' => Actor::name($actor),
            ]);

            foreach ($lines as $index => $line) {
                $product = Product::query()->find($line['product_id'] ?? null);

                if ($product === null) {
                    throw new PharmacieRuleViolation(sprintf('Ligne %d : produit introuvable.', $index + 1));
                }

                $quantity = (int) ($line['quantity'] ?? 0);

                if ($quantity <= 0) {
                    throw new PharmacieRuleViolation(sprintf('Ligne %d (%s) : la quantité doit être supérieure à zéro.', $index + 1, $product->name));
                }

                TransferLine::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $product->id,
                    'label' => $product->label(),
                    'quantity' => $quantity,
                ]);
            }

            $this->auditor->record(
                'transfer_requested',
                $transfer,
                sprintf('Transfert %s demandé : %s → %s, %d ligne(s)', $transfer->number, $from->name, $to->name, count($lines)),
                [],
                ['from' => $from->code, 'to' => $to->code, 'lines' => count($lines)],
                $actor,
            );

            return $transfer->refresh();
        });
    }

    public function approve(Transfer $transfer, Authenticatable $actor): Transfer
    {
        return $this->decide($transfer, Transfer::STATUS_APPROVED, $actor, null);
    }

    public function refuse(Transfer $transfer, string $reason, Authenticatable $actor): Transfer
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new PharmacieRuleViolation('Un refus doit avoir un motif : celui qui a demandé doit savoir pourquoi.');
        }

        return $this->decide($transfer, Transfer::STATUS_REFUSED, $actor, $reason);
    }

    /**
     * Envoyer : les unités sortent de l'origine, lot par lot, en FEFO.
     */
    public function send(Transfer $transfer, Authenticatable $actor): Transfer
    {
        return DB::transaction(function () use ($transfer, $actor): Transfer {
            $fresh = Transfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== Transfer::STATUS_APPROVED) {
                throw new PharmacieRuleViolation(
                    "Le transfert {$fresh->number} n'est pas prêt à partir : {$fresh->statusLabel()}."
                );
            }

            $from = $fresh->from;

            foreach ($fresh->lines as $line) {
                $product = $line->product;

                if ($product === null) {
                    continue;
                }

                $plan = $this->picker->plan($product, $from, (int) $line->quantity);

                if ($plan['missing'] > 0) {
                    throw new PharmacieRuleViolation(sprintf(
                        '%s : %s ne dispose que de %d unité(s) sur les %d demandées.',
                        $line->label,
                        $from->name,
                        (int) $line->quantity - $plan['missing'],
                        (int) $line->quantity,
                    ));
                }

                // Un transfert prend le lot qui périme le premier, comme une
                // dispensation : le service receveur ne doit pas hériter du
                // plus vieux ni du plus neuf par hasard.
                foreach ($plan['lines'] as $row) {
                    $this->ledger->issue(
                        $row['batch'],
                        $from,
                        $row['quantity'],
                        StockMovement::KIND_TRANSFER_OUT,
                        $actor,
                        ['document' => $fresh, 'document_number' => $fresh->number, 'reason' => 'Vers '.$fresh->to?->name],
                    );
                }

                // Le lot retenu est inscrit sur la ligne : c'est lui qui sera
                // reçu à l'arrivée.
                $line->update(['batch_id' => $plan['lines'][0]['batch']->id]);
            }

            $fresh->update([
                'status' => Transfer::STATUS_SENT,
                'sent_at' => now(),
                'sent_by_name' => Actor::name($actor),
            ]);

            $this->auditor->record(
                'transfer_sent',
                $fresh,
                sprintf('Transfert %s parti de %s vers %s', $fresh->number, $from?->name, $fresh->to?->name),
                ['status' => Transfer::STATUS_APPROVED],
                ['status' => Transfer::STATUS_SENT],
                $actor,
            );

            return $fresh->refresh();
        });
    }

    /**
     * Recevoir : les unités entrent à destination. Un écart se justifie.
     *
     * @param  array<int, array{quantity: int, gap_reason?: ?string}>  $received  par identifiant de ligne
     */
    public function receive(Transfer $transfer, array $received, Authenticatable $actor): Transfer
    {
        return DB::transaction(function () use ($transfer, $received, $actor): Transfer {
            $fresh = Transfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== Transfer::STATUS_SENT) {
                throw new PharmacieRuleViolation(
                    "Le transfert {$fresh->number} n'est pas en transit : {$fresh->statusLabel()}."
                );
            }

            $to = $fresh->to;

            foreach ($fresh->lines as $line) {
                $row = $received[$line->id] ?? ['quantity' => (int) $line->quantity];
                $quantity = max(0, (int) ($row['quantity'] ?? 0));
                $gapReason = Text::clean($row['gap_reason'] ?? null);

                if ($quantity > (int) $line->quantity) {
                    throw new PharmacieRuleViolation(sprintf(
                        '%s : %d unité(s) sont parties, on ne peut pas en recevoir %d.',
                        $line->label,
                        (int) $line->quantity,
                        $quantity,
                    ));
                }

                if ($quantity < (int) $line->quantity && $gapReason === null) {
                    throw new PharmacieRuleViolation(sprintf(
                        '%s : %d unité(s) manquent à l\'arrivée. Justifiez l\'écart — c\'est ainsi qu\'on sait où le stock se perd.',
                        $line->label,
                        (int) $line->quantity - $quantity,
                    ));
                }

                $batch = $line->batch;

                if ($batch instanceof Batch && $quantity > 0) {
                    $this->ledger->receive(
                        $batch,
                        $to,
                        $quantity,
                        StockMovement::KIND_TRANSFER_IN,
                        $actor,
                        ['document' => $fresh, 'document_number' => $fresh->number, 'reason' => 'Depuis '.$fresh->from?->name],
                    );
                }

                $line->update(['received_quantity' => $quantity, 'gap_reason' => $gapReason]);
            }

            $fresh->update([
                'status' => Transfer::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by_name' => Actor::name($actor),
            ]);

            $gaps = (int) $fresh->lines()->get()->sum(fn (TransferLine $line): int => $line->gap());

            $this->auditor->record(
                'transfer_received',
                $fresh,
                sprintf(
                    'Transfert %s reçu à %s%s',
                    $fresh->number,
                    $to?->name,
                    $gaps > 0 ? sprintf(' — écart de %d unité(s)', $gaps) : '',
                ),
                ['status' => Transfer::STATUS_SENT],
                ['status' => Transfer::STATUS_RECEIVED, 'gap' => $gaps],
                $actor,
            );

            return $fresh->refresh();
        });
    }

    private function decide(Transfer $transfer, string $status, Authenticatable $actor, ?string $reason): Transfer
    {
        return DB::transaction(function () use ($transfer, $status, $actor, $reason): Transfer {
            $fresh = Transfer::query()->whereKey($transfer->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== Transfer::STATUS_REQUESTED) {
                throw new PharmacieRuleViolation("Le transfert {$fresh->number} est déjà tranché : {$fresh->statusLabel()}.");
            }

            $fresh->update([
                'status' => $status,
                'approved_at' => now(),
                'approved_by_name' => Actor::name($actor),
                'decision_reason' => $reason,
            ]);

            $this->auditor->record(
                $status === Transfer::STATUS_APPROVED ? 'transfer_approved' : 'transfer_refused',
                $fresh,
                sprintf(
                    'Transfert %s %s%s',
                    $fresh->number,
                    $status === Transfer::STATUS_APPROVED ? 'validé' : 'refusé',
                    $reason === null ? '' : ' : '.$reason,
                ),
                ['status' => Transfer::STATUS_REQUESTED],
                ['status' => $status, 'reason' => $reason],
                $actor,
            );

            return $fresh;
        });
    }
}
