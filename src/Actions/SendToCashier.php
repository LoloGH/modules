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
use Keneya\Pharmacie\Sales\DispensedSale;
use Keneya\Pharmacie\Support\Money;
use Keneya\Pharmacie\Support\Text;

/**
 * Envoyer à la caisse ce qui doit être payé.
 *
 * La pharmacie ne tient pas de tiroir : elle dit ce qui est dû, et l'envoie
 * par le contrat de vente. Ce que la caisse en fait, un passage en file, une
 * facture, un ticket, ne la regarde pas.
 *
 * Quatre titres de facturation, et chacun a ses conséquences :
 *
 *   - **direct** : le patient paie au comptoir ;
 *   - **hospitalisation** : la dépense suit le séjour, elle n'est pas
 *     encaissée ici ;
 *   - **prise en charge** : un organisme paiera ;
 *   - **gratuité** : l'établissement ne réclame rien, et l'écrit.
 *
 * Une dispensation annulée ne s'envoie pas, et rien ne s'envoie deux fois :
 * c'est ce qui empêche un patient d'être facturé en double.
 */
final class SendToCashier
{
    public const KIND_DIRECT = 'direct';

    public const KIND_HOSPITALIZATION = 'hospitalisation';

    public const KIND_COVERAGE = 'prise_en_charge';

    public const KIND_FREE = 'gratuite';

    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            self::KIND_DIRECT => 'Paiement direct',
            self::KIND_HOSPITALIZATION => 'Porté au séjour',
            self::KIND_COVERAGE => 'Prise en charge',
            self::KIND_FREE => 'Gratuité',
        ];
    }

    public function handle(Dispensation $dispensation, string $kind, Authenticatable $actor, ?string $note = null): Dispensation
    {
        if (! array_key_exists($kind, self::kindLabels())) {
            throw new PharmacieRuleViolation('Titre de facturation inconnu.');
        }

        return DB::transaction(function () use ($dispensation, $kind, $actor, $note): Dispensation {
            $fresh = Dispensation::query()->whereKey($dispensation->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isCancelled()) {
                throw new PharmacieRuleViolation("La dispensation {$fresh->number} est annulée : il n'y a rien à faire payer.");
            }

            if (in_array($fresh->payment_status, [Dispensation::PAYMENT_SENT, Dispensation::PAYMENT_SETTLED], true)) {
                throw new PharmacieRuleViolation(
                    "La dispensation {$fresh->number} est déjà partie à la caisse : elle ne s'envoie pas deux fois."
                );
            }

            $note = Text::clean($note);

            // Gratuité : rien ne part à la caisse, mais la décision s'écrit.
            if ($kind === self::KIND_FREE) {
                if ($note === null) {
                    throw new PharmacieRuleViolation('Une gratuité se justifie : indiquez à quel titre.');
                }

                $fresh->update([
                    'billing_kind' => $kind,
                    'payment_status' => Dispensation::PAYMENT_FREE,
                    'billing_note' => $note,
                    'billed_at' => now(),
                ]);

                $this->audit($fresh, $kind, null, $actor);

                return $fresh;
            }

            $reference = Pharmacie::sales()->send(new DispensedSale(
                reference: (string) $fresh->number,
                patientId: (string) ($fresh->patient_id ?? ''),
                patientName: $fresh->patient_name,
                lines: $fresh->items->map(static fn (DispensationItem $item): array => [
                    'label' => (string) $item->label,
                    'quantity' => (int) $item->quantity,
                    'unit_price' => (int) $item->unit_price,
                    'amount' => (int) $item->amount,
                ])->all(),
                total: (int) $fresh->total,
                queueRef: $fresh->queue_ref,
            ));

            $fresh->update([
                'billing_kind' => $kind,
                'payment_status' => Dispensation::PAYMENT_SENT,
                'billing_reference' => $reference,
                'billing_note' => $note,
                'billed_at' => now(),
            ]);

            $this->audit($fresh, $kind, $reference, $actor);

            return $fresh;
        });
    }

    private function audit(Dispensation $dispensation, string $kind, ?string $reference, Authenticatable $actor): void
    {
        $this->auditor->record(
            'dispensation_billed',
            $dispensation,
            sprintf(
                'Dispensation %s : %s%s (%s)',
                $dispensation->number,
                self::kindLabels()[$kind],
                $reference === null ? '' : ', pièce '.$reference,
                Money::format((int) $dispensation->total),
            ),
            [],
            [
                'kind' => $kind,
                'reference' => $reference,
                'total' => (int) $dispensation->total,
                'note' => $dispensation->billing_note,
            ],
            $actor,
        );
    }
}
