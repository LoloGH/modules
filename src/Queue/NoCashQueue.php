<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Queue;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\FinanceCaisse\Contracts\CashQueueProvider;

/**
 * Aucune file : le module seul, sans hôte pour lui fournir des visites.
 * L'écran de file le dit au lieu d'afficher une liste inventée.
 */
final class NoCashQueue implements CashQueueProvider
{
    public function queues(): array
    {
        return [];
    }

    public function pendingVisits(string $queueRef): array
    {
        return [];
    }

    public function callNext(string $queueRef, Authenticatable $cashier): ?QueuedVisit
    {
        return null;
    }

    public function findVisit(string $queueRef, string $visitRef): ?QueuedVisit
    {
        return null;
    }
}
