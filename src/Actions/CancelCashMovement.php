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
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Annule un encaissement ou un décaissement : il n'est jamais effacé, il
 * reste visible avec son auteur et son motif, et sort des totaux.
 *
 * Seule une session OUVERTE permet d'annuler : après la clôture, le montant
 * compté et l'écart sont figés, et une correction passe par un remboursement
 * ou un ajustement (tranches suivantes).
 */
final class CancelCashMovement
{
    use GuardsCashSession;

    public function __construct(private readonly Auditor $auditor) {}

    public function payment(Payment $payment, string $reason, Authenticatable $actor): Payment
    {
        return $this->cancel($payment, 'payment_cancelled', 'Encaissement', $reason, $actor);
    }

    public function disbursement(Disbursement $disbursement, string $reason, Authenticatable $actor): Disbursement
    {
        return $this->cancel($disbursement, 'disbursement_cancelled', 'Décaissement', $reason, $actor);
    }

    /**
     * @template T of Payment|Disbursement
     *
     * @param  T  $movement
     * @return T
     */
    private function cancel(Payment|Disbursement $movement, string $event, string $label, string $reason, Authenticatable $actor): Payment|Disbursement
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new FinanceRuleViolation("L'annulation doit avoir un motif.");
        }

        return DB::transaction(function () use ($movement, $event, $label, $reason, $actor) {
            $session = $this->lockSession(CashSession::query()->findOrFail($movement->cash_session_id));

            $fresh = $movement::query()->whereKey($movement->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isCancelled()) {
                throw new FinanceRuleViolation("{$label} {$fresh->number} : déjà annulé.");
            }

            if (! $session->isOpen()) {
                throw new FinanceRuleViolation(
                    "La session {$session->number} n'est plus ouverte : après la clôture, une correction passe par un remboursement ou un ajustement."
                );
            }

            $fresh->update([
                'status' => Payment::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_id' => Actor::id($actor),
                'cancelled_by_name' => Actor::name($actor),
                'cancellation_reason' => $reason,
            ]);

            $this->auditor->record(
                $event,
                $fresh,
                sprintf('%s %s annulé (%s) : %s', $label, $fresh->number, Money::format((int) $fresh->amount), $reason),
                ['status' => Payment::STATUS_VALID],
                ['status' => Payment::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );

            return $fresh;
        });
    }
}
