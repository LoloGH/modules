<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Sales;

/**
 * Ce que la pharmacie a délivré et qui reste à payer : le patient, la pièce
 * de la pharmacie, les lignes et le total.
 *
 * Volontairement pauvre : ni lot, ni péremption, ni stock. Ce qui sort d'ici
 * va à la caisse, pas au dossier pharmaceutique.
 *
 * @param  list<array{label: string, quantity: int, unit_price: int, amount: int}>  $lines
 */
final readonly class DispensedSale
{
    /**
     * @param  list<array{label: string, quantity: int, unit_price: int, amount: int}>  $lines
     */
    public function __construct(
        public string $reference,
        public string $patientId,
        public ?string $patientName,
        public array $lines,
        public int $total,
        public ?string $queueRef = null,
    ) {}
}
