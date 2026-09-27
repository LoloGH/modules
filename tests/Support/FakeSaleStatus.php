<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Support;

use Keneya\Pharmacie\Contracts\SaleStatusProvider;
use Keneya\Pharmacie\Sales\SaleStatus;

/**
 * Une caisse d'hôte qui répond où en sont ses pièces, pour les tests.
 *
 * Elle se règle pièce par pièce : impayée, partiellement réglée, soldée du
 * point de vue du patient alors que l'assureur doit encore sa part, ou
 * annulée. Ce sont les quatre situations que la délivrance doit savoir
 * distinguer.
 */
final class FakeSaleStatus implements SaleStatusProvider
{
    /** @var array<string, SaleStatus> */
    private array $statuses = [];

    public function set(string $reference, SaleStatus $status): self
    {
        $this->statuses[$reference] = $status;

        return $this;
    }

    /**
     * Le cas courant : le patient a tout payé.
     */
    public function settle(string $reference, int $total): self
    {
        return $this->set($reference, new SaleStatus(
            reference: $reference,
            total: $total,
            patientDue: 0,
            patientPaid: $total,
        ));
    }

    /**
     * Le patient doit encore quelque chose.
     */
    public function owing(string $reference, int $total, int $due): self
    {
        return $this->set($reference, new SaleStatus(
            reference: $reference,
            total: $total,
            patientDue: $due,
            patientPaid: max(0, $total - $due),
        ));
    }

    public function status(string $reference): ?SaleStatus
    {
        return $this->statuses[$reference] ?? null;
    }
}
