<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Queue;

/**
 * Un service vers lequel la pharmacie peut renvoyer un patient servi, tel
 * que l'hôte le nomme.
 *
 * Volontairement pauvre : une référence opaque et un nom lisible. La
 * pharmacie ne sait pas ce qu'est un service de l'hôte, et n'a pas à le
 * savoir pour proposer une destination.
 */
final readonly class QueueDestination
{
    public function __construct(
        public string $ref,
        public string $name,
        public ?string $hint = null,
    ) {}
}
