<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Queue;

/**
 * Une file d'attente de la pharmacie, telle que l'hôte la déclare : sa
 * référence opaque, son nom lisible, et le nombre de patients qui y
 * attendent.
 */
final readonly class PharmacyQueue
{
    public function __construct(
        public string $ref,
        public string $name,
        public int $waiting = 0,
        public ?string $hint = null,
    ) {}
}
