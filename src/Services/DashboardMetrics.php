<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Payment;

/**
 * Chiffres du tableau de bord.
 *
 * Lecture seule, et uniquement à partir de ce que la base contient déjà :
 * encaissements, décaissements et sessions de caisse. Aucune estimation,
 * aucune donnée de remplissage — un écran vide vaut mieux qu'un chiffre faux.
 *
 * Les opérations annulées sont exclues partout, comme dans
 * `CashSessionCalculator`.
 */
final class DashboardMetrics
{
    /** Nombre de jours de l'historique affiché. */
    public const WINDOW = 7;

    /**
     * @return array{
     *     income_today: int, income_yesterday: int, income_trend: ?int,
     *     outflow_today: int, outflow_yesterday: int, outflow_trend: ?int,
     *     operations_today: int,
     *     open_sessions: int, sessions_to_validate: int,
     *     window_total: int,
     *     series: list<array{label: string, amount: int, share: int, last: bool}>,
     *     split: list<array{name: string, amount: int, percent: int, color: string}>
     * }
     */
    public function overview(): array
    {
        $today = Carbon::today();
        $yesterday = $today->copy()->subDay();

        $incomeToday = $this->sum(Payment::query(), $today);
        $incomeYesterday = $this->sum(Payment::query(), $yesterday);
        $outflowToday = $this->sum(Disbursement::query(), $today);
        $outflowYesterday = $this->sum(Disbursement::query(), $yesterday);

        $series = $this->series($today);

        return [
            'income_today' => $incomeToday,
            'income_yesterday' => $incomeYesterday,
            'income_trend' => $this->trend($incomeToday, $incomeYesterday),

            'outflow_today' => $outflowToday,
            'outflow_yesterday' => $outflowYesterday,
            'outflow_trend' => $this->trend($outflowToday, $outflowYesterday),

            'operations_today' => Payment::query()->valid()->whereBetween('created_at', $this->day($today))->count(),

            'open_sessions' => CashSession::query()->open()->count(),
            'sessions_to_validate' => CashSession::query()->where('status', CashSession::STATUS_CLOSED)->count(),

            'window_total' => array_sum(array_column($series, 'amount')),
            'series' => $series,
            'split' => $this->split($today),
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
     * Total encaissé par jour sur la fenêtre, du plus ancien au plus récent.
     * `share` est la hauteur de la barre en pourcentage du maximum : la vue
     * n'a aucun calcul à faire.
     *
     * @return list<array{label: string, amount: int, share: int, last: bool}>
     */
    private function series(Carbon $today): array
    {
        $days = [];

        for ($back = self::WINDOW - 1; $back >= 0; $back--) {
            $day = $today->copy()->subDays($back);

            $days[] = [
                'label' => $day->translatedFormat('d M'),
                'amount' => $this->sum(Payment::query(), $day),
                'share' => 0,
                'last' => $back === 0,
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
     * Répartition des encaissements par moyen de paiement sur la fenêtre.
     *
     * @return list<array{name: string, amount: int, percent: int, color: string}>
     */
    private function split(Carbon $today): array
    {
        $rows = DB::table('finance_payments as p')
            ->join('finance_payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->where('p.status', Payment::STATUS_VALID)
            ->whereBetween('p.created_at', [
                $today->copy()->subDays(self::WINDOW - 1)->startOfDay(),
                $today->copy()->endOfDay(),
            ])
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
     * @param  Builder<Payment|Disbursement>  $query
     */
    private function sum($query, Carbon $day): int
    {
        return (int) $query->valid()->whereBetween('created_at', $this->day($day))->sum('amount');
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function day(Carbon $day): array
    {
        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
    }

    /**
     * Variation en pourcentage, ou null quand la veille est à zéro : « +∞ % »
     * ne dit rien à personne.
     */
    private function trend(int $now, int $before): ?int
    {
        if ($before === 0) {
            return null;
        }

        return (int) round(($now - $before) / $before * 100);
    }
}
