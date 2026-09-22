<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Contracts;

use Keneya\FinanceCaisse\Cashiers\HostCashier;

/**
 * L'annuaire des caissiers, tenu par l'application hôte.
 *
 * Le module ne possède pas la table des utilisateurs : sans l'hôte, il ne
 * connaît un caissier qu'après sa première session. Pour qu'on puisse régler
 * d'avance qui ouvre quelles caisses (et combien à la fois), l'hôte déclare
 * ici son personnel qui encaisse. Il IMPLÉMENTE ce contrat et le lie dans le
 * conteneur ; Finance le lit par `Finance::cashiers()`.
 *
 * L'identifiant d'un caissier est celui que Finance copie dans ses écritures
 * (`Support\Actor::id()` : l'identifiant d'authentification, en chaîne) : les
 * réglages faits d'avance s'appliquent ainsi dès sa première ouverture.
 */
interface CashierDirectory
{
    /**
     * Le personnel de l'hôte habilité à encaisser, comptes actifs seulement.
     *
     * @return list<HostCashier>
     */
    public function cashiers(): array;
}
