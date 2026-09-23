<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\Pharmacie\Queue\PharmacyQueue;
use Keneya\Pharmacie\Queue\QueuedPatient;

/**
 * La file d'attente de la pharmacie, tenue par l'application hôte.
 *
 * Le module ne possède ni le dossier du patient ni son parcours : c'est
 * l'hôte (Keneya Workflow) qui sait qui attend d'être servi, et dans quel
 * ordre. Il IMPLÉMENTE ce contrat et le lie dans le conteneur ; la pharmacie
 * le lit par `Pharmacie::queue()`.
 *
 * Même mécanique que la file de caisse du module Finance : le module affiche
 * et appelle, l'hôte range. Sans hôte, `NoPharmacyQueue` rend une file vide
 * et l'écran le dit, plutôt que d'inventer des patients.
 *
 * Les références (`ref`) sont opaques pour le module : il les recopie telles
 * quelles dans ses écritures pour que l'hôte s'y retrouve.
 */
interface PharmacyQueueProvider
{
    /**
     * Les files que l'hôte expose (comptoir, hospitalisation, urgences…).
     *
     * @return list<PharmacyQueue>
     */
    public function queues(): array;

    /**
     * Les patients qui attendent dans cette file, du plus ancien au plus
     * récent.
     *
     * @return list<QueuedPatient>
     */
    public function pendingPatients(string $queueRef): array;

    /**
     * Appeler le patient suivant : l'hôte le marque comme appelé et le rend.
     * Null si la file est vide.
     */
    public function callNext(string $queueRef, Authenticatable $preparer): ?QueuedPatient;

    /**
     * Retrouver un patient précis de la file, pour reprendre une
     * dispensation interrompue.
     */
    public function findPatient(string $queueRef, string $patientRef): ?QueuedPatient;
}
