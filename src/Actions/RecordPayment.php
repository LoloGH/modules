<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Enregistre un encaissement dans la session ouverte de ce caissier.
 */
final class RecordPayment
{
    use GuardsCashSession;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{reference?: ?string, patient_id?: string|int|null, patient_name?: ?string, description?: ?string, invoice_id?: ?int, act_id?: ?int, host_visit_ref?: ?string}  $details
     */
    public function handle(
        CashSession $session,
        PaymentMethod $method,
        int $amount,
        Authenticatable $cashier,
        array $details = [],
    ): Payment {
        if ($amount <= 0) {
            throw new FinanceRuleViolation('Le montant doit être supérieur à zéro.');
        }

        return DB::transaction(function () use ($session, $method, $amount, $cashier, $details): Payment {
            $session = $this->lockSession($session);
            $this->assertOpen($session);
            $this->assertOwnedBy($session, $cashier);

            $method = PaymentMethod::query()->findOrFail($method->getKey());

            if (! $method->is_active) {
                throw new FinanceRuleViolation("Le moyen de paiement « {$method->name} » est désactivé.");
            }

            $reference = Text::clean($details['reference'] ?? null);

            if ($method->requires_reference && $reference === null) {
                throw new FinanceRuleViolation("Le moyen de paiement « {$method->name} » exige une référence.");
            }

            $patientId = $details['patient_id'] ?? null;

            $act = $this->act($details['act_id'] ?? null);

            $payment = Payment::create([
                'number' => $this->numbers->next('payment'),
                'cash_session_id' => $session->id,
                'payment_method_id' => $method->id,
                'act_id' => $act?->id,
                'amount' => $amount,
                'reference' => $reference,
                'patient_id' => $patientId === null ? null : (string) $patientId,
                'patient_name' => Text::clean($details['patient_name'] ?? null),
                // Sans libellé saisi, le nom de l'acte fait office de motif :
                // une ligne de caisse ne doit jamais rester muette.
                'description' => Text::clean($details['description'] ?? null) ?? $act?->name,
                'invoice_id' => $details['invoice_id'] ?? null,
                // La visite de l'hôte réglée par cet encaissement, venu de la file.
                'host_visit_ref' => $details['host_visit_ref'] ?? null,
                'status' => Payment::STATUS_VALID,
            ]);

            $this->auditor->record(
                'payment_recorded',
                $payment,
                sprintf(
                    'Encaissement %s : %s (%s), session %s%s',
                    $payment->number,
                    Money::format($amount),
                    $method->name,
                    $session->number,
                    $act === null ? '' : ', acte '.$act->code,
                ),
                [],
                array_filter([
                    'amount' => $amount,
                    'method' => $method->code,
                    'cash_session' => $session->number,
                    'act' => $act?->code,
                ], static fn ($value): bool => $value !== null),
                $cashier,
            );

            return $payment;
        });
    }

    /**
     * L'acte encaissé, s'il y en a un. Un acte désactivé ne se facture plus :
     * il reste lisible sur les encaissements passés, mais on n'en crée pas de
     * nouveaux.
     */
    private function act(?int $actId): ?Act
    {
        if ($actId === null) {
            return null;
        }

        $act = Act::query()->find($actId);

        if ($act === null) {
            throw new FinanceRuleViolation("L'acte choisi n'existe pas.");
        }

        if (! $act->is_active) {
            throw new FinanceRuleViolation("L'acte « {$act->name} » est désactivé : il ne peut plus être encaissé.");
        }

        return $act;
    }
}
