<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Discount;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Approuver ou refuser une remise.
 *
 * Celui qui a demandé n'approuve pas : une remise accordée est de l'argent
 * que l'établissement abandonne, et deux personnes en répondent. Approuvée,
 * elle réduit ce que le patient doit ; refusée, elle ne change rien et son
 * motif reste écrit.
 */
final class DecideDiscount
{
    public function __construct(private readonly Auditor $auditor) {}

    public function approve(Discount $discount, Authenticatable $actor): Discount
    {
        return DB::transaction(function () use ($discount, $actor): Discount {
            $discount = $this->pending($discount, $actor);
            $invoice = Invoice::query()->whereKey($discount->invoice_id)->lockForUpdate()->firstOrFail();

            if ($invoice->isClosed()) {
                throw new FinanceRuleViolation("La facture {$invoice->number} est close : la remise ne s'y applique plus.");
            }

            if ((int) $discount->amount > $invoice->balance()) {
                throw new FinanceRuleViolation(sprintf(
                    'Le patient ne doit plus que %s sur la facture %s : la remise de %s ne s\'y applique plus.',
                    Money::format($invoice->balance()),
                    $invoice->number,
                    Money::format((int) $discount->amount),
                ));
            }

            $invoice->update(['discount' => (int) $invoice->discount + (int) $discount->amount]);
            $invoice->recalculate();

            $discount->update([
                'status' => Discount::STATUS_APPROVED,
                'decided_at' => now(),
                'decided_by_id' => Actor::id($actor),
                'decided_by_name' => Actor::name($actor),
            ]);

            $this->auditor->record(
                'discount_approved',
                $discount,
                sprintf('Remise %s approuvée sur la facture %s : %s', $discount->number, $invoice->number, Money::format((int) $discount->amount)),
                ['status' => Discount::STATUS_REQUESTED],
                ['status' => Discount::STATUS_APPROVED, 'invoice' => $invoice->number, 'amount' => (int) $discount->amount],
                $actor,
            );

            return $discount;
        });
    }

    public function refuse(Discount $discount, string $reason, Authenticatable $actor): Discount
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new FinanceRuleViolation('Un refus doit avoir un motif : celui qui a demandé doit savoir pourquoi.');
        }

        return DB::transaction(function () use ($discount, $reason, $actor): Discount {
            $discount = $this->pending($discount, $actor);

            $discount->update([
                'status' => Discount::STATUS_REFUSED,
                'decided_at' => now(),
                'decided_by_id' => Actor::id($actor),
                'decided_by_name' => Actor::name($actor),
                'decision_reason' => $reason,
            ]);

            $this->auditor->record(
                'discount_refused',
                $discount,
                sprintf('Remise %s refusée : %s', $discount->number, $reason),
                ['status' => Discount::STATUS_REQUESTED],
                ['status' => Discount::STATUS_REFUSED, 'reason' => $reason],
                $actor,
            );

            return $discount;
        });
    }

    /**
     * La remise, verrouillée, encore à trancher, et par quelqu'un d'autre que
     * son demandeur.
     */
    private function pending(Discount $discount, Authenticatable $actor): Discount
    {
        $fresh = Discount::query()->whereKey($discount->getKey())->lockForUpdate()->firstOrFail();

        if (! $fresh->isPending()) {
            throw new FinanceRuleViolation("La remise {$fresh->number} est déjà tranchée : {$fresh->statusLabel()}.");
        }

        if ((string) $fresh->requested_by_id === Actor::id($actor)) {
            throw new FinanceRuleViolation('On n\'approuve pas la remise qu\'on a demandée : elle attend quelqu\'un d\'autre.');
        }

        return $fresh;
    }
}
