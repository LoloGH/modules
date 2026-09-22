<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Cashiers;

/**
 * Un caissier déclaré par l'hôte : son identifiant (celui de `Actor::id()`),
 * son nom, et sa fonction lisible (« Caissier », « Agent de facturation »…).
 */
final readonly class HostCashier
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $function = null,
    ) {}
}
