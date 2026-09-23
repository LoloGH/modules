<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationBatch;
use Keneya\Pharmacie\Models\DispensationItem;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Services\StockPicker;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Money;
use Keneya\Pharmacie\Support\Text;
use Throwable;

/**
 * Délivrer : le geste central de la pharmacie.
 *
 * Ce que cette action garantit :
 *
 *   - **FEFO d'office** : sans lot désigné, on sert celui qui périme le
 *     premier, en répartissant sur plusieurs lots si nécessaire ;
 *   - **une dérogation se justifie** : choisir un autre lot est possible,
 *     mais le motif est obligatoire et reste écrit ;
 *   - **la dispensation partielle est normale** : ce qui manque devient un
 *     reliquat visible, jamais un silence ;
 *   - **rien de périmé ni de bloqué ne sort** : le grand livre le refuse ;
 *   - **tout ou rien** : la sortie de stock, les lignes et la pièce sont
 *     écrites dans une seule transaction.
 *
 * Ce qu'elle ne fait pas : encaisser. L'argent part par le contrat de vente
 * (`Contracts\SaleSink`), une fois la dispensation écrite.
 */
final class DispenseProducts
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StockLedger $ledger,
        private readonly StockPicker $picker,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int, prescribed_quantity?: int, posology?: ?string, batch_id?: ?int, override_reason?: ?string, substituted_for_id?: ?int, substitution_reason?: ?string, comment?: ?string, unit_price?: ?int}>  $lines
     * @param  array{patient_id?: ?string, patient_name?: ?string, source?: string, queue_ref?: ?string, prescription_ref?: ?string, notes?: ?string}  $details
     */
    public function handle(Location $location, array $lines, Authenticatable $dispenser, array $details = []): Dispensation
    {
        if ($lines === []) {
            throw new PharmacieRuleViolation('Une dispensation doit porter au moins une ligne.');
        }

        return DB::transaction(function () use ($location, $lines, $dispenser, $details): Dispensation {
            $dispensation = Dispensation::create([
                'facility_id' => Facility::current(),
                'number' => $this->numbers->next('dispensation'),
                'patient_id' => Text::clean($details['patient_id'] ?? null),
                'patient_name' => Text::clean($details['patient_name'] ?? null),
                'source' => $details['source'] ?? Dispensation::SOURCE_COUNTER,
                'queue_ref' => Text::clean($details['queue_ref'] ?? null),
                'prescription_ref' => Text::clean($details['prescription_ref'] ?? null),
                'location_id' => $location->id,
                'status' => Dispensation::STATUS_DISPENSED,
                'notes' => Text::clean($details['notes'] ?? null),
                'dispensed_by_id' => Actor::id($dispenser),
                'dispensed_by_name' => Actor::name($dispenser),
                'dispensed_at' => now(),
            ]);

            $total = 0;
            $outstanding = 0;
            $served = 0;

            foreach ($lines as $index => $line) {
                $result = $this->dispenseLine($dispensation, $location, $line, $dispenser, $index);
                $total += $result['amount'];
                $outstanding += $result['outstanding'];
                $served += $result['quantity'];
            }

            if ($served <= 0) {
                throw new PharmacieRuleViolation(
                    'Rien n\'a pu être délivré : aucun lot disponible pour les produits demandés.'
                );
            }

            $dispensation->update(['total' => $total, 'outstanding' => $outstanding]);

            $this->auditor->record(
                'dispensation_recorded',
                $dispensation,
                sprintf(
                    'Dispensation %s pour %s : %d ligne(s), %s%s',
                    $dispensation->number,
                    $dispensation->patient_name ?? $dispensation->patient_id ?? 'patient non désigné',
                    count($lines),
                    Money::format($total),
                    $outstanding > 0 ? sprintf(' — reliquat de %d unité(s)', $outstanding) : '',
                ),
                [],
                [
                    'patient_id' => $dispensation->patient_id,
                    'prescription' => $dispensation->prescription_ref,
                    'lines' => count($lines),
                    'total' => $total,
                    'outstanding' => $outstanding,
                ],
                $dispenser,
            );

            return $dispensation->refresh();
        });
    }

    /**
     * Dit au dossier médical ce qui a été servi sur son ordonnance.
     *
     * Appelé APRÈS la transaction : le stock a bougé, c'est un fait. Si le
     * dossier médical est injoignable, la dispensation reste écrite et la
     * pharmacie continue de tourner — on ne perd pas une sortie de stock
     * parce qu'un autre module ne répond pas.
     */
    public function reportToPrescriber(Dispensation $dispensation): void
    {
        if ($dispensation->prescription_ref === null) {
            return;
        }

        try {
            Pharmacie::prescriptionSink()->dispensed(
                (string) $dispensation->prescription_ref,
                $dispensation,
                (int) $dispensation->outstanding === 0,
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Annuler : le stock revient, lot par lot, par des écritures inverses.
     * Rien n'est effacé — la dispensation reste, marquée annulée.
     */
    public function cancel(Dispensation $dispensation, string $reason, Authenticatable $actor): Dispensation
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new PharmacieRuleViolation('Une annulation doit avoir un motif.');
        }

        return DB::transaction(function () use ($dispensation, $reason, $actor): Dispensation {
            $fresh = Dispensation::query()->whereKey($dispensation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isCancelled()) {
                throw new PharmacieRuleViolation("La dispensation {$fresh->number} est déjà annulée.");
            }

            $location = $fresh->location;

            foreach ($fresh->items()->with('batches.batch')->get() as $item) {
                foreach ($item->batches as $served) {
                    if ($served->batch === null) {
                        continue;
                    }

                    // Le stock revient tel qu'il est sorti : même lot, même
                    // emplacement. Un lot périmé entre-temps revient quand
                    // même — il faudra le détruire, pas le faire disparaître.
                    $this->ledger->receive(
                        $served->batch,
                        $location,
                        (int) $served->quantity,
                        StockMovement::KIND_CANCELLATION,
                        $actor,
                        [
                            'document' => $fresh,
                            'document_number' => $fresh->number,
                            'reason' => 'Annulation : '.$reason,
                        ],
                    );
                }
            }

            $fresh->update([
                'status' => Dispensation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_name' => Actor::name($actor),
                'cancellation_reason' => $reason,
            ]);

            $this->auditor->record(
                'dispensation_cancelled',
                $fresh,
                sprintf('Dispensation %s annulée : %s', $fresh->number, $reason),
                ['status' => Dispensation::STATUS_DISPENSED],
                ['status' => Dispensation::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );

            return $fresh;
        });
    }

    /**
     * @param  array{product_id: int, quantity: int, prescribed_quantity?: int, posology?: ?string, batch_id?: ?int, override_reason?: ?string, substituted_for_id?: ?int, substitution_reason?: ?string, comment?: ?string, unit_price?: ?int}  $line
     * @return array{amount: int, outstanding: int, quantity: int}
     */
    private function dispenseLine(Dispensation $dispensation, Location $location, array $line, Authenticatable $dispenser, int $index): array
    {
        $position = $index + 1;
        $product = Product::query()->find($line['product_id'] ?? null);

        if ($product === null) {
            throw new PharmacieRuleViolation("Ligne {$position} : produit introuvable.");
        }

        $wanted = (int) ($line['quantity'] ?? 0);
        $prescribed = (int) ($line['prescribed_quantity'] ?? $wanted);

        if ($wanted <= 0 && $prescribed <= 0) {
            throw new PharmacieRuleViolation("Ligne {$position} ({$product->name}) : la quantité doit être supérieure à zéro.");
        }

        $substitutedFor = isset($line['substituted_for_id']) && $line['substituted_for_id'] !== null
            ? Product::query()->find((int) $line['substituted_for_id'])
            : null;

        if ($substitutedFor !== null && Text::clean($line['substitution_reason'] ?? null) === null) {
            throw new PharmacieRuleViolation(
                "Ligne {$position} : une substitution doit être justifiée — ce qui est délivré n'est pas ce qui a été prescrit."
            );
        }

        $unitPrice = isset($line['unit_price']) && $line['unit_price'] !== null
            ? max(0, (int) $line['unit_price'])
            : (int) ($product->sale_price ?? 0);

        $item = DispensationItem::create([
            'dispensation_id' => $dispensation->id,
            'product_id' => $product->id,
            'label' => $product->label(),
            'posology' => Text::clean($line['posology'] ?? null),
            'prescribed_quantity' => max($prescribed, 0),
            'quantity' => 0,
            'unit_price' => $unitPrice,
            'amount' => 0,
            'substituted_for_id' => $substitutedFor?->id,
            'substitution_reason' => Text::clean($line['substitution_reason'] ?? null),
            'comment' => Text::clean($line['comment'] ?? null),
        ]);

        $plan = $this->planFor($product, $location, $wanted, $line, $position);

        $served = 0;

        foreach ($plan as $row) {
            $this->ledger->issue(
                $row['batch'],
                $location,
                $row['quantity'],
                StockMovement::KIND_DISPENSING,
                $dispenser,
                ['document' => $dispensation, 'document_number' => $dispensation->number],
            );

            DispensationBatch::create([
                'dispensation_item_id' => $item->id,
                'batch_id' => $row['batch']->id,
                'quantity' => $row['quantity'],
                'overrode_fefo' => $row['overrode_fefo'],
                'override_reason' => $row['override_reason'],
            ]);

            $served += $row['quantity'];
        }

        $amount = $served * $unitPrice;

        $item->update(['quantity' => $served, 'amount' => $amount]);

        return [
            'amount' => $amount,
            'outstanding' => max(0, (int) $item->prescribed_quantity - $served),
            'quantity' => $served,
        ];
    }

    /**
     * Le plan de sortie : FEFO par défaut, ou le lot désigné — et alors la
     * dérogation est tracée.
     *
     * @param  array{batch_id?: ?int, override_reason?: ?string}  $line
     * @return list<array{batch: Batch, quantity: int, overrode_fefo: bool, override_reason: ?string}>
     */
    private function planFor(Product $product, Location $location, int $wanted, array $line, int $position): array
    {
        if ($wanted <= 0) {
            return [];
        }

        if (! isset($line['batch_id']) || $line['batch_id'] === null) {
            return array_map(
                static fn (array $row): array => [
                    'batch' => $row['batch'],
                    'quantity' => $row['quantity'],
                    'overrode_fefo' => false,
                    'override_reason' => null,
                ],
                $this->picker->plan($product, $location, $wanted)['lines'],
            );
        }

        $batch = Batch::query()->find((int) $line['batch_id']);

        if ($batch === null || (int) $batch->product_id !== (int) $product->id) {
            throw new PharmacieRuleViolation("Ligne {$position} ({$product->name}) : ce lot n'appartient pas à ce produit.");
        }

        $suggested = $this->picker->suggest($product, $location);
        $overrode = $suggested !== null && (int) $suggested->id !== (int) $batch->id;
        $reason = Text::clean($line['override_reason'] ?? null);

        // Servir un autre lot que celui proposé est permis — mais jamais en
        // silence : c'est la règle qui protège le FEFO d'être contourné par
        // habitude.
        if ($overrode && $reason === null) {
            throw new PharmacieRuleViolation(sprintf(
                'Ligne %d (%s) : le lot %s périme avant le lot %s. Pour servir celui-ci, indiquez un motif.',
                $position,
                $product->name,
                $suggested->number,
                $batch->number,
            ));
        }

        $available = $this->ledger->available($batch, $location);

        return [[
            'batch' => $batch,
            'quantity' => min($wanted, $available),
            'overrode_fefo' => $overrode,
            'override_reason' => $overrode ? $reason : null,
        ]];
    }
}
