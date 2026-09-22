<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Queue;

/**
 * L'encaissement que Finance vient d'enregistrer, tel que l'hôte le reçoit
 * pour faire avancer la visite (historique, message au patient).
 */
final readonly class SettledPayment
{
    public function __construct(
        public string $number,
        public int $amount,
        public ?string $actName,
        public string $queueRef,
        public ?string $queueName,
        public string $cashierName,
    ) {}
}
