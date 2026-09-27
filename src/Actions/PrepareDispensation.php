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
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Sales\DispensedSale;
use Keneya\Pharmacie\Services\NumberGenerator;
use Keneya\Pharmacie\Services\Reservations;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Controlled;
use Keneya\Pharmacie\Support\Facility;
use Keneya\Pharmacie\Support\Money;
use Keneya\Pharmacie\Support\Text;

/**
 * Préparer : décider ce qui sera servi, le chiffrer, l'envoyer à la caisse.
 *
 * Dans cet établissement, le patient règle avant d'être servi. La préparation
 * est donc l'objet qui vit entre le comptoir et la caisse : une dispensation
 * écrite et facturée, dont **rien n'est encore sorti du stock**.
 *
 * Ce qu'elle fait, et ce qu'elle se refuse à faire :
 *
 *   - elle **ne bouge pas le stock** : aucune écriture au grand livre. Le
 *     produit sort à la délivrance, pas avant, et c'est ce qui permet de
 *     servir quelqu'un d'autre entre-temps si la préparation est abandonnée ;
 *   - elle **ne bloque rien non plus**, sauf prise en charge. Un dossier
 *     d'assurance ou d'aide sociale demande du temps, et on ne peut pas faire
 *     attendre quelqu'un pour lui annoncer ensuite que le dernier flacon est
 *     parti. Les unités réservées restent physiquement là, simplement retirées
 *     du disponible ;
 *   - elle **ne calcule aucune part** : elle transmet à la caisse le tiers
 *     payant et son taux, et c'est Finance qui découpe la facture ;
 *   - elle **vérifie déjà les règles de délivrance** : un produit sous
 *     surveillance sans ordonnance est refusé ici, pas une fois le patient
 *     revenu de la caisse.
 */
