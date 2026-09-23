<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Queue;

use Illuminate\Support\Carbon;

/**
 * Un patient qui attend à la pharmacie, tel que l'hôte le décrit.
 *
 * Le module n'en garde que ce qu'il lui faut pour servir et pour tracer :
 * la référence opaque de l'hôte, l'identifiant et le nom du patient, l'objet
 * de sa venue, et l'ordonnance s'il en a une. Rien du dossier médical.
 */
final readonly class QueuedPatient
{
    public function __construct(
        public string $ref,
        public string $patientId,
        public string $patientName,
        public ?string $reason = null,
        public ?string $prescriptionRef = null,
        public ?Carbon $waitingSince = null,
        public ?string $calledBy = null,
    ) {}

    public function isCalled(): bool
    {
        return $this->calledBy !== null;
    }

    /**
     * Depuis combien de temps il attend, en minutes : ce que l'écran affiche
     * pour que le comptoir sache qui sert en premier.
     */
    public function waitedMinutes(): ?int
    {
        return $this->waitingSince?->diffInMinutes(now()) === null
            ? null
            : (int) $this->waitingSince->diffInMinutes(now());
    }
}
