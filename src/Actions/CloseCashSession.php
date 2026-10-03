<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Clôture le tiroir : le caissier déclare les espèces comptées, le système
 * calcule le théorique et l'écart.
 *
 * écart = compté - théorique (négatif : manque, positif : excédent).
 * Un écart, quel que soit son sens, doit être justifié.
 *
 * **On clôture un tiroir, pas une caisse.** Des caisses ouvertes groupées
 * partagent un seul tiroir, donc un seul fonds et un seul tas d'espèces. Les
 * clôturer une par une demandait trois fois « comptez les espèces du tiroir »
 * pour un tiroir unique, chaque fois comparé à un théorique partiel : la
 * première portait le fonds, les autres partaient de zéro. Le caissier ne
 * pouvait pas répondre honnêtement, puisqu'on ne compte pas un tiers de
 * tiroir. Un comptage, donc, pour tout le tiroir ; les caisses du groupe se
 * ferment ensemble.
 *
 * Chaque session garde son propre théorique, qui dit ce qu'elle a apporté. Le
 * compté se répartit de sorte que la somme fasse exactement ce qui a été
 * compté : chaque caisse reçoit son théorique, et celle qui porte le fonds
 * reçoit l'écart. Ainsi un écart de tiroir ne se compte qu'une fois, et les
 * totaux de l'établissement restent justes. Les écrans, eux, présentent le
 * tiroir dans son ensemble : personne n'a à lire ce partage.
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
            $sessions = $session->drawerSessions(lock: true);

            foreach ($sessions as $ouverte) {
                $this->assertOpen($ouverte);
                $this->assertOwnedBy($ouverte, $cashier);
            }

            $totals = $sessions->mapWithKeys(fn (CashSession $item): array => [
                $item->getKey() => $this->calculator->totals($item),
            ]);

            $expected = (int) $totals->sum(fn (array $ligne): int => $ligne['expected_cash']);
            $variance = $countedCash - $expected;
            $reason = Text::clean($varianceReason);

            if ($variance !== 0 && $reason === null) {
                throw new FinanceRuleViolation(sprintf(
                    'Écart de %s entre le compté et le théorique : il doit être justifié.',
                    Money::format($variance),
                ));
            }

            $this->closeAll($sessions, $totals, $expected, $variance, $reason, $cashier, $countedCash);

            return $session->refresh();
        });
    }

    /**
     * @param  Collection<int, CashSession>  $sessions
     * @param  Collection<int, array<string, mixed>>  $totals
     */
    private function closeAll(
        Collection $sessions,
        Collection $totals,
        int $expected,
        int $variance,
        ?string $reason,
        Authenticatable $cashier,
        int $countedCash,
    ): void {
        $groupe = $sessions->count() > 1;

        foreach ($sessions as $index => $item) {
            $ligne = $totals[$item->getKey()];
            $porteur = $index === 0;
            $compte = $ligne['expected_cash'] + ($porteur ? $variance : 0);
            $ecart = $porteur ? $variance : 0;

            $item->update([
                'status' => CashSession::STATUS_CLOSED,
                'closed_at' => now(),
                'expected_cash' => $ligne['expected_cash'],
                'counted_cash' => $compte,
                'variance' => $ecart,
                'variance_reason' => $ecart === 0 ? null : $reason,
                'totals' => $ligne,
            ]);

            $this->auditor->record(
                'session_closed',
                $item,
                $groupe
                    ? sprintf(
                        'Session %s clôturée avec son tiroir (%d caisses) : tiroir compté %s, théorique %s, écart %s ; cette caisse y apporte %s',
                        $item->number,
                        $sessions->count(),
                        Money::format($countedCash),
                        Money::format($expected),
                        Money::format($variance),
                        Money::format($ligne['expected_cash']),
                    )
                    : sprintf(
                        'Session %s clôturée : théorique %s, compté %s, écart %s',
                        $item->number,
                        Money::format($ligne['expected_cash']),
                        Money::format($compte),
                        Money::format($ecart),
                    ),
                ['status' => CashSession::STATUS_OPEN],
                [
                    'status' => CashSession::STATUS_CLOSED,
                    'expected_cash' => $ligne['expected_cash'],
                    'counted_cash' => $compte,
                    'variance' => $ecart,
                    'variance_reason' => $ecart === 0 ? null : $reason,
                ],
                $cashier,
            );
        }
    }
}
