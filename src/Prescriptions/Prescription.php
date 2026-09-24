<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Prescriptions;

use Illuminate\Support\Carbon;

/**
 * Une ordonnance telle que le dossier médical la décrit.
 *
 * Volontairement pauvre : de quoi servir, et rien de plus. Le pharmacien a
 * besoin du patient, du prescripteur, des lignes et des alertes d'allergie,
 * pas du dossier médical complet.
 */
final readonly class Prescription
{
    /**
     * @param  list<PrescriptionLine>  $lines
     * @param  list<string>  $allergyWarnings  alertes relevées par le DME
     */
    public function __construct(
        public string $reference,
        public string $patientId,
        public string $patientName,
        public array $lines,
        public ?string $prescriber = null,
        public ?Carbon $issuedOn = null,
        public ?Carbon $validUntil = null,
        public ?string $instructions = null,
        public array $allergyWarnings = [],
        public ?string $status = null,
    ) {}

    public function hasAllergyWarnings(): bool
    {
        return $this->allergyWarnings !== [];
    }

    /**
     * Une ordonnance périmée se sert encore, mais le pharmacien doit le
     * savoir : c'est à lui de juger, pas au logiciel de décider seul.
     */
    public function isExpired(): bool
    {
        return $this->validUntil !== null && $this->validUntil->lt(now()->startOfDay());
    }

    public function totalPrescribed(): int
    {
        return array_sum(array_map(static fn (PrescriptionLine $line): int => $line->quantity, $this->lines));
    }
}
