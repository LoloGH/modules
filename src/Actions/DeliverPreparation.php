<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\Pharmacie\Audit\Auditor;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationItem;
use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Sales\SaleStatus;
use Keneya\Pharmacie\Services\LineDispenser;
use Keneya\Pharmacie\Services\Reservations;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Controlled;
use Keneya\Pharmacie\Support\Money;
use Keneya\Pharmacie\Support\Text;

/**
 * Délivrer une préparation réglée : le moment où le produit part enfin.
 *
 * Le patient est passé en caisse, il revient au comptoir. C'est ici, et
 * seulement ici, que le stock sort, que le reliquat se calcule et que
 * l'ordonnance remonte au dossier médical.
 *
 * Trois choses méritent d'être dites, parce qu'elles ne vont pas de soi :
 *
 *   - **la porte n'est pas « facture payée », c'est « part patient soldée ».**
 *     Une facture peut rester partielle des semaines parce qu'un assureur n'a
 *     pas versé sa part : ce n'est pas au patient d'attendre ses médicaments
 *     pendant ce temps, et c'est à la caisse de poursuivre sa créance. Une
 *     prise en charge à 100 % passe donc d'emblée, sans qu'on invente un
 *     encaissement de zéro franc ;
 *   - **la pharmacie ne décide jamais qu'une facture est réglée.** Elle
 *     demande à l'hôte, qui interroge sa caisse. Sans caisse branchée, elle
 *     refuse de délivrer plutôt que de supposer : servir sans que personne
 *     n'ait encaissé serait la pire des erreurs ;
 *   - **les lots se choisissent maintenant, pas à la préparation.** Le stock
 *     a pu bouger depuis, et c'est l'état du jour qui fait foi. Ce qui manque
 *     devient un reliquat, comme dans une vente immédiate.
 */
final class DeliverPreparation
{
    public function __construct(
        private readonly LineDispenser $lines,
        private readonly Reservations $reservations,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array<int, array{batch_id?: ?int, override_reason?: ?string}>  $overrides  par identifiant de ligne
     */
    public function handle(Dispensation $dispensation, Authenticatable $dispenser, array $overrides = []): Dispensation
    {
        $status = $this->assertPayable($dispensation);

        return DB::transaction(function () use ($dispensation, $dispenser, $overrides, $status): Dispensation {
            $fresh = Dispensation::query()->whereKey($dispensation->getKey())->lockForUpdate()->firstOrFail();

            if (! $fresh->isPreparation()) {
                throw new PharmacieRuleViolation(
                    "La dispensation {$fresh->number} n'est plus une préparation : {$fresh->statusLabel()}."
                );
            }

            // Ce qui était mis de côté sort maintenant pour de bon : la
            // réservation doit disparaître avant, sinon le grand livre
            // refuserait de servir des unités qu'il croit promises.
            $this->reservations->release($fresh);

            $location = $fresh->location;
            $served = 0;
            $outstanding = 0;
            $total = 0;

            foreach ($fresh->items()->with(['product', 'location'])->get() as $position => $item) {
                $this->assertStillDispensable($fresh, $item, $dispenser, $position + 1);

                $quantity = $this->lines->serve(
                    $fresh,
                    $item,
                    // Chaque ligne sort de là où elle a été prise : le grand
                    // livre dit d'où le stock est parti, pas d'où on aurait
                    // voulu qu'il parte.
                    $item->servingLocation($location),
                    (int) $item->prescribed_quantity,
                    $overrides[$item->id] ?? [],
                    $dispenser,
                    $position + 1,
                );

                $served += $quantity;
                $outstanding += max(0, (int) $item->prescribed_quantity - $quantity);
                $total += $quantity * (int) $item->unit_price;
            }

            if ($served <= 0) {
                throw new PharmacieRuleViolation(
                    'Rien n\'a pu être délivré : aucun lot disponible pour les produits préparés. La préparation reste en attente.'
                );
            }

            $fresh->update([
                'status' => Dispensation::STATUS_DISPENSED,
                'payment_status' => Dispensation::PAYMENT_SETTLED,
                'outstanding' => $outstanding,
                'dispensed_by_id' => Actor::id($dispenser),
                'dispensed_by_name' => Actor::name($dispenser),
                'dispensed_at' => now(),
            ]);

            $this->auditor->record(
                'preparation_delivered',
                $fresh,
                sprintf(
                    'Préparation %s délivrée à %s : %d unité(s), %s%s',
                    $fresh->number,
                    $fresh->patient_name ?? $fresh->patient_id ?? 'patient non désigné',
                    $served,
                    Money::format($total),
                    $outstanding > 0 ? sprintf(', reliquat de %d unité(s)', $outstanding) : '',
                ),
                ['status' => Dispensation::STATUS_DRAFT],
                [
                    'status' => Dispensation::STATUS_DISPENSED,
                    'served' => $served,
                    'outstanding' => $outstanding,
                    'billing_reference' => $fresh->billing_reference,
                    'patient_paid' => $status->patientPaid,
                ],
                $dispenser,
            );

            return $fresh->refresh();
        });
    }

    /**
     * Ce que la caisse dit de cette préparation, et le refus si elle n'est
     * pas réglée.
     *
     * Le montant facturé ne change pas ici : ce qui est facturé est ce qui a
     * été préparé. Si un lot manque au moment de servir, le reliquat reste
     * dû au patient, et c'est la caisse qui saura le rembourser ou le porter
     * au prochain passage.
     */
    private function assertPayable(Dispensation $dispensation): SaleStatus
    {
        if (! $dispensation->isPreparation()) {
            throw new PharmacieRuleViolation(
                "La dispensation {$dispensation->number} n'est plus une préparation : {$dispensation->statusLabel()}."
            );
        }

        $reference = Text::clean($dispensation->billing_reference);

        if ($reference === null) {
            throw new PharmacieRuleViolation(
                "La préparation {$dispensation->number} n'a pas de pièce en caisse : renvoyez-la avant de délivrer."
            );
        }

        $status = Pharmacie::saleStatus()->status($reference);

        if ($status === null) {
            throw new PharmacieRuleViolation(
                "La caisse ne connaît pas la pièce {$reference} : impossible de savoir si elle est réglée."
            );
        }

        if ($status->cancelled) {
            throw new PharmacieRuleViolation(
                "La pièce {$reference} a été annulée en caisse : la préparation ne peut pas être délivrée."
            );
        }

        if (! $status->settledForPatient()) {
            throw new PharmacieRuleViolation(sprintf(
                'Il reste %s à régler en caisse sur la pièce %s : le patient est servi une fois sa part soldée.',
                Money::format($status->patientDue),
                $reference,
            ));
        }

        return $status;
    }

    /**
     * Les règles du contrôle renforcé se revérifient au comptoir.
     *
     * Ce n'est pas une redite : celui qui délivre n'est pas forcément celui
     * qui a préparé, et l'habilitation porte sur le geste de délivrer.
     */
    private function assertStillDispensable(Dispensation $dispensation, DispensationItem $item, Authenticatable $dispenser, int $position): void
    {
        if ($item->product === null) {
            throw new PharmacieRuleViolation("Ligne {$position} : produit introuvable.");
        }

        Controlled::assertDispensable($item->product, [
            'patient_id' => $dispensation->patient_id,
            'patient_name' => $dispensation->patient_name,
            'prescription_ref' => $dispensation->prescription_ref,
        ], $dispenser, $position);
    }
}
