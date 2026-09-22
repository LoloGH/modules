<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Queue;

use Keneya\FinanceCaisse\Contracts\VisitAdvancer;

/**
 * Sans hôte, aucune visite à faire avancer : le module seul n'a pas de file.
 */
final class NoVisitAdvancer implements VisitAdvancer
{
    public function advanceAfterPayment(string $visitRef, SettledPayment $payment): void
    {
        //
    }
}
