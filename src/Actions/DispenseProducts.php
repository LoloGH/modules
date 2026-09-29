<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationItem;
use Keneya\Pharmacie\Models\Location;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Models\ProductCoverage;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Services\LineDispenser;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Controlled;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Money;
use Keneya\Pharmacie\Support\Text;
use Throwable;

/**
 * Délivrer : le geste central de la pharmacie.
 *
 * C'est le chemin court : on sert et on encaisse ensuite. Quand
 * l'établissement demande le paiement avant la délivrance, le comptoir passe
 * par {@see PrepareDispensation} puis {@see DeliverPreparation}.
 *
 * Ce que cette action garantit :
 *
 *   - **la dispensation partielle est normale** : ce qui manque devient un
 *     reliquat visible, jamais un silence ;
 *   - **rien de périmé ni de bloqué ne sort** : le grand livre le refuse ;
 *   - **tout ou rien** : la sortie de stock, les lignes et la pièce sont
 *     écrites dans une seule transaction.
 *
 * Le choix des lots, lui, appartient à {@see LineDispenser}, partagé avec la
 * délivrance d'une préparation : le FEFO et ses dérogations ne doivent pas
 * dépendre du chemin emprunté.
 *
 * Ce qu'elle ne fait pas : encaisser. L'argent part par le contrat de vente
 * (`Contracts\SaleSink`), une fois la dispensation écrite.
 */
final class DispenseProducts
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly StockLedger $ledger,
        private readonly LineDispenser $lines,
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
                // La prise en charge vaut ici aussi : la caisse decoupera la
                // facture, que le patient ait paye avant ou apres.
                'coverage_insurer' => Text::clean($details['coverage']['insurer'] ?? null),
                'coverage_rate' => $details['coverage']['rate'] ?? null,
                'coverage_reference' => Text::clean($details['coverage']['reference'] ?? null),
            ]);

            $total = 0;
            $outstanding = 0;
            $served = 0;

            foreach ($lines as $index => $line) {
                $result = $this->dispenseLine($dispensation, $location, $line, $dispenser, $index, $details['coverage'] ?? null);
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
                    $outstanding > 0 ? sprintf(', reliquat de %d unité(s)', $outstanding) : '',
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
     * pharmacie continue de tourner, on ne perd pas une sortie de stock
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
     * Rien n'est effacé, la dispensation reste, marquée annulée.
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
                    // même, il faudra le détruire, pas le faire disparaître.
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
    /**
     * L'organisme qui porte cette ligne, et son taux.
     *
     * Le comptoir choisit ligne par ligne, parmi les organismes qui couvrent
     * ce produit-la. Ce qui n'est pas choisi reste a la charge du patient.
     *
     * @param  array<string, mixed>  $line
     * @param  array{insurer?: ?string, insurer_ref?: ?string, rate?: ?int}|null  $coverage
     * @return array{ref: ?string, name: ?string, rate: int}
     */
    private function coverageFor(array $line, ?array $coverage, Product $product): array
    {
        $ref = Text::clean($line['insurer_ref'] ?? null);

        if ($ref !== null) {
            $regle = ProductCoverage::query()->ofFacility()
                ->where('product_id', $product->getKey())
                ->forInsurer($ref)
                ->first();

            if ($regle !== null) {
                return ['ref' => $regle->insurer_ref, 'name' => $regle->insurer_name, 'rate' => (int) $regle->rate];
            }
        }

        $global = $coverage['insurer'] ?? null;
        $taux = (int) ($coverage['rate'] ?? 0);

        return $global !== null && ($coverage['insurer_ref'] ?? null) === null && $taux > 0
            ? ['ref' => null, 'name' => $global, 'rate' => $taux]
            : ['ref' => null, 'name' => null, 'rate' => 0];
    }

    /**
     * L'emplacement d'où cette ligne est prise, s'il diffère de celui de la
     * dispensation.
     *
     * @param  array<string, mixed>  $line
     */
    private function lineLocation(array $line, Dispensation $dispensation, int $position): ?Location
    {
        $id = $line['location_id'] ?? null;

        if ($id === null) {
            return null;
        }

        $location = Location::query()->ofFacility()->active()->whereKey((int) $id)->first();

        if ($location === null) {
            throw new PharmacieRuleViolation("Ligne {$position} : cet emplacement n'existe pas, ou n'est plus actif.");
        }

        return $location;
    }

    private function dispenseLine(Dispensation $dispensation, Location $location, array $line, Authenticatable $dispenser, int $index, ?array $coverage = null): array
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

        // Un produit sous surveillance exige davantage : une habilitation,
        // une ordonnance, un patient nommé. Ce qu'il exige exactement vient
        // de la configuration, car la règle change d'un pays à l'autre.
        Controlled::assertDispensable($product, [
            'patient_id' => $dispensation->patient_id,
            'patient_name' => $dispensation->patient_name,
            'prescription_ref' => $dispensation->prescription_ref,
        ], $dispenser, $position);

        $substitutedFor = isset($line['substituted_for_id']) && $line['substituted_for_id'] !== null
            ? Product::query()->find((int) $line['substituted_for_id'])
            : null;

        if ($substitutedFor !== null && Text::clean($line['substitution_reason'] ?? null) === null) {
            throw new PharmacieRuleViolation(
                "Ligne {$position} : une substitution doit être justifiée : ce qui est délivré n'est pas ce qui a été prescrit."
            );
        }

        $unitPrice = isset($line['unit_price']) && $line['unit_price'] !== null
            ? max(0, (int) $line['unit_price'])
            : (int) ($product->sale_price ?? 0);

        // La ligne peut être prise ailleurs qu'au comptoir : le vaccin dans la
        // chaîne du froid, ce qui n'est pas descendu à la centrale. Nulle,
        // elle suit l'emplacement de la dispensation.
        $from = $this->lineLocation($line, $dispensation, $position) ?? $location;
        $prise = $this->coverageFor($line, $coverage, $product);

        $item = DispensationItem::create([
            'dispensation_id' => $dispensation->id,
            'product_id' => $product->id,
            'location_id' => $from->is($location) ? null : $from->id,
            'label' => $product->label(),
            // Ce que porte CETTE ligne.
            'insurer_ref' => $prise['ref'],
            'insurer_name' => $prise['name'],
            'insurer_rate' => $prise['rate'],
            'posology' => Text::clean($line['posology'] ?? null),
            'prescribed_quantity' => max($prescribed, 0),
            'quantity' => 0,
            'unit_price' => $unitPrice,
            'amount' => 0,
            'substituted_for_id' => $substitutedFor?->id,
            'substitution_reason' => Text::clean($line['substitution_reason'] ?? null),
            'comment' => Text::clean($line['comment'] ?? null),
        ]);

        $served = $this->lines->serve(
            $dispensation,
            $item,
            $from,
            $wanted,
            ['batch_id' => $line['batch_id'] ?? null, 'override_reason' => $line['override_reason'] ?? null],
            $dispenser,
            $position,
        );

        $amount = $served * $unitPrice;

        return [
            'amount' => $amount,
            'outstanding' => max(0, (int) $item->prescribed_quantity - $served),
            'quantity' => $served,
        ];
    }
}
