<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Staff;

/**
 * Une personne déclarée par l'hôte : son identifiant (celui de
 * `Actor::id()`), son nom, et sa fonction lisible (« Pharmacien »,
 * « Préparateur », « Magasinier »…).
 */
final readonly class HostStaff
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $function = null,
    ) {}
}
