<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Clôture la session : le caissier déclare les espèces comptées, le système
 * calcule le théorique et l'écart.
 *
 * écart = compté - théorique (négatif : manque, positif : excédent).
 * Un écart, quel que soit son sens, doit être justifié.
 */
final class CloseCashSession
{
    use GuardsCashSession;

    public function __construct(
        private readonly Auditor $auditor,
        private readonly CashSessionCalculator $calculator,
    ) {}

    public function handle(CashSession $session, int $countedCash, Authenticatable $cashier, ?string $varianceReason = null): CashSession
    {
        if ($countedCash < 0) {
            throw new FinanceRuleViolation('Le montant compté ne peut pas être négatif.');
        }

        return DB::transaction(function () use ($session, $countedCash, $cashier, $varianceReason): CashSession {
            $session = $this->lockSession($session);
            $this->assertOpen($session);
            $this->assertOwnedBy($session, $cashier);

            $totals = $this->calculator->totals($session);
            $variance = $countedCash - $totals['expected_cash'];
            $reason = Text::clean($varianceReason);

            if ($variance !== 0 && $reason === null) {
                throw new FinanceRuleViolation(sprintf(
                    'Écart de %s entre le compté et le théorique : il doit être justifié.',
                    Money::format($variance),
                ));
            }

            $session->update([
                'status' => CashSession::STATUS_CLOSED,
                'closed_at' => now(),
                'expected_cash' => $totals['expected_cash'],
                'counted_cash' => $countedCash,
                'variance' => $variance,
                'variance_reason' => $variance === 0 ? null : $reason,
                'totals' => $totals,
            ]);

            $this->auditor->record(
                'session_closed',
                $session,
                sprintf(
                    'Session %s clôturée : théorique %s, compté %s, écart %s',
                    $session->number,
                    Money::format($totals['expected_cash']),
                    Money::format($countedCash),
                    Money::format($variance),
                ),
                ['status' => CashSession::STATUS_OPEN],
                [
                    'status' => CashSession::STATUS_CLOSED,
                    'expected_cash' => $totals['expected_cash'],
                    'counted_cash' => $countedCash,
                    'variance' => $variance,
                    'variance_reason' => $variance === 0 ? null : $reason,
                ],
                $cashier,
            );

            return $session;
        });
    }
}
