<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\InsuranceSettlement;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\InvoiceLine;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Support\LedgerFilters;

/**
 * Les rapports financiers, calculés sur les écritures réelles : encaissements
 * et décaissements VALIDES de caisse (un mouvement annulé ne compte pas) et
 * règlements reçus des assureurs (hors tiroir, datés du jour de réception).
 *
 * Les filtres service et activité ne portent que sur les encaissements ; le
 * moyen de paiement, sur les encaissements et les décaissements.
 *
 * Chaque rapport rend des lignes « libellé, nombre, montant » (ou, pour la
 * synthèse, « jour, recettes, dépenses, solde »), plus un total : la même
 * forme sert l'écran et l'export CSV.
 */
final class ReportBuilder
{
    public const TYPES = [
        'recettes-centre' => 'Recettes par service (centre analytique)',
        'recettes-acte' => 'Recettes par activité (acte)',
        'recettes-moyen' => 'Recettes par moyen de paiement',
        'depenses-categorie' => 'Dépenses par catégorie',
        'depenses-moyen' => 'Dépenses par moyen de paiement',
        'reglements-assurance' => 'Règlements des assureurs, par assureur',
        'prises-en-charge-organisme' => 'Prises en charge par organisme (assurances et aides sociales)',
        'prises-en-charge-acte' => 'Prises en charge par acte',
        'synthese' => 'Synthèse journalière : recettes, règlements, dépenses, solde',
    ];

    public const DEFAULT_TYPE = 'recettes-centre';

    public static function type(mixed $value): string
    {
        return is_string($value) && isset(self::TYPES[$value]) ? $value : self::DEFAULT_TYPE;
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string|int>>, total: list<string|int>, money: list<int>}
     *                                                                                                               `money` : index des colonnes en FCFA
     */
    public function build(string $type, LedgerFilters $filters): array
    {
        return match ($type) {
            'recettes-acte' => $this->grouped(
                $this->payments($filters)->load('act'),
                static fn (Payment $p): string => $p->act?->name ?? 'Hors catalogue',
                'Activité (acte)',
            ),
            'recettes-moyen' => $this->grouped(
                $this->payments($filters)->load('method'),
                static fn (Payment $p): string => $p->method?->name ?? '—',
                'Moyen de paiement',
            ),
            'depenses-categorie' => $this->grouped(
                $filters->disbursements()->where('status', Disbursement::STATUS_VALID)->get(),
                static fn (Disbursement $d): string => $d->categoryLabel(),
                'Catégorie',
            ),
            'reglements-assurance' => $this->grouped(
                $this->settlements($filters)->load('insurer'),
                static fn (InsuranceSettlement $s): string => $s->insurer?->name ?? '—',
                'Assureur',
            ),
            'prises-en-charge-organisme' => $this->coverageByInsurer($filters),
            'prises-en-charge-acte' => $this->coverageByAct($filters),
            'depenses-moyen' => $this->grouped(
                $filters->disbursements()->where('status', Disbursement::STATUS_VALID)->with('method')->get(),
                static fn (Disbursement $d): string => $d->method?->name ?? '—',
                'Moyen de paiement',
            ),
            'synthese' => $this->daily($filters),
            default => $this->grouped(
                $this->payments($filters)->load('act.center'),
                static fn (Payment $p): string => $p->act?->center?->name ?? ($p->act === null ? 'Hors catalogue' : 'Sans centre analytique'),
                'Service (centre analytique)',
            ),
        };
    }

    /**
     * @return Collection<int, Payment>
     */
    private function payments(LedgerFilters $filters)
    {
        return $filters->payments()->where('status', Payment::STATUS_VALID)->get();
    }

    /**
     * Les règlements d'assureurs reçus dans la période.
     *
     * @return Collection<int, InsuranceSettlement>
     */
    private function settlements(LedgerFilters $filters)
    {
        return InsuranceSettlement::query()
            // whereDate : la colonne peut porter une heure (SQLite), la borne non.
            ->whereDate('received_on', '>=', $filters->from->toDateString())
            ->whereDate('received_on', '<=', $filters->to->toDateString())
            ->get();
    }

