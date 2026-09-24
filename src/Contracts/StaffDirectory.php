<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Contracts;

use Keneya\Pharmacie\Staff\HostStaff;

/**
 * L'annuaire du personnel de la pharmacie, tenu par l'application hôte.
 *
 * Le module ne possède pas la table des utilisateurs : sans l'hôte, il ne
 * connaît quelqu'un qu'après sa première dispensation. Pour qu'on puisse
 * régler d'avance qui a droit à quoi, l'hôte déclare ici le personnel à qui
 * il ouvre la pharmacie. Il IMPLÉMENTE ce contrat et le lie dans le
 * conteneur ; la pharmacie le lit par `Pharmacie::staff()`.
 *
 * L'identifiant est celui que le module copie dans ses écritures
 * (`Support\Actor::id()`) : un réglage fait d'avance s'applique donc dès la
 * première venue de la personne au comptoir.
 *
 * Sans hôte, `NoStaffDirectory` rend une liste vide, et l'écran le dit
 * plutôt que d'inventer du personnel.
 */
interface StaffDirectory
{
    /**
     * Le personnel de l'hôte qui entre dans la pharmacie, comptes actifs
     * seulement.
     *
     * @return list<HostStaff>
     */
    public function staff(): array;
}
