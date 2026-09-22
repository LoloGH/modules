<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Catalog;

/**
 * Un acte du catalogue, vu de l'application hôte.
 *
 * Objet de valeur en lecture seule : c'est la forme stable promise à l'hôte,
 * indépendante du modèle Eloquent et du schéma de `finance_acts`. Ajouter un
 * champ est permis ; en retirer ou en renommer un casse l'hôte.
 *
 * `activeAmount` est le tarif STANDARD actif (entier, FCFA), null s'il n'est
 * pas encore fixé. Pour un autre contexte : `CatalogProvider::activeTariffFor()`.
 */
final readonly class CatalogAct
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?int $hostServiceId,
        public ?int $analyticCenterId,
        public ?int $activeAmount,
    ) {}

    /**
     * Rattaché à aucun service : proposable partout.
     */
    public function isGeneric(): bool
    {
        return $this->hostServiceId === null;
    }
}
