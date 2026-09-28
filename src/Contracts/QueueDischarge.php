<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\Pharmacie\Queue\QueueDestination;

/**
 * La sortie de la file : ce qu'il advient d'un patient une fois servi.
 *
 * Sans ce contrat, un patient servi resterait dans la file de la pharmacie.
 * Revenu de la caisse, il y réapparaîtrait « en attente », le comptoir le
 * rappellerait, préparerait une seconde fois, renverrait une seconde facture
 * — une boucle dont personne ne sort.
 *
 * Servir n'est pas clore. Le patient a fini à la pharmacie, mais peut-être
 * pas dans l'établissement : il repart chez lui, ou il est attendu ailleurs.
 * C'est au pharmacien de le dire, et à l'hôte de l'inscrire dans le parcours,
 * car le parcours lui appartient.
 *
 * L'hôte IMPLÉMENTE ce contrat et le lie dans le conteneur. Sans hôte,
 * `NoQueueDischarge` ne propose aucune destination et refuse poliment : le
 * module le dit à l'écran plutôt que de faire semblant.
 */
interface QueueDischarge
{
    /**
     * Les services vers lesquels un patient servi peut être envoyé.
     *
     * @return list<QueueDestination>
     */
    public function destinations(): array;

    /**
     * Le patient a fini : son passage se clôt.
     */
    public function close(string $queueRef, string $patientRef, ?string $reason, Authenticatable $actor): void;

    /**
     * Le patient est attendu ailleurs : on l'y envoie.
     */
    public function refer(string $queueRef, string $patientRef, string $destinationRef, ?string $reason, Authenticatable $actor): void;
}
