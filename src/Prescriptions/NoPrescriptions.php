<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Prescriptions;

use Keneya\Pharmacie\Contracts\PrescriptionProvider;
use Keneya\Pharmacie\Contracts\PrescriptionSink;
use Keneya\Pharmacie\Models\Dispensation;

/**
 * Sans dossier medical branche : aucune ordonnance, et rien a lui rendre.
 *
 * La pharmacie reste utilisable — on sert au comptoir, sans ordonnance
 * electronique — et l'ecran dit clairement que le DME n'est pas branche,
 * plutot que d'afficher une liste vide sans explication.
 */
final class NoPrescriptions implements PrescriptionProvider, PrescriptionSink
{
    public function pending(): array
    {
        return [];
    }

    public function find(string $reference): ?Prescription
    {
        return null;
    }

    public function forPatient(string $patientId): array
    {
        return [];
    }

    public function dispensed(string $reference, Dispensation $dispensation, bool $complete): void
    {
        // Rien a rendre : personne n'ecoute.
    }
}
