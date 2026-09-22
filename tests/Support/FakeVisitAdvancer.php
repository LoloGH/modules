<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Support;

use Closure;
use Keneya\FinanceCaisse\Contracts\VisitAdvancer;
use Keneya\FinanceCaisse\Queue\SettledPayment;

/**
 * L'avancement de visite d'un hôte, en mémoire. `failWith` simule un hôte
 * qui refuse (visite déjà orientée, dossier clôturé, panne).
 */
final class FakeVisitAdvancer implements VisitAdvancer
{
    /** @var list<array{visit: string, payment: SettledPayment}> */
    public array $advanced = [];

    public ?Closure $failWith = null;

    public function advanceAfterPayment(string $visitRef, SettledPayment $payment): void
    {
        if ($this->failWith !== null) {
            throw ($this->failWith)();
        }

        $this->advanced[] = ['visit' => $visitRef, 'payment' => $payment];
    }
}
