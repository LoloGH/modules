<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Discount;
use Keneya\FinanceCaisse\Models\InsuranceSettlement;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\PatientDeposit;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Models\Refund;
use Keneya\FinanceCaisse\Support\DashboardPeriod;

/**
 * Chiffres du tableau de bord.
 *
 * Lecture seule, et uniquement à partir de ce que la base contient déjà :
 * encaissements, décaissements, avances, règlements d'assureurs, factures et
 * sessions de caisse. Aucune estimation, aucune donnée de remplissage, un
 * écran vide vaut mieux qu'un chiffre faux.
 *
 * Tout se calcule sur la période choisie (voir DashboardPeriod), comparée à
 * la période équivalente précédente. Les opérations annulées sont exclues
 * partout, comme dans `CashSessionCalculator`.
 */
final class DashboardMetrics
{
    /** Nombre de jours de l'historique affiché par défaut. */
    public const WINDOW = 7;

    /**
     * @return array<string, mixed>
     */
    public function overview(DashboardPeriod $period): array
    {
        $revenue = $this->sum(Payment::query(), $period->range());
        $revenueBefore = $this->sum(Payment::query(), $period->previousRange());
        $expenses = $this->sum(Disbursement::query(), $period->range());
        $expensesBefore = $this->sum(Disbursement::query(), $period->previousRange());
        $settlements = $this->settlements($period->range());

        $series = $this->series($period);
        $split = $this->split($period);

        return [
            'period' => $period,

            'revenue' => $revenue,
            'revenue_before' => $revenueBefore,
            'revenue_trend' => $this->trend($revenue, $revenueBefore),

            'expenses' => $expenses,
            'expenses_before' => $expensesBefore,
            'expenses_trend' => $this->trend($expenses, $expensesBefore),

            'settlements' => $settlements,
            // Le résultat de la période : ce qui est entré, moins ce qui est
            // sorti. Les avances n'y sont pas : elles restent dues au patient.
            'net' => $revenue + $settlements - $expenses,

            'operations' => Payment::query()->valid()->whereBetween('created_at', $period->range())->count(),
            'deposits' => $this->sum(PatientDeposit::query(), $period->range()),

            'open_sessions' => CashSession::query()->open()->count(),
            'sessions_to_validate' => CashSession::query()->where('status', CashSession::STATUS_CLOSED)->count(),

            'window_total' => array_sum(array_column($series, 'amount')),
            'series' => $series,
            'split' => $split,
            'centers' => $this->centers($period),
            'acts' => $this->acts($period),
            'coverage' => $this->coverage($period),
            'dues' => $this->dues(),
            'todo' => $this->todo(),
        ];
    }

    /**
     * Les derniers encaissements valides, pour la liste « Dernières
     * transactions ».
     *
     * @return Collection<int, Payment>
     */
    public function latestPayments(int $limit = 6)
    {
        return Payment::query()
            ->valid()
            ->with(['method', 'session'])
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Ce que rapporte chaque centre analytique sur la période, du plus fort
     * au plus faible. Le centre est celui gravé sur l'encaissement.
     *
     * @return list<array{name: string, amount: int, percent: int}>
     */
    private function centers(DashboardPeriod $period): array
    {
        $rows = Payment::query()->valid()
            ->whereBetween('created_at', $period->range())
            ->groupBy('analytic_center_id')
            ->selectRaw('analytic_center_id, SUM(amount) as total')
            ->pluck('total', 'analytic_center_id');

        if ($rows->isEmpty()) {
            return [];
        }

        $names = AnalyticCenter::query()->whereIn('id', $rows->keys()->filter())->pluck('name', 'id');
        $total = (int) $rows->sum();

        $centers = $rows->map(fn ($amount, $id): array => [
            'name' => $id === '' || $id === null ? 'Non rattaché' : (string) ($names[$id] ?? 'Non rattaché'),
            'amount' => (int) $amount,
            'percent' => $total === 0 ? 0 : (int) round((int) $amount / $total * 100),
        ])->values()->all();

        usort($centers, static fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return array_slice($centers, 0, 6);
    }

    /**
     * Les actes les plus encaissés sur la période : combien de fois, et pour
     * combien.
     *
     * @return list<array{name: string, count: int, amount: int}>
     */
    private function acts(DashboardPeriod $period): array
    {
        return DB::table('finance_payments as p')
            ->join('finance_acts as a', 'a.id', '=', 'p.act_id')
            ->where('p.status', Payment::STATUS_VALID)
            ->whereBetween('p.created_at', $period->range())
            ->groupBy('a.id', 'a.name')
            ->selectRaw('a.name as name, COUNT(*) as n, SUM(p.amount) as total')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(static fn ($row): array => [
                'name' => (string) $row->name,
                'count' => (int) $row->n,
                'amount' => (int) $row->total,
            ])
            ->all();
    }

    /**
     * Les prises en charge de la période (factures émises, hors annulées) :
     * part des assurances, des aides sociales, réglé, reste dû.
     *
     * @return array{insurance: int, social_aid: int, paid: int, outstanding: int}
     */
    private function coverage(DashboardPeriod $period): array
    {
        $invoices = Invoice::query()
            ->whereNotNull('insurer_id')
            ->where('status', '!=', Invoice::STATUS_CANCELLED)
            ->whereBetween('created_at', $period->range())
            ->with('insurer')
            ->get();

        return [
            'insurance' => (int) $invoices->filter(fn (Invoice $i): bool => $i->insurer?->kind === Insurer::KIND_INSURANCE)->sum('insurer_share'),
            'social_aid' => (int) $invoices->filter(fn (Invoice $i): bool => $i->insurer?->kind === Insurer::KIND_SOCIAL_AID)->sum('insurer_share'),
            'paid' => (int) $invoices->sum('insurer_paid'),
            'outstanding' => (int) $invoices->sum(fn (Invoice $i): int => $i->insurerOutstanding()),
        ];
    }

    /**
     * Ce qu'on attend et ce qu'on doit, à l'instant où l'on regarde : sans
     * période, ces montants sont des situations, pas des flux.
     *
     * @return array{patients: int, insurers: int, accounts: int, refunds: int}
     */
    private function dues(): array
    {
        $live = Invoice::query()->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED]);

        $patients = (int) (clone $live)->get()->sum(fn (Invoice $invoice): int => $invoice->balance());
        $insurers = (int) (clone $live)->whereNotNull('insurer_id')->get()->sum(fn (Invoice $invoice): int => $invoice->insurerOutstanding());

        $deposited = (int) PatientDeposit::query()->valid()->sum('amount');
        $used = (int) Payment::query()->valid()
            ->whereHas('method', fn ($query) => $query->where('kind', PaymentMethod::KIND_PATIENT_ACCOUNT))
            ->sum('amount');
        $given = (int) Refund::query()->where('source', Refund::SOURCE_ACCOUNT)->where('status', Refund::STATUS_PAID)->sum('amount');

        return [
            'patients' => $patients,
            'insurers' => $insurers,
            // Le solde de tous les comptes patients : de l'argent encaissé
            // qui reste dû à quelqu'un.
            'accounts' => $deposited - $used - $given,
            'refunds' => (int) Refund::query()->toPay()->sum('amount'),
        ];
    }

