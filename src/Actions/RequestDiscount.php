<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Discount;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Demander une remise sur une facture.
 *
 * Demander n'accorde rien : la remise attend l'approbation de quelqu'un
 * d'autre (voir DecideDiscount). On ne demande jamais plus que ce que le
 * patient doit encore, remises déjà demandées comprises.
 */
final class RequestDiscount
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
    ) {}

    public function handle(Invoice $invoice, int $amount, string $reason, Authenticatable $requester): Discount
    {
        if ($amount <= 0) {
            throw new FinanceRuleViolation('Le montant de la remise doit être supérieur à zéro.');
        }

        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new FinanceRuleViolation('Une remise doit avoir un motif : elle se justifie devant le contrôle.');
        }

        return DB::transaction(function () use ($invoice, $amount, $reason, $requester): Discount {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->isClosed()) {
                throw new FinanceRuleViolation("La facture {$invoice->number} est close : elle n'accepte plus de remise.");
            }

            $room = $invoice->balance() - $this->pending($invoice);

            if ($amount > $room) {
                throw new FinanceRuleViolation(sprintf(
                    'Le patient ne doit plus que %s sur la facture %s : une remise de %s dépasse ce qu\'il reste.',
                    Money::format(max(0, $room)),
                    $invoice->number,
                    Money::format($amount),
                ));
            }

            $discount = Discount::create([
                'number' => $this->numbers->next('credit_note'),
                'invoice_id' => $invoice->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => Discount::STATUS_REQUESTED,
                'requested_by_id' => Actor::id($requester),
                'requested_by_name' => Actor::name($requester),
            ]);

            $this->auditor->record(
                'discount_requested',
                $discount,
                sprintf('Remise %s demandée sur la facture %s : %s (%s)', $discount->number, $invoice->number, Money::format($amount), $reason),
                [],
                ['invoice' => $invoice->number, 'amount' => $amount, 'reason' => $reason],
                $requester,
            );

            return $discount;
        });
    }

    /**
     * Les remises déjà demandées et pas encore tranchées : elles retiennent
     * leur montant, sans quoi deux demandes successives accorderaient deux
     * fois la même somme.
     */
    private function pending(Invoice $invoice): int
    {
        return (int) Discount::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', Discount::STATUS_REQUESTED)
            ->sum('amount');
    }
}
