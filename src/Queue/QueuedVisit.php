<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Queue;

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
    ) {}

    public function isCalled(): bool
    {
        return $this->status === self::STATUS_CALLED;
    }

    /** Le montant attendu : le tarif standard de l'acte, s'il est fixé. */
    public function expectedAmount(): ?int
    {
        return $this->act?->activeAmount;
    }
}