    /**
     * Factures prises en charge émises dans la période (hors annulées).
     *
     * @return Builder<Invoice>
     */
    private function coveredInvoices(LedgerFilters $filters)
    {
        return Invoice::query()
            ->whereNotNull('insurer_id')
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->whereBetween('created_at', [$filters->from, $filters->to]);
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string|int>>, total: list<string|int>, money: list<int>}
     */
    private function coverageByInsurer(LedgerFilters $filters): array
    {
        $rows = $this->coveredInvoices($filters)->with('insurer')->get()
            ->groupBy(fn (Invoice $i): string => ($i->insurer?->name ?? '—').' ('.($i->insurer?->kindLabel() ?? '—').')')
            ->map(fn ($group, string $name): array => [
                $name,
                $group->count(),
                (int) $group->sum('insurer_share'),
                (int) $group->sum('insurer_paid'),
                (int) $group->sum(fn (Invoice $i): int => $i->insurerOutstanding()),
            ])
            ->sortByDesc(fn (array $row): int => $row[2])
            ->values()
            ->all();

        return [
            'columns' => ['Organisme', 'Factures', 'Pris en charge', 'Réglé', 'Reste dû'],
            'rows' => $rows,
            'total' => ['Total', array_sum(array_column($rows, 1)), array_sum(array_column($rows, 2)), array_sum(array_column($rows, 3)), array_sum(array_column($rows, 4))],
            'money' => [2, 3, 4],
        ];
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string|int>>, total: list<string|int>, money: list<int>}
     */
    private function coverageByAct(LedgerFilters $filters): array
    {
        $rows = InvoiceLine::query()
            ->whereIn('invoice_id', $this->coveredInvoices($filters)->select('id'))
            ->where('insurer_share', '>', 0)
            ->when($filters->actId, fn ($q) => $q->where('act_id', $filters->actId))
            ->get()
            ->groupBy('label')
            ->map(fn ($group, string $label): array => [$label, (int) $group->sum('quantity'), (int) $group->sum('amount'), (int) $group->sum('insurer_share')])
            ->sortByDesc(fn (array $row): int => $row[3])
            ->values()
            ->all();

        return [
            'columns' => ['Acte', 'Quantité', 'Montant', 'Pris en charge'],
            'rows' => $rows,
            'total' => ['Total', array_sum(array_column($rows, 1)), array_sum(array_column($rows, 2)), array_sum(array_column($rows, 3))],
            'money' => [2, 3],
        ];
    }

    /**
     * Les chiffres clés de la période, quel que soit le rapport choisi.
     *
     * @return array{revenue: int, insurance: int, expenses: int, net: int, covered: int}
     */
    public function summary(LedgerFilters $filters): array
    {
        $revenue = (int) $filters->payments()->where('status', Payment::STATUS_VALID)->sum('amount');
        $insurance = (int) $this->settlements($filters)->sum('amount');
        $expenses = (int) $filters->disbursements()->where('status', Disbursement::STATUS_VALID)->sum('amount');

        $covered = (int) $this->coveredInvoices($filters)->sum('insurer_share');

        return ['revenue' => $revenue, 'insurance' => $insurance, 'expenses' => $expenses, 'net' => $revenue + $insurance - $expenses, 'covered' => $covered];
    }

    /**
     * @param  iterable<Payment|Disbursement|InsuranceSettlement>  $items
     * @return array{columns: list<string>, rows: list<list<string|int>>, total: list<string|int>, money: list<int>}
     */
    private function grouped(iterable $items, callable $label, string $heading): array
    {
        $rows = collect($items)
            ->groupBy($label)
            ->map(fn ($group, string $name): array => [$name, $group->count(), (int) $group->sum('amount')])
            ->sortByDesc(fn (array $row): int => $row[2])
            ->values()
            ->all();

        return [
            'columns' => [$heading, 'Nombre', 'Montant'],
            'rows' => $rows,
            'total' => ['Total', array_sum(array_column($rows, 1)), array_sum(array_column($rows, 2))],
            'money' => [2],
        ];
    }

    /**
     * @return array{columns: list<string>, rows: list<list<string|int>>, total: list<string|int>, money: list<int>}
     */
    private function daily(LedgerFilters $filters): array
    {
        $in = $this->payments($filters)->groupBy(fn (Payment $p): string => $p->created_at->toDateString())
            ->map(fn ($group): int => (int) $group->sum('amount'));
        $out = $filters->disbursements()->where('status', Disbursement::STATUS_VALID)->get()
            ->groupBy(fn (Disbursement $d): string => $d->created_at->toDateString())
            ->map(fn ($group): int => (int) $group->sum('amount'));
        $ins = $this->settlements($filters)
            ->groupBy(fn (InsuranceSettlement $s): string => $s->received_on->toDateString())
            ->map(fn ($group): int => (int) $group->sum('amount'));

        $days = $in->keys()->merge($out->keys())->merge($ins->keys())->unique()->sort()->values();

        $rows = $days->map(fn (string $day): array => [
            Carbon::parse($day)->format('d/m/Y'),
            $in->get($day, 0),
            $ins->get($day, 0),
            $out->get($day, 0),
            $in->get($day, 0) + $ins->get($day, 0) - $out->get($day, 0),
        ])->all();

        return [
            'columns' => ['Jour', 'Recettes caisse', 'Règlements assurance', 'Dépenses', 'Solde'],
            'rows' => $rows,
            'total' => ['Total', (int) $in->sum(), (int) $ins->sum(), (int) $out->sum(), (int) $in->sum() + (int) $ins->sum() - (int) $out->sum()],
            'money' => [1, 2, 3, 4],
        ];
    }
}