    /**
     * Ce qui attend une décision ou un geste, maintenant.
     *
     * @return array{sessions: int, discounts: int, refunds: int, refunds_to_pay: int}
     */
    private function todo(): array
    {
        return [
            'sessions' => CashSession::query()->where('status', CashSession::STATUS_CLOSED)->count(),
            'discounts' => Discount::query()->where('status', Discount::STATUS_REQUESTED)->count(),
            'refunds' => Refund::query()->where('status', Refund::STATUS_REQUESTED)->count(),
            'refunds_to_pay' => Refund::query()->toPay()->count(),
        ];
    }

    /**
     * Total encaissé par jour, du plus ancien au plus récent. `share` est la
     * hauteur de la barre en pourcentage du maximum : la vue n'a aucun calcul
     * à faire.
     *
     * @return list<array{label: string, amount: int, share: int, last: bool}>
     */
    private function series(DashboardPeriod $period): array
    {
        $start = $period->seriesFrom();

        $totals = Payment::query()->valid()
            ->whereBetween('created_at', [$start, $period->to])
            ->get(['created_at', 'amount'])
            ->groupBy(fn (Payment $payment): string => $payment->created_at->toDateString())
            ->map(fn ($group): int => (int) $group->sum('amount'));

        $days = [];

        for ($step = 0; $step < $period->seriesDays; $step++) {
            $day = $start->copy()->addDays($step);

            $days[] = [
                'label' => $day->translatedFormat('d M'),
                'amount' => (int) ($totals[$day->toDateString()] ?? 0),
                'share' => 0,
                'last' => $step === $period->seriesDays - 1,
            ];
        }

        $peak = max(array_column($days, 'amount'));

        if ($peak > 0) {
            foreach ($days as $index => $day) {
                $days[$index]['share'] = (int) round($day['amount'] / $peak * 100);
            }
        }

        return $days;
    }

    /**
     * Répartition des encaissements par moyen de paiement sur la période.
     *
     * @return list<array{name: string, amount: int, percent: int, color: string}>
     */
    private function split(DashboardPeriod $period): array
    {
        $rows = DB::table('finance_payments as p')
            ->join('finance_payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->where('p.status', Payment::STATUS_VALID)
            ->whereBetween('p.created_at', $period->range())
            ->groupBy('m.id', 'm.name')
            ->selectRaw('m.name as name, SUM(p.amount) as total')
            ->orderByDesc('total')
            ->get();

        $total = (int) $rows->sum('total');

        if ($total === 0) {
            return [];
        }

        // Palette sobre, dans l'ordre décroissant des montants.
        $palette = ['#0ea5e9', '#2563eb', '#f59e0b', '#14b8a6', '#8b5cf6', '#94a3b8'];

        return $rows->values()->map(fn ($row, $index): array => [
            'name' => (string) $row->name,
            'amount' => (int) $row->total,
            'percent' => (int) round((int) $row->total / $total * 100),
            'color' => $palette[$index % count($palette)],
        ])->all();
    }

    /**
     * Les règlements d'assureurs reçus dans la période : de l'argent entré,
     * hors tiroir.
     *
     * @param  array{0: Carbon, 1: Carbon}  $range
     */
    private function settlements(array $range): int
    {
        return (int) InsuranceSettlement::query()
            // whereDate : la colonne peut porter une heure (SQLite), la borne non.
            ->whereDate('received_on', '>=', $range[0]->toDateString())
            ->whereDate('received_on', '<=', $range[1]->toDateString())
            ->sum('amount');
    }

    /**
     * @param  Builder<Payment|Disbursement|PatientDeposit>  $query
     * @param  array{0: Carbon, 1: Carbon}  $range
     */
    private function sum($query, array $range): int
    {
        return (int) $query->valid()->whereBetween('created_at', $range)->sum('amount');
    }

    /**
     * Variation en pourcentage, ou null quand la période précédente est à
     * zéro : « +∞ % » ne dit rien à personne.
     */
    private function trend(int $now, int $before): ?int
    {
        if ($before === 0) {
            return null;
        }

        return (int) round(($now - $before) / $before * 100);
    }
}
