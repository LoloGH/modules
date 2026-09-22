<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Refund;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Approuver ou refuser un remboursement.
 *
 * Comme pour la remise, celui qui a demandé ne tranche pas. Approuvé, le
 * remboursement attend d'être payé à la caisse ; refusé, il ne sort aucun
 * argent et son motif reste écrit.
 */
final class DecideRefund
{
    public function __construct(private readonly Auditor $auditor) {}

    public function approve(Refund $refund, Authenticatable $actor): Refund
    {
        return DB::transaction(function () use ($refund, $actor): Refund {
            $refund = $this->pending($refund, $actor);

            $refund->update([
                'status' => Refund::STATUS_APPROVED,
                'decided_at' => now(),
                'decided_by_id' => Actor::id($actor),
                'decided_by_name' => Actor::name($actor),
            ]);

            $this->auditor->record(
                'refund_approved',
                $refund,
                sprintf('Remboursement %s approuvé : %s', $refund->number, Money::format((int) $refund->amount)),
                ['status' => Refund::STATUS_REQUESTED],
                ['status' => Refund::STATUS_APPROVED, 'amount' => (int) $refund->amount],
                $actor,
            );

            return $refund;
        });
    }

    public function refuse(Refund $refund, string $reason, Authenticatable $actor): Refund
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new FinanceRuleViolation('Un refus doit avoir un motif : celui qui a demandé doit savoir pourquoi.');
        }

        return DB::transaction(function () use ($refund, $reason, $actor): Refund {
            $refund = $this->pending($refund, $actor);

            $refund->update([
                'status' => Refund::STATUS_REFUSED,
                'decided_at' => now(),
                'decided_by_id' => Actor::id($actor),
                'decided_by_name' => Actor::name($actor),
                'decision_reason' => $reason,
            ]);

            $this->auditor->record(
                'refund_refused',
                $refund,
                sprintf('Remboursement %s refusé : %s', $refund->number, $reason),
                ['status' => Refund::STATUS_REQUESTED],
                ['status' => Refund::STATUS_REFUSED, 'reason' => $reason],
                $actor,
            );

            return $refund;
        });
    }

    private function pending(Refund $refund, Authenticatable $actor): Refund
    {
        $fresh = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

        if (! $fresh->isPending()) {
            throw new FinanceRuleViolation("Le remboursement {$fresh->number} est déjà tranché : {$fresh->statusLabel()}.");
        }

        if ((string) $fresh->requested_by_id === Actor::id($actor)) {
            throw new FinanceRuleViolation('On n\'approuve pas le remboursement qu\'on a demandé : il attend quelqu\'un d\'autre.');
        }

        return $fresh;
    }
}
