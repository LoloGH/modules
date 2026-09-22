<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\InsuranceRejection;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Enregistre un rejet de l'assureur : le montant refusé retombe à la charge
 * du patient, et la facture redevient due d'autant.
 */
final class RecordInsuranceRejection
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(Invoice $invoice, int $amount, string $reason, Authenticatable $actor): InsuranceRejection
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new FinanceRuleViolation('Un rejet doit avoir un motif.');
        }

        if ($amount <= 0) {
            throw new FinanceRuleViolation('Le montant doit être supérieur à zéro.');
        }

        return DB::transaction(function () use ($invoice, $amount, $reason, $actor): InsuranceRejection {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            InsuranceGuards::assertInsuredAndOpen($invoice);

            if ($amount > $invoice->insurerOutstanding()) {
                throw new FinanceRuleViolation(sprintf(
                    "On ne peut rejeter que ce que l'assureur doit encore : %s sur la facture %s.",
                    Money::format($invoice->insurerOutstanding()),
                    $invoice->number,
                ));
            }

            $rejection = InsuranceRejection::create([
                'invoice_id' => $invoice->id,
                'insurer_id' => $invoice->insurer_id,
                'amount' => $amount,
                'reason' => $reason,
                'recorded_by_id' => Actor::id($actor),
                'recorded_by_name' => Actor::name($actor),
            ]);

            // La créance de l'assureur baisse, la dette du patient monte.
            $invoice->recalculateClaim();
            $invoice->recalculate();

            $this->auditor->record(
                'insurance_rejection_recorded',
                $rejection,
                sprintf('Rejet de %s par %s sur la facture %s : %s', Money::format($amount), $invoice->insurer->name, $invoice->number, $reason),
                [],
                ['amount' => $amount, 'invoice' => $invoice->number, 'reason' => $reason],
                $actor,
            );

            return $rejection;
        });
    }
}
