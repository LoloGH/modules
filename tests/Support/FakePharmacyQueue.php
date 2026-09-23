<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Queue\PharmacyQueue;
use Keneya\Pharmacie\Queue\QueuedPatient;

/**
 * Une file d'attente d'hôte, pour les tests : on lui donne ses patients, et
 * elle retient qui a été appelé.
 */
final class FakePharmacyQueue implements PharmacyQueueProvider
{
    /** @var array<string, list<QueuedPatient>> */
    public array $patients = [];

    /** @var list<array{queue: string, patient: string, by: string}> */
    public array $calls = [];

    /**
     * @param  list<PharmacyQueue>  $queues
     */
    public function __construct(public array $queues = []) {}

    public function queues(): array
    {
        return $this->queues;
    }

    public function pendingPatients(string $queueRef): array
    {
        return $this->patients[$queueRef] ?? [];
    }

    public function callNext(string $queueRef, Authenticatable $preparer): ?QueuedPatient
    {
        $patient = ($this->patients[$queueRef] ?? [])[0] ?? null;

        if ($patient !== null) {
            $this->calls[] = ['queue' => $queueRef, 'patient' => $patient->ref, 'by' => (string) ($preparer->name ?? '')];
        }

        return $patient;
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
}
