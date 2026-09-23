<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Support;

use Keneya\Pharmacie\Contracts\PrescriptionProvider;
use Keneya\Pharmacie\Contracts\PrescriptionSink;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Prescriptions\Prescription;

/**
 * Un dossier médical d'hôte, pour les tests : on lui donne ses ordonnances,
 * et il retient ce que la pharmacie lui rend.
 */
final class FakePrescriptions implements PrescriptionProvider, PrescriptionSink
{
    /** @var list<array{reference: string, dispensation: string, complete: bool}> */
    public array $reported = [];

    /**
     * @param  list<Prescription>  $prescriptions
     */
    public function __construct(public array $prescriptions = []) {}

    public function pending(): array
    {
        return $this->prescriptions;
    }

    public function find(string $reference): ?Prescription
    {
        foreach ($this->prescriptions as $prescription) {
            if ($prescription->reference === $reference) {
                return $prescription;
            }
        }

        return null;
    }

    public function forPatient(string $patientId): array
    {
        return array_values(array_filter(
            $this->prescriptions,
            static fn (Prescription $prescription): bool => $prescription->patientId === $patientId,
        ));
    }

    public function dispensed(string $reference, Dispensation $dispensation, bool $complete): void
    {
        $this->reported[] = [
            'reference' => $reference,
            'dispensation' => (string) $dispensation->number,
            'complete' => $complete,
        ];
    }
}
