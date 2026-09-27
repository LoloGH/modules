<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Queue;

use Keneya\FinanceCaisse\Actions\BillExternalSale;
use Keneya\FinanceCaisse\Catalog\CatalogAct;

/**
 * Une visite qui attend un encaissement dans une file de caisse de l'hôte.
 *
 * Objet de valeur en lecture seule : la forme stable promise par le contrat
 * `CashQueueProvider`. Ajouter un champ est permis ; en retirer un casse
 * l'hôte.
 *
 * - `ref` : référence opaque d'un PASSAGE à cette caisse, pas de l'épisode :
 *   un même patient repasse par une autre caisse (ticket, puis services)
 *   sous une autre référence. Finance s'en sert pour refuser d'encaisser deux
 *   fois le même passage ;
 * - `patientRef` : identifiant stable et lisible du patient (son code), repris
 *   tel quel sur l'encaissement ;
 * - `act` : l'acte attendu, résolu par l'hôte dans le catalogue de Finance,
 *   avec son tarif standard (`act->activeAmount`) ; null si rien ne s'applique.
 *
 * Tout ce qui se paie à une caisse ne vient pas du catalogue des actes : un
 * autre module de l'établissement peut avoir déjà vendu, à ses prix, et fait
 * émettre une facture ({@see BillExternalSale}).
 * L'hôte remplit alors `invoiceId` et `reason` ; le montant attendu est ce que
 * la facture laisse à la charge du patient, et l'encaissement s'y rattache.
 */
final readonly class QueuedVisit
{
    public const STATUS_WAITING = 'waiting';

    public const STATUS_CALLED = 'called';

    public function __construct(
        public string $ref,
        public int $token,
        public string $status,
        public string $patientRef,
        public string $patientName,
        public ?string $originService,
        public ?string $destinationService,
        public ?CatalogAct $act,
        public ?int $invoiceId = null,
        public ?int $amount = null,
        public ?string $reason = null,
    ) {}

    public function isCalled(): bool
    {
        return $this->status === self::STATUS_CALLED;
    }

    /**
     * Le montant attendu : ce que laisse une facture rattachée, sinon le
     * tarif standard de l'acte, s'il est fixé.
     */
    public function expectedAmount(): ?int
    {
        return $this->amount ?? $this->act?->activeAmount;
    }

    /**
     * Ce que le caissier lit dans la colonne « Acte attendu » : le motif
     * annoncé par l'hôte, sinon le nom de l'acte.
     */
    public function label(): ?string
    {
        return $this->reason ?? $this->act?->name;
    }
}