final class PrepareDispensation
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Reservations $reservations,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  list<array{product_id: int, quantity: int, prescribed_quantity?: int, posology?: ?string, location_id?: ?int, substituted_for_id?: ?int, substitution_reason?: ?string, comment?: ?string, unit_price?: ?int}>  $lines
     * @param  array{patient_id?: ?string, patient_name?: ?string, source?: string, queue_ref?: ?string, prescription_ref?: ?string, notes?: ?string, coverage?: ?array{insurer?: ?string, rate?: ?int, reference?: ?string}}  $details
     */
    public function handle(Location $location, array $lines, Authenticatable $preparer, array $details = []): Dispensation
    {
        if ($lines === []) {
            throw new PharmacieRuleViolation('Une préparation doit porter au moins une ligne.');
        }

        $this->assertNotAlreadyPrepared(Text::clean($details['queue_ref'] ?? null));

        $coverage = $this->coverage($details['coverage'] ?? null);

        $dispensation = DB::transaction(function () use ($location, $lines, $preparer, $details, $coverage): Dispensation {
            $dispensation = Dispensation::create([
                'facility_id' => Facility::current(),
                'number' => $this->numbers->next('dispensation'),
                'patient_id' => Text::clean($details['patient_id'] ?? null),
                'patient_name' => Text::clean($details['patient_name'] ?? null),
                'source' => $details['source'] ?? Dispensation::SOURCE_COUNTER,
                'queue_ref' => Text::clean($details['queue_ref'] ?? null),
                'prescription_ref' => Text::clean($details['prescription_ref'] ?? null),
                'location_id' => $location->id,
                'status' => Dispensation::STATUS_DRAFT,
                'payment_status' => Dispensation::PAYMENT_DUE,
                'notes' => Text::clean($details['notes'] ?? null),
                'prepared_by_id' => Actor::id($preparer),
                'prepared_by_name' => Actor::name($preparer),
                'prepared_at' => now(),
                'coverage_insurer' => $coverage['insurer'] ?? null,
                'coverage_rate' => $coverage['rate'] ?? null,
                'coverage_reference' => $coverage['reference'] ?? null,
            ]);

            $total = 0;
            $prescribed = 0;

            foreach ($lines as $index => $line) {
                $item = $this->prepareLine($dispensation, $line, $preparer, $index + 1);

                $total += (int) $item->amount;
                $prescribed += (int) $item->prescribed_quantity;
            }

            // Le reliquat n'a pas de sens tant que rien n'est sorti : tout
            // reste à servir.
            $dispensation->update(['total' => $total, 'outstanding' => $prescribed]);

            if ($coverage !== null) {
                $this->reservations->hold($dispensation->refresh(), $location);
            }

            return $dispensation->refresh();
        });

        // Hors transaction : la caisse est un autre système, et une
        // préparation écrite ne doit pas être perdue parce qu'elle tarde à
        // répondre. Sans référence, l'écran dira que la facture n'est pas
        // partie, et on pourra la renvoyer.
        $this->sendToCashier($dispensation, $preparer);

        return $dispensation->refresh();
    }

    /**
     * L'emplacement d'où cette ligne sera prise.
     *
     * Tout n'est pas au comptoir : un vaccin dort dans la chaîne du froid, et
     * ce qui n'est pas descendu attend à la centrale. Plutôt que d'annoncer
     * une rupture ou de faire recommencer la dispensation ailleurs, la ligne
     * dit d'où elle vient.
     *
     * @param  array<string, mixed>  $line
     */
    private function lineLocation(array $line, Dispensation $dispensation, int $position): ?Location
    {
        $id = $line['location_id'] ?? null;

        if ($id === null || (int) $id === (int) $dispensation->location_id) {
            return null;
        }

        $location = Location::query()->ofFacility()->active()->whereKey((int) $id)->first();

        if ($location === null) {
            throw new PharmacieRuleViolation("Ligne {$position} : cet emplacement n'existe pas, ou n'est plus actif.");
        }

        return $location;
    }

    /**
     * Une préparation par passage, et une seule.
     *
     * Le patient qui revient de la caisse réapparaît dans la file comme un
     * arrivant. En préparer une seconde le renverrait payer, et ainsi de
     * suite : il faut délivrer celle qui l'attend, ou l'abandonner.
     */
    private function assertNotAlreadyPrepared(?string $queueRef): void
    {
        if ($queueRef === null) {
            return;
        }

        $pending = Dispensation::query()->ofFacility()
            ->where('queue_ref', $queueRef)
            ->where('status', Dispensation::STATUS_DRAFT)
            ->value('number');

        if ($pending !== null) {
            throw new PharmacieRuleViolation(
                "La préparation {$pending} attend déjà ce patient : délivrez-la, ou abandonnez-la avant d'en écrire une autre."
            );
        }
    }

    /**
     * Abandonner : ce qui était réservé redevient disponible, et la
     * préparation reste, marquée annulée.
     *
     * Rien n'a bougé au grand livre, il n'y a donc rien à contre-passer : une
     * préparation abandonnée n'est pas une dispensation annulée, c'est une
     * vente qui n'a pas eu lieu.
     */
    public function abandon(Dispensation $dispensation, string $reason, Authenticatable $actor): Dispensation
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new PharmacieRuleViolation('Abandonner une préparation demande un motif : le stock réservé revient aux autres patients.');
        }

        return DB::transaction(function () use ($dispensation, $reason, $actor): Dispensation {
            $fresh = Dispensation::query()->whereKey($dispensation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->status !== Dispensation::STATUS_DRAFT) {
                throw new PharmacieRuleViolation(
                    "La dispensation {$fresh->number} n'est plus une préparation : {$fresh->statusLabel()}."
                );
            }

            $released = $this->reservations->release($fresh);

            $fresh->update([
                'status' => Dispensation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_name' => Actor::name($actor),
                'cancellation_reason' => $reason,
            ]);

            $this->auditor->record(
                'preparation_abandoned',
                $fresh,
                sprintf(
                    'Préparation %s abandonnée : %s%s',
                    $fresh->number,
                    $reason,
                    $released > 0 ? sprintf(', %d unité(s) rendues au stock disponible', $released) : '',
                ),
                ['status' => Dispensation::STATUS_DRAFT],
                ['status' => Dispensation::STATUS_CANCELLED, 'released' => $released],
                $actor,
            );

            return $fresh;
        });
    }

    /**
     * Renvoyer la facture quand la caisse n'a pas répondu la première fois.
     */
    public function sendToCashier(Dispensation $dispensation, Authenticatable $actor): ?string
    {
        if ($dispensation->billing_reference !== null) {
            return $dispensation->billing_reference;
        }

        $reference = Pharmacie::sales()->send(new DispensedSale(
            reference: (string) $dispensation->number,
            patientId: (string) ($dispensation->patient_id ?? ''),
            patientName: $dispensation->patient_name,
            lines: $dispensation->items->map(static fn (DispensationItem $item): array => [
                'label' => (string) $item->label,
                'quantity' => (int) $item->prescribed_quantity,
                'unit_price' => (int) $item->unit_price,
                'amount' => (int) $item->amount,
            ])->all(),
            total: (int) $dispensation->total,
            queueRef: $dispensation->queue_ref,
            coverage: $dispensation->coverage_insurer === null ? null : [
                'insurer' => $dispensation->coverage_insurer,
                'rate' => $dispensation->coverage_rate === null ? null : (int) $dispensation->coverage_rate,
                'reference' => $dispensation->coverage_reference,
            ],
        ));

        $dispensation->update([
            'billing_reference' => $reference,
            'payment_status' => $reference === null ? Dispensation::PAYMENT_DUE : Dispensation::PAYMENT_SENT,
            'billed_at' => $reference === null ? null : now(),
        ]);

        $this->auditor->record(
            'preparation_prepared',
            $dispensation,
            sprintf(
                'Préparation %s pour %s : %d ligne(s), %s%s',
                $dispensation->number,
                $dispensation->patient_name ?? $dispensation->patient_id ?? 'patient non désigné',
                $dispensation->items->count(),
                Money::format((int) $dispensation->total),
                $reference === null ? ', caisse injoignable' : ', pièce '.$reference,
            ),
            [],
            [
                'patient_id' => $dispensation->patient_id,
                'total' => (int) $dispensation->total,
                'billing_reference' => $reference,
                'coverage' => $dispensation->coverage_insurer,
            ],
            $actor,
        );

        return $reference;
    }

    /**
     * @param  array{product_id: int, quantity: int, prescribed_quantity?: int, posology?: ?string, substituted_for_id?: ?int, substitution_reason?: ?string, comment?: ?string, unit_price?: ?int}  $line
     */
    private function prepareLine(Dispensation $dispensation, array $line, Authenticatable $preparer, int $position): DispensationItem
    {
        $product = Product::query()->find($line['product_id'] ?? null);

        if ($product === null) {
            throw new PharmacieRuleViolation("Ligne {$position} : produit introuvable.");
        }

        $wanted = (int) ($line['quantity'] ?? 0);
        $prescribed = (int) ($line['prescribed_quantity'] ?? $wanted);

        if ($wanted <= 0) {
            throw new PharmacieRuleViolation("Ligne {$position} ({$product->name}) : la quantité doit être supérieure à zéro.");
        }

        // Les règles du contrôle renforcé s'appliquent dès la préparation :
        // un stupéfiant sans ordonnance se refuse au comptoir, pas une fois
        // le patient revenu de la caisse avec son reçu.
        Controlled::assertDispensable($product, [
            'patient_id' => $dispensation->patient_id,
            'patient_name' => $dispensation->patient_name,
            'prescription_ref' => $dispensation->prescription_ref,
        ], $preparer, $position);

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

        // `quantity` reste à zéro : rien n'est servi. Ce qui est facturé est
        // ce qui a été demandé, et la délivrance dira ce qui est sorti.
        return DispensationItem::create([
            'dispensation_id' => $dispensation->id,
            'product_id' => $product->id,
            // Nul quand la ligne vient du comptoir : elle suit alors
            // l'emplacement de la dispensation, et rien n'est à retenir.
            'location_id' => $this->lineLocation($line, $dispensation, $position)?->id,
            'label' => $product->label(),
            'posology' => Text::clean($line['posology'] ?? null),
            'prescribed_quantity' => max($prescribed, $wanted),
            'quantity' => 0,
            'unit_price' => $unitPrice,
            'amount' => $wanted * $unitPrice,
            'substituted_for_id' => $substitutedFor?->id,
            'substitution_reason' => Text::clean($line['substitution_reason'] ?? null),
            'comment' => Text::clean($line['comment'] ?? null),
        ]);
    }

    /**
     * Ce que le comptoir a constaté du tiers payant.
     *
     * La pharmacie ne vérifie pas les droits et ne calcule aucune part : elle
     * note qui prend en charge, à quel taux, sous quelle référence, et le
     * transmet. Un taux hors de 1 à 100 n'a pas de sens : au-delà, ce n'est
     * plus une prise en charge partielle, et en deçà ce n'est rien.
     *
     * @param  array{insurer?: ?string, rate?: ?int, reference?: ?string}|null  $coverage
     * @return array{insurer: string, rate: ?int, reference: ?string}|null
     */
    private function coverage(?array $coverage): ?array
    {
        $insurer = Text::clean($coverage['insurer'] ?? null);

        if ($insurer === null) {
            return null;
        }

        $rate = isset($coverage['rate']) && $coverage['rate'] !== null ? (int) $coverage['rate'] : null;

        if ($rate !== null && ($rate < 1 || $rate > 100)) {
            throw new PharmacieRuleViolation('La part prise en charge se dit en pourcentage, entre 1 et 100.');
        }

        return [
            'insurer' => $insurer,
            'rate' => $rate,
            'reference' => Text::clean($coverage['reference'] ?? null),
        ];
    }
}
