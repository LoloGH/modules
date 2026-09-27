<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Contracts;

use Keneya\Pharmacie\Sales\SaleStatus;

/**
 * Où en est ce qui a été envoyé à la caisse.
 *
 * {@see SaleSink} est la porte par laquelle la pharmacie dit ce qui est dû.
 * Celle-ci est la porte de retour, et elle ne sert qu'à lire : l'hôte
 * interroge sa caisse (le module Finance, ou autre chose) et rend l'état de
 * la pièce. La pharmacie s'en sert pour une seule décision, délivrer ou non.
 *
 * Deux principes la gouvernent :
 *
 *   - **la pharmacie ne décide jamais qu'une facture est réglée.** Elle ne
 *     sait pas encaisser, elle ne tient pas de tiroir, et deux vérités sur
 *     l'argent valent moins qu'une ;
 *   - **elle ne conserve pas cet état.** Le statut est relu à chaque
 *     affichage, donc il ne peut pas se périmer en base pendant qu'un
 *     caissier encaisse à l'autre bout du couloir.
 *
 * Sans hôte, `NoSaleStatus` ne sait rien et le dit : l'écran affiche que la
 * caisse n'est pas branchée, plutôt que de laisser croire à un impayé.
 */
interface SaleStatusProvider
{
    /**
     * L'état de la pièce portant cette référence, ou null si l'hôte ne la
     * connaît pas.
     */
    public function status(string $reference): ?SaleStatus;
}
