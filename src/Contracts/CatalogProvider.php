<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Contracts;

use Keneya\FinanceCaisse\Catalog\CatalogAct;

/**
 * Le catalogue des actes de Finance, tel que l'application hôte peut le lire.
 *
 * Finance est la source unique des actes et de leurs prix : l'hôte ne garde
 * pas sa propre liste, il interroge ce contrat (`Finance::catalog()`). Il ne
 * reçoit jamais de modèle Eloquent, seulement des {@see CatalogAct} en
 * lecture seule : le schéma de Finance peut évoluer sans casser l'hôte.
 *
 * Lecture seule : rien ici ne modifie le catalogue. Seuls les actes ACTIFS
 * sont visibles ; un acte désactivé n'existe plus pour l'hôte.
 */
interface CatalogProvider
{
    /**
     * Les actes actifs proposables pour un service de l'hôte : ceux qui lui
     * sont rattachés (`dme_service_id`), puis les actes génériques (sans
     * service), chaque groupe par nom. Sans service, les génériques seuls.
     *
     * @return list<CatalogAct>
     */
    public function actsForService(?int $hostServiceId): iterable;

    /**
     * Un acte actif par son identifiant, ou null s'il n'existe pas ou est
     * désactivé.
     */
    public function findAct(int $actId): ?CatalogAct;

    /**
     * Le montant (entier, FCFA) du tarif actif d'un acte actif pour un
     * contexte, ou null si aucun tarif n'est fixé.
     */
    public function activeTariffFor(int $actId, string $kind = 'standard'): ?int;

    /**
     * L'acte marqué « ticket de consultation », s'il y en a un et qu'il est
     * actif. Il n'y en a jamais plus d'un.
     */
    public function ticketAct(): ?CatalogAct;
}
