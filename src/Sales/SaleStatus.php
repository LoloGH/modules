<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Sales;

/**
 * Où en est la pièce créée par la caisse, telle que l'hôte la lit.
 *
 * La pharmacie ne décide jamais qu'une facture est réglée : elle demande.
 * Ce qui l'intéresse tient en une question, « puis-je délivrer ? », et la
 * réponse n'est pas « la facture est payée » mais **« la part du patient est
 * soldée »**. Une facture peut rester partielle des semaines parce qu'un
 * assureur n'a pas encore versé sa part : ce n'est pas au patient d'attendre
 * ses médicaments pendant ce temps, et c'est à Finance de poursuivre la
 * créance.
 *
 * Les montants sont en unités entières de la devise, comme partout ailleurs
 * dans le module.
 */
final readonly class SaleStatus
{
    public function __construct(
        public string $reference,
        public int $total,
        /** Ce que le patient doit encore, remise et rejets d'assureur compris. */
        public int $patientDue,
        /** Ce que le patient a deja verse. */
        public int $patientPaid = 0,
        /** La part portee par un tiers payant, s'il y en a un. */
        public int $coveredShare = 0,
        public ?string $insurerName = null,
        public bool $cancelled = false,
    ) {}

    /**
     * La porte de la délivrance : le patient ne doit plus rien.
     *
     * Une prise en charge à 100 % passe donc d'emblée, sans qu'on ait à
     * inventer un encaissement de zéro franc.
     */
    public function settledForPatient(): bool
    {
        return ! $this->cancelled && $this->patientDue <= 0;
    }

    public function isCovered(): bool
    {
        return $this->coveredShare > 0;
    }
}
