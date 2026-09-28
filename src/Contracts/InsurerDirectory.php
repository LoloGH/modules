<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Contracts;

use Keneya\Pharmacie\Coverage\HostInsurer;
use Keneya\Pharmacie\Models\ProductCoverage;

/**
 * Les organismes qui prennent en charge, tenus par l'application hôte.
 *
 * Assurances et aides sociales ne sont pas une affaire de pharmacie : c'est
 * la caisse qui les connaît, qui suit leurs créances et qui sait lesquels
 * sont encore actifs. Le module ne duplique donc pas cette table — deux
 * listes d'assureurs finiraient par diverger, et c'est alors la facture
 * qu'on cesse de croire.
 *
 * Ce que la pharmacie possède, en revanche, c'est son catalogue : quel
 * produit un organisme couvre, et à quel taux, est une règle qui porte sur
 * des médicaments, pas sur des actes. Elle vit donc ici
 * ({@see ProductCoverage}), et cet annuaire sert à
 * la rattacher à un organisme que l'hôte nomme.
 *
 * Sans hôte, `NoInsurers` rend une liste vide, et l'écran le dit plutôt que
 * d'inventer des assureurs.
 */
interface InsurerDirectory
{
    /**
     * Les organismes actifs, du plus courant au moins courant, ou par nom.
     *
     * @return list<HostInsurer>
     */
    public function insurers(): array;
}
