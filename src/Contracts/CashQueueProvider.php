<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Queue\QueuedVisit;

/**
 * La file d'attente des caisses, fournie par l'application hôte.
 *
 * Finance ne connaît ni les visites ni les tickets de l'hôte : c'est l'hôte
 * qui IMPLÉMENTE ce contrat et l'enregistre dans le conteneur, comme il pose
 * sa décision d'accès (`Finance::authorizeAccessUsing()`). Finance le lit par
 * `Finance::cashQueue()` et n'en reçoit que des objets de valeur stables,
 * jamais les modèles de l'hôte.
 *
 * Sans hôte, l'implémentation par défaut ne fournit aucune file.
 *
 * Les références (`ref`) sont des chaînes opaques choisies par l'hôte :
 * Finance les transporte sans les interpréter.
 */
interface CashQueueProvider
{
    /**
     * Les files de caisse de l'hôte (« Caisse Ticket », « Caisse Services »…).
     *
     * @return list<CashQueue>
     */
    public function queues(): array;

    /**
     * Les visites du jour qui attendent un encaissement à cette caisse : les
     * patients appelés d'abord, puis ceux qui attendent, par ticket.
     *
     * @return list<QueuedVisit>
     */
    public function pendingVisits(string $queueRef): array;

    /**
     * Appelle le patient suivant de cette caisse, au nom du caissier. Null si
     * personne n'attend.
     */
    public function callNext(string $queueRef, Authenticatable $cashier): ?QueuedVisit;

    /**
     * Une visite de cette file qui attend encore un encaissement, ou null.
     */
    public function findVisit(string $queueRef, string $visitRef): ?QueuedVisit;
}
