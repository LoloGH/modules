<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\FinanceCaisse\Catalog\CatalogAct;
use Keneya\FinanceCaisse\Contracts\CashQueueProvider;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Queue\QueuedVisit;

/**
 * Une file de caisse d'hôte, en mémoire : ce que l'application hôte
 * fournirait à Finance par le contrat.
 */
final class FakeCashQueue implements CashQueueProvider
{
    /** @var array<string, list<QueuedVisit>> */
    private array $visits = [];

    /** @var list<string> */
    public array $calls = [];

    /**
     * @param  list<CashQueue>  $queues
     */
    public function __construct(private readonly array $queues = []) {}

    public function add(string $queueRef, string $ref, int $token, string $patientName, ?CatalogAct $act = null, string $status = QueuedVisit::STATUS_WAITING): QueuedVisit
    {
        $visit = new QueuedVisit($ref, $token, $status, 'PAT-'.$ref, $patientName, 'Accueil', 'Médecine générale', $act);
        $this->visits[$queueRef][] = $visit;

        return $visit;
    }

    public function queues(): array
    {
        return $this->queues;
    }

    public function pendingVisits(string $queueRef): array
    {
        $visits = $this->visits[$queueRef] ?? [];

        usort($visits, static fn (QueuedVisit $a, QueuedVisit $b): int => [$a->isCalled() ? 0 : 1, $a->token] <=> [$b->isCalled() ? 0 : 1, $b->token]);

        return $visits;
    }

    public function callNext(string $queueRef, Authenticatable $cashier): ?QueuedVisit
    {
        $this->calls[] = $queueRef.':'.$cashier->getAuthIdentifier();

        foreach ($this->visits[$queueRef] ?? [] as $index => $visit) {
            if (! $visit->isCalled()) {
                $called = new QueuedVisit(
                    $visit->ref, $visit->token, QueuedVisit::STATUS_CALLED, $visit->patientRef, $visit->patientName,
                    $visit->originService, $visit->destinationService, $visit->act,
                );
                $this->visits[$queueRef][$index] = $called;

                return $called;
            }
        }

        return null;
    }

    public function findVisit(string $queueRef, string $visitRef): ?QueuedVisit
    {
        foreach ($this->visits[$queueRef] ?? [] as $visit) {
            if ($visit->ref === $visitRef) {
                return $visit;
            }
        }

        return null;
    }
}
