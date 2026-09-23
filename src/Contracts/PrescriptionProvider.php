<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Contracts;

use Keneya\Pharmacie\Prescriptions\Prescription;

/**
 * Les ordonnances à servir, tenues par l'application hôte (le DME).
 *
 * Le module ne prescrit pas et ne duplique pas les ordonnances : il les lit.
 * L'hôte IMPLÉMENTE ce contrat au-dessus de son dossier médical et le lie
 * dans le conteneur ; la pharmacie l'appelle par `Pharmacie::prescriptions()`.
 *
 * Ce qui reste du côté de la pharmacie : le **reliquat**. Ce qu'il manque
 * après une dispensation partielle est un fait de stock, pas un fait médical.
 * Le dossier du patient ne connaît que le statut global de l'ordonnance, que
 * la pharmacie lui rend par {@see PrescriptionSink}.
 *
 * Sans hôte, `NoPrescriptions` rend une liste vide et l'écran le dit.
 */
interface PrescriptionProvider
{
    /**
     * Les ordonnances qui attendent d'être servies, de la plus ancienne à la
     * plus récente.
     *
     * @return list<Prescription>
     */
    public function pending(): array;

    /**
     * Une ordonnance précise, pour la servir.
     */
    public function find(string $reference): ?Prescription;

    /**
     * Les ordonnances d'un patient : ce qui sert à voir son historique
     * pharmaceutique sans ouvrir son dossier médical.
     *
     * @return list<Prescription>
     */
    public function forPatient(string $patientId): array;
}
