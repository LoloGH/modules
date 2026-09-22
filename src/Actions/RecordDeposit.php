<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\PatientDeposit;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Enregistrer une avance : le patient dépose de l'argent d'avance.
 *
 * L'argent entre dans le tiroir comme un encaissement, mais ce n'est pas une
 * recette : il reste dû au patient tant qu'il n'a rien payé. Ce que l'avance
 * paiera ensuite sera un encaissement ordinaire, réglé par « Compte patient ».
 */
final class RecordDeposit
{
    use GuardsCashSession;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    /**
     * @param  array{reference?: ?string, patient_name?: ?string, note?: ?string}  $details
     */
    public function handle(
        CashSession $session,
        PaymentMethod $method,
        int $amount,
        string $patientId,
        Authenticatable $cashier,
        array $details = [],
    ): PatientDeposit {
        if ($amount <= 0) {
            throw new FinanceRuleViolation('Le montant doit être supérieur à zéro.');
        }

        $patientId = Text::clean($patientId);

        if ($patientId === null) {
            throw new FinanceRuleViolation('Une avance appartient à un patient : son identifiant est obligatoire.');
        }

        return DB::transaction(function () use ($session, $method, $amount, $patientId, $cashier, $details): PatientDeposit {
            $session = $this->lockSession($session);
            $this->assertOpen($session);
            $this->assertOwnedBy($session, $cashier);

            $method = PaymentMethod::query()->findOrFail($method->getKey());

            if (! $method->is_active) {
                throw new FinanceRuleViolation("Le moyen de paiement « {$method->name} » est désactivé.");
            }

            // Une avance apporte de l'argent : on ne la verse ni depuis le
            // compte du patient lui-même, ni au titre d'une assurance.
            if (in_array($method->kind, [PaymentMethod::KIND_PATIENT_ACCOUNT, PaymentMethod::KIND_INSURANCE], true)) {
                throw new FinanceRuleViolation("« {$method->name} » ne peut pas servir à verser une avance.");
            }

            $reference = Text::clean($details['reference'] ?? null);

            if ($method->requires_reference && $reference === null) {
                throw new FinanceRuleViolation("Le moyen de paiement « {$method->name} » exige une référence.");
            }

            $deposit = PatientDeposit::create([
                'number' => $this->numbers->next('deposit'),
                'cash_session_id' => $session->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'patient_id' => $patientId,
                'patient_name' => Text::clean($details['patient_name'] ?? null),
                'reference' => $reference,
                'note' => Text::clean($details['note'] ?? null),
                'status' => PatientDeposit::STATUS_VALID,
            ]);

            $this->auditor->record(
                'deposit_recorded',
                $deposit,
                sprintf(
                    'Avance %s : %s (%s) pour %s',
                    $deposit->number,
                    Money::format($amount),
                    $method->name,
                    $deposit->patient_name ?? $patientId,
                ),
                [],
                ['amount' => $amount, 'method' => $method->code, 'patient_id' => $patientId, 'cash_session' => $session->number],
                $cashier,
            );

            return $deposit;
        });
    }
}
