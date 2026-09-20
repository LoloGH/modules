<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
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
     * @param  array{reference?: ?string, patient_id?: string|int|null, patient_name?: ?string, description?: ?string, invoice_id?: ?int}  $details
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

            $payment = Payment::create([
                'number' => $this->numbers->next('payment'),
                'cash_session_id' => $session->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'reference' => $reference,
                'patient_id' => $patientId === null ? null : (string) $patientId,
                'patient_name' => Text::clean($details['patient_name'] ?? null),
                'description' => Text::clean($details['description'] ?? null),
                'invoice_id' => $details['invoice_id'] ?? null,
                'status' => Payment::STATUS_VALID,
            ]);

            $this->auditor->record(
                'payment_recorded',
                $payment,
                sprintf('Encaissement %s : %s (%s), session %s', $payment->number, Money::format($amount), $method->name, $session->number),
                [],
                ['amount' => $amount, 'method' => $method->code, 'cash_session' => $session->number],
                $cashier,
            );

            return $payment;
        });
    }
}
