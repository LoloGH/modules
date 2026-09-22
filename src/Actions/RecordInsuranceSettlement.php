<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\InsuranceSettlement;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Enregistre un règlement reçu de l'assureur pour une facture. Jamais plus
 * que ce qu'il doit encore ; sous verrou, audité.
 */
final class RecordInsuranceSettlement
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    public function handle(Invoice $invoice, int $amount, ?string $reference, ?string $receivedOn, Authenticatable $actor): InsuranceSettlement
    {
        if ($amount <= 0) {
            throw new FinanceRuleViolation('Le montant doit être supérieur à zéro.');
        }

        $date = $this->date($receivedOn);

        return DB::transaction(function () use ($invoice, $amount, $reference, $date, $actor): InsuranceSettlement {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            InsuranceGuards::assertInsuredAndOpen($invoice);

            if ($amount > $invoice->insurerOutstanding()) {
                throw new FinanceRuleViolation(sprintf(
                    "L'assureur ne doit plus que %s sur la facture %s.",
                    Money::format($invoice->insurerOutstanding()),
                    $invoice->number,
                ));
            }

            $settlement = InsuranceSettlement::create([
                'number' => $this->numbers->next('insurance_settlement'),
                'invoice_id' => $invoice->id,
                'insurer_id' => $invoice->insurer_id,
                'amount' => $amount,
                'reference' => Text::clean($reference),
                'received_on' => $date,
                'recorded_by_id' => Actor::id($actor),
                'recorded_by_name' => Actor::name($actor),
            ]);

            $invoice->recalculateClaim();

            $this->auditor->record(
                'insurance_settlement_recorded',
                $settlement,
                sprintf('Règlement %s de %s reçu de %s pour la facture %s', $settlement->number, Money::format($amount), $invoice->insurer->name, $invoice->number),
                [],
                ['amount' => $amount, 'invoice' => $invoice->number, 'reference' => $settlement->reference],
                $actor,
            );

            return $settlement;
        });
    }

    private function date(?string $value): Carbon
    {
        $value = Text::clean($value);

        if ($value === null) {
            return Carbon::today();
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            throw new FinanceRuleViolation("La date « {$value} » n'est pas une date valide.");
        }
    }
}
