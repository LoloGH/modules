<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Prescriptions;

/**
 * Une ligne d'ordonnance : ce que le prescripteur a écrit.
 *
 * `productCode` est facultatif : le DME prescrit souvent un nom, pas une
 * référence du catalogue pharmaceutique. Quand il le donne, la pharmacie
 * retrouve le produit toute seule ; sinon, le pharmacien le choisit — et
 * c'est là qu'une substitution peut se décider.
 */
final readonly class PrescriptionLine
{
    public function __construct(
        public string $label,
        public int $quantity,
        public ?string $productCode = null,
        public ?string $dosage = null,
        public ?string $form = null,
        public ?string $route = null,
        public ?string $frequency = null,
        public ?string $duration = null,
        public ?string $instructions = null,
        public bool $substitutable = true,
    ) {}

    /**
     * La posologie en une ligne : « 1 gélule, 2 fois par jour, 7 jours ».
     */
    public function posology(): string
    {
        return implode(', ', array_filter([$this->dosage, $this->frequency, $this->duration])) ?: '-';
    }
}
