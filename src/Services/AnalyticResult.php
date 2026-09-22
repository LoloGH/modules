<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\InsuranceSettlement;
use Keneya\FinanceCaisse\Models\InvoiceLine;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Support\AnalyticTree;
use Keneya\FinanceCaisse\Support\LedgerFilters;

/**
 * Le résultat par centre analytique : ce que chaque centre rapporte, ce qu'il
 * coûte, et ce qu'il reste.
 *
 * Trois produits et une charge, tous lus sur le centre GRAVÉ sur l'écriture :
 *
 *   - les encaissements de caisse ;
 *   - les règlements reçus des assureurs, répartis sur les centres au prorata
 *     de la part prise en charge de chaque ligne de la facture réglée : un
 *     règlement d'une facture qui mêle laboratoire et imagerie ne tombe pas
 *     entier dans le premier centre venu ;
 *   - les décaissements, rattachés à leur centre de charges.
 *
 * Chaque centre porte son propre total et celui de sa descendance : le total
 * d'un pôle est celui de ses services. Les écritures sans centre sont
 * rassemblées sous « Non rattaché » plutôt que passées sous silence.
 */
final class AnalyticResult
{
    public const UNASSIGNED = 'none';

    /**
     * @return array{
     *     rows: list<array{label: string, revenue: int, settlements: int, expenses: int, net: int}>,
     *     total: array{revenue: int, settlements: int, expenses: int, net: int}
     * }
     */
    public function build(LedgerFilters $filters): array
    {
        $tree = AnalyticTree::load();

        $revenue = $this->byCenter($filters->payments()->where('status', Payment::STATUS_VALID)->get(['analytic_center_id', 'amount']));
        $expenses = $this->byCenter($filters->disbursements()->where('status', Disbursement::STATUS_VALID)->get(['analytic_center_id', 'amount']));
        $settlements = $this->settlements($filters);

        // Un centre demandé restreint aussi les règlements : on répartit la
        // facture entière, puis on ne garde que ce qui tombe dans le centre.
        $scope = $filters->centerScope();

        if ($scope !== null) {
            $settlements = array_intersect_key($settlements, array_flip($scope));
        }

        $rows = [];

        foreach ($tree->flat() as $node) {
            $ids = $tree->withDescendants((int) $node['center']->id);

            $row = [
                'label' => $tree->label($node['center'], $node['depth']),
                'revenue' => $this->sum($revenue, $ids),
                'settlements' => $this->sum($settlements, $ids),
                'expenses' => $this->sum($expenses, $ids),
            ];

            // Un centre qui n'a rien porté sur la période n'encombre pas le
            // rapport ; son parent reste visible s'il a porté quelque chose.
            if ($row['revenue'] === 0 && $row['settlements'] === 0 && $row['expenses'] === 0) {
                continue;
            }

            $row['net'] = $row['revenue'] + $row['settlements'] - $row['expenses'];
            $rows[] = $row;
        }

        $unassigned = [
            'label' => 'Non rattaché',
            'revenue' => $revenue[self::UNASSIGNED] ?? 0,
            'settlements' => $settlements[self::UNASSIGNED] ?? 0,
            'expenses' => $expenses[self::UNASSIGNED] ?? 0,
        ];

        if ($unassigned['revenue'] !== 0 || $unassigned['settlements'] !== 0 || $unassigned['expenses'] !== 0) {
            $unassigned['net'] = $unassigned['revenue'] + $unassigned['settlements'] - $unassigned['expenses'];
            $rows[] = $unassigned;
        }

        // Le total est celui des écritures, pas celui des lignes : additionner
        // les lignes compterait deux fois ce qu'un pôle partage avec ses
        // services.
        $total = [
            'revenue' => array_sum($revenue),
            'settlements' => array_sum($settlements),
            'expenses' => array_sum($expenses),
        ];
        $total['net'] = $total['revenue'] + $total['settlements'] - $total['expenses'];

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param  iterable<Payment|Disbursement>  $items
     * @return array<int|string, int>
     */
    private function byCenter(iterable $items): array
    {
        $totals = [];

        foreach ($items as $item) {
            $key = $item->analytic_center_id === null ? self::UNASSIGNED : (int) $item->analytic_center_id;
            $totals[$key] = ($totals[$key] ?? 0) + (int) $item->amount;
        }

        return $totals;
    }

    /**
     * Les règlements d'assureurs de la période, répartis au prorata de la part
     * prise en charge de chaque ligne. Le dernier centre servi reçoit le reste
     * de la division : la somme répartie égale toujours le règlement.
     *
     * @return array<int|string, int>
     */
    private function settlements(LedgerFilters $filters): array
    {
        $received = InsuranceSettlement::query()
            // whereDate : la colonne peut porter une heure (SQLite), la borne non.
            ->whereDate('received_on', '>=', $filters->from->toDateString())
            ->whereDate('received_on', '<=', $filters->to->toDateString())
            ->get()
            ->groupBy('invoice_id');

        if ($received->isEmpty()) {
            return [];
        }

        $lines = InvoiceLine::query()
            ->whereIn('invoice_id', $received->keys()->all())
            ->where('insurer_share', '>', 0)
            ->get()
            ->groupBy('invoice_id');

        $totals = [];

        foreach ($received as $invoiceId => $settlements) {
            $amount = (int) $settlements->sum('amount');
            $invoiceLines = $lines->get($invoiceId);

            if ($invoiceLines === null || $invoiceLines->isEmpty()) {
                $totals[self::UNASSIGNED] = ($totals[self::UNASSIGNED] ?? 0) + $amount;

                continue;
            }

            $share = (int) $invoiceLines->sum('insurer_share');
            $spread = 0;
            $last = $invoiceLines->count() - 1;

            foreach ($invoiceLines->values() as $index => $line) {
                $part = $index === $last
                    ? $amount - $spread
                    : (int) floor($amount * (int) $line->insurer_share / $share);
                $spread += $part;

                $key = $line->analytic_center_id === null ? self::UNASSIGNED : (int) $line->analytic_center_id;
                $totals[$key] = ($totals[$key] ?? 0) + $part;
            }
        }

        return $totals;
    }

    /**
     * @param  array<int|string, int>  $totals
     * @param  list<int>  $ids
     */
    private function sum(array $totals, array $ids): int
    {
        $sum = 0;

        foreach ($ids as $id) {
            $sum += $totals[$id] ?? 0;
        }

        return $sum;
    }
}
