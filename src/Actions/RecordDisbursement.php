<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Enregistre une sortie de caisse dans la session ouverte de ce caissier.
 *
 * Un motif est obligatoire. Une sortie en espèces ne peut pas dépasser ce que
 * le tiroir contient. Le compte patient et l'assurance ne sont pas des
 * moyens de sortie d'argent.
 */
final class RecordDisbursement
{
    use GuardsCashSession;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
        private readonly CashSessionCalculator $calculator,
    ) {}

    /**
     * @param  array{beneficiary?: ?string, reference?: ?string}  $details
     */
    public function handle(
        CashSession $session,
        PaymentMethod $method,
        int $amount,
        string $reason,
        Authenticatable $cashier,
        array $details = [],
    ): Disbursement {
        if ($amount <= 0) {
            throw new FinanceRuleViolation('Le montant doit être supérieur à zéro.');
        }

        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new FinanceRuleViolation('Un décaissement doit avoir un motif.');
        }

        return DB::transaction(function () use ($session, $method, $amount, $reason, $cashier, $details): Disbursement {
            $session = $this->lockSession($session);
            $this->assertOpen($session);
            $this->assertOwnedBy($session, $cashier);

            $method = PaymentMethod::query()->findOrFail($method->getKey());

            if (! $method->is_active) {
                throw new FinanceRuleViolation("Le moyen de paiement « {$method->name} » est désactivé.");
            }

            if (in_array($method->kind, [PaymentMethod::KIND_PATIENT_ACCOUNT, PaymentMethod::KIND_INSURANCE], true)) {
                throw new FinanceRuleViolation("« {$method->name} » ne peut pas servir à un décaissement.");
            }

            $reference = Text::clean($details['reference'] ?? null);

            if ($method->requires_reference && $reference === null) {
                throw new FinanceRuleViolation("Le moyen de paiement « {$method->name} » exige une référence.");
            }

            if ($method->isCash()) {
                $inDrawer = $this->calculator->totals($session)['expected_cash'];

                if ($amount > $inDrawer) {
                    throw new FinanceRuleViolation(sprintf(
                        'Le tiroir ne contient que %s en espèces : le décaissement de %s est impossible.',
                        Money::format($inDrawer),
                        Money::format($amount),
                    ));
                }
            }

            $disbursement = Disbursement::create([
                'number' => $this->numbers->next('disbursement'),
                'cash_session_id' => $session->id,
                'payment_method_id' => $method->id,
                'amount' => $amount,
                'reason' => $reason,
                'beneficiary' => Text::clean($details['beneficiary'] ?? null),
                'reference' => $reference,
                'status' => Disbursement::STATUS_VALID,
            ]);

            $this->auditor->record(
                'disbursement_recorded',
                $disbursement,
                sprintf('Décaissement %s : %s (%s), motif : %s', $disbursement->number, Money::format($amount), $method->name, $reason),
                [],
                ['amount' => $amount, 'method' => $method->code, 'cash_session' => $session->number, 'reason' => $reason],
                $cashier,
            );

            return $disbursement;
        });
    }
}
