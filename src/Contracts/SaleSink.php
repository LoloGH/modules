<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Contracts;

use Keneya\Pharmacie\Sales\DispensedSale;

/**
 * Où part ce qui doit être payé.
 *
 * La pharmacie sait ce qu'elle a délivré et ce que cela coûte ; elle ne
 * décide pas où l'argent est encaissé. Ce contrat est le seul point de sortie
 * prévu, et il reste volontairement neutre : on y branchera, le moment venu,
 * soit le module Finance (la caisse Pharmacie y existe déjà, avec ses
 * sessions, ses écarts et son audit), soit une caisse propre.
 *
 * Tant que rien n'est branché, `NoSaleSink` ne fait rien : le module se
 * développe et se teste seul, sans supposer la décision.
 *
 * Recommandation de conception : garder Finance comme source unique des
 * paiements. Deux caisses, ce sont deux vérités sur l'argent, deux clôtures
 * et deux journaux.
 */
interface SaleSink
{
    /**
     * Une dispensation prête à être payée. L'implémentation rend la
     * référence de la pièce créée chez elle (facture, ticket de caisse…),
     * ou null si elle n'en produit pas.
     */
    public function send(DispensedSale $sale): ?string;
}
