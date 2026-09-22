<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Queue;

/**
 * Une file de caisse de l'hôte, vue de Finance.
 */
final readonly class CashQueue
{
    public function __construct(
        public string $ref,
        public string $name,
    ) {}
}
