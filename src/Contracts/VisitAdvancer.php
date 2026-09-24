<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Contracts;

use Keneya\FinanceCaisse\Queue\SettledPayment;

/**
 * Faire avancer, chez l'hôte, la visite que Finance vient d'encaisser.
 *
 * Quand un patient appelé depuis la file est encaissé dans Finance, sa visite
 * doit quitter la caisse et poursuivre son parcours chez l'hôte (nouveau
 * service, nouveau ticket, historique, SMS). Finance ne sait pas le faire :
 * l'hôte IMPLÉMENTE ce contrat et le lie dans le conteneur, comme
 * `CashQueueProvider`. Finance l'appelle par `Finance::visitAdvancer()`.
 *
 * Finance l'appelle dans la MÊME transaction que l'encaissement : si l'hôte
 * lève une exception, l'encaissement est annulé avec lui, jamais d'argent
 * encaissé pour un patient resté bloqué à la caisse. L'hôte ne crée AUCUN
 * paiement de son côté : la source de vérité du paiement est Finance.
 *
 * Rien ne doit partir au-dehors (SMS, message) avant la validation de la
 * transaction : l'hôte le diffère (`afterCommit`).
 */
interface VisitAdvancer
{
    /**
     * @throws \Throwable si la visite ne peut pas avancer : l'encaissement est
     *                    alors annulé. Le message d'une `\InvalidArgumentException`
     *                    ou d'une `\DomainException` est montré au caissier.
     */
    public function advanceAfterPayment(string $visitRef, SettledPayment $payment): void;
}
