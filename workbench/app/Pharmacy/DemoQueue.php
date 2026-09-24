<?php

declare(strict_types=1);

namespace Workbench\App\Pharmacy;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Queue\PharmacyQueue;
use Keneya\Pharmacie\Queue\QueuedPatient;

/**
 * La file d'attente de l'hôte de démonstration.
 *
 * Elle imite ce que fera Keneya Workflow : deux files, des patients qui
 * attendent, et un appel qui marque le patient comme appelé. Les appels sont
 * gardés dans le cache de la session de développement, rien n'est écrit dans
 * le module, qui ne possède pas la file.
 */
final class DemoQueue implements PharmacyQueueProvider
{
    private const CALLED = 'pharmacie-demo-appeles';

    public function queues(): array
    {
        return [
            new PharmacyQueue('comptoir', 'Comptoir', $this->waiting('comptoir'), 'Patients venus de la consultation'),
            new PharmacyQueue('hospitalisation', 'Hospitalisation', $this->waiting('hospitalisation'), 'Traitements des patients hospitalisés'),
        ];
    }

    public function pendingPatients(string $queueRef): array
    {
        $called = (array) Cache::get(self::CALLED, []);

        return array_values(array_map(
            fn (array $row): QueuedPatient => new QueuedPatient(
                ref: $row['ref'],
                patientId: $row['patient_id'],
                patientName: $row['name'],
                reason: $row['reason'],
                prescriptionRef: $row['prescription'],
                waitingSince: now()->subMinutes($row['minutes']),
                calledBy: $called[$row['ref']] ?? null,
            ),
            $this->rows($queueRef),
        ));
    }

    public function callNext(string $queueRef, Authenticatable $preparer): ?QueuedPatient
    {
        foreach ($this->pendingPatients($queueRef) as $patient) {
            if ($patient->isCalled()) {
                continue;
            }

            $called = (array) Cache::get(self::CALLED, []);
            $called[$patient->ref] = (string) ($preparer->name ?? 'Comptoir');
            Cache::put(self::CALLED, $called, now()->addHours(4));

            return $patient;
        }

        return null;
    }

    public function findPatient(string $queueRef, string $patientRef): ?QueuedPatient
    {
        foreach ($this->pendingPatients($queueRef) as $patient) {
            if ($patient->ref === $patientRef) {
                return $patient;
            }
        }

        return null;
    }

    private function waiting(string $queueRef): int
    {
        $called = (array) Cache::get(self::CALLED, []);

        return count(array_filter(
            $this->rows($queueRef),
            static fn (array $row): bool => ! isset($called[$row['ref']]),
        ));
    }

    /**
     * @return list<array{ref: string, patient_id: string, name: string, reason: string, prescription: ?string, minutes: int}>
     */
    private function rows(string $queueRef): array
    {
        return $queueRef === 'hospitalisation'
            ? [
                ['ref' => 'H-1', 'patient_id' => 'PAT-000318', 'name' => 'Oumar Sidibé', 'reason' => 'Traitement du soir', 'prescription' => 'ORD-2026-000118', 'minutes' => 12],
                ['ref' => 'H-2', 'patient_id' => 'PAT-000412', 'name' => 'Fanta Coulibaly', 'reason' => 'Perfusion', 'prescription' => 'ORD-2026-000121', 'minutes' => 4],
            ]
            : [
                ['ref' => 'C-1', 'patient_id' => 'PAT-000123', 'name' => 'Aminata Traoré', 'reason' => 'Ordonnance de consultation', 'prescription' => 'ORD-2026-000097', 'minutes' => 21],
                ['ref' => 'C-2', 'patient_id' => 'PAT-000201', 'name' => 'Ibrahim Keita', 'reason' => 'Achat libre', 'prescription' => null, 'minutes' => 9],
                ['ref' => 'C-3', 'patient_id' => 'PAT-000265', 'name' => 'Mariam Diarra', 'reason' => 'Ordonnance des urgences', 'prescription' => 'ORD-2026-000104', 'minutes' => 3],
            ];
    }
}
