<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Coverage;

/**
 * Un organisme qui prend en charge, tel que l'hôte le nomme.
 *
 * Volontairement pauvre : une référence opaque, un nom lisible, et de quoi
 * distinguer une assurance d'une aide sociale : la seconde ne se négocie pas
 * comme la première, et le comptoir a besoin de le voir.
 *
 * `defaultRate` est le taux que l'organisme applique en général. Il ne
 * s'impose pas : c'est une proposition pour la fiche produit, où le taux réel
 * se déclare produit par produit.
 */
final readonly class HostInsurer
{
    public const KIND_INSURANCE = 'insurance';

    public const KIND_SOCIAL_AID = 'social_aid';

    public function __construct(
        public string $ref,
        public string $name,
        public string $kind = self::KIND_INSURANCE,
        public ?int $defaultRate = null,
    ) {}

    public function isSocialAid(): bool
    {
        return $this->kind === self::KIND_SOCIAL_AID;
    }

    public function kindLabel(): string
    {
        return $this->isSocialAid() ? 'Aide sociale' : 'Assurance';
    }
}
