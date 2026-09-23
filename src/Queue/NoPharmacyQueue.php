<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Queue;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;

/**
 * La file par défaut : vide.
 *
 * Tant qu'aucun hôte ne fournit sa file, la pharmacie n'en invente pas. Les
 * écrans le disent — « l'application hôte ne fournit pas encore de file » —
 * plutôt que d'afficher des patients qui n'existent pas.
 */
final class NoPharmacyQueue implements PharmacyQueueProvider
{
    public function queues(): array
    {
        return [];
    }

    public function pendingPatients(string $queueRef): array
    {
        return [];
    }

    public function callNext(string $queueRef, Authenticatable $preparer): ?QueuedPatient
    {
        return null;
    }

    public function findPatient(string $queueRef, string $patientRef): ?QueuedPatient
    {
        return null;
    }
}
