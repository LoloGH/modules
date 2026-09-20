<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Support\Actor;

/**
 * Contrôles communs aux actions qui touchent une session de caisse.
 * À appeler DANS la transaction : la ligne est verrouillée pour que deux
 * opérations simultanées (un encaissement et la clôture, par exemple) ne
 * puissent pas se croiser.
 */
trait GuardsCashSession
{
    private function lockSession(CashSession $session): CashSession
    {
        return CashSession::query()->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
    }

    private function assertOpen(CashSession $session): void
    {
        if (! $session->isOpen()) {
            throw new FinanceRuleViolation("La session de caisse {$session->number} n'est pas ouverte.");
        }
    }

    private function assertOwnedBy(CashSession $session, Authenticatable $actor): void
    {
        if ((string) $session->cashier_id !== Actor::id($actor)) {
            throw new FinanceRuleViolation('Cette session de caisse appartient à un autre caissier.');
        }
    }
}
