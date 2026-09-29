<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Queue;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\Pharmacie\Contracts\QueueDischarge;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;

/**
 * Sans hote branche : la pharmacie ne fait sortir personne d'une file
 * qu'elle ne tient pas.
 *
 * Le module reste utilisable (on sert au comptoir, sans file) et l'ecran
 * dit clairement que le parcours du patient appartient a l'application hote,
 * plutot que de proposer un bouton qui ne ferait rien.
 */
final class NoQueueDischarge implements QueueDischarge
{
    public function destinations(): array
    {
        return [];
    }

    public function close(string $queueRef, string $patientRef, ?string $reason, Authenticatable $actor): void
    {
        throw new PharmacieRuleViolation(
            "Le parcours du patient appartient à l'application hôte : la pharmacie ne peut pas clore son passage."
        );
    }

    public function refer(string $queueRef, string $patientRef, string $destinationRef, ?string $reason, Authenticatable $actor): void
    {
        throw new PharmacieRuleViolation(
            "Le parcours du patient appartient à l'application hôte : la pharmacie ne peut pas l'envoyer ailleurs."
        );
    }
}
