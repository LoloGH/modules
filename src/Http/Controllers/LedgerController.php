<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\LedgerFilters;

/**
 * Les écritures réelles de caisse, en lecture, avec des filtres communs
 * (période, moyen, statut, recherche).
 *
 * Un caissier ne voit que les mouvements de ses sessions ; qui contrôle les
 * caisses (`finance.sessions.validate`) voit tout l'établissement.
 */
final class LedgerController extends FinanceController
{
    private const PER_PAGE = 50;

    public function payments(Request $request): View
    {
        $filters = $this->filters($request);
        $base = $filters->payments();
        $valid = (clone $base)->where('status', Payment::STATUS_VALID);

        return view('finance::ledger.payments', [
            'filters' => $filters,
            'payments' => (clone $base)->with(['method', 'act', 'invoice', 'session.register'])
                ->latest('created_at')->latest('id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'stats' => [
                'total' => (int) (clone $valid)->sum('amount'),
                'count' => (clone $valid)->count(),
                'cancelled' => (clone $base)->where('status', Payment::STATUS_CANCELLED)->count(),
            ],
            // Ce qu'a rapporté chaque moyen sur la période : une ligne, pas un
            // tableau de plus.
            'byMethod' => (clone $valid)->selectRaw('payment_method_id, SUM(amount) as total')
                ->groupBy('payment_method_id')->pluck('total', 'payment_method_id')
                ->map(fn ($total) => (int) $total)->sortDesc(),
            'methods' => PaymentMethod::query()->orderBy('name')->get(),
            'scoped' => $filters->cashierScope !== null,
        ]);
    }

    /**
     * Recettes : les encaissements VALIDES, vus par ce qu'ils rapportent —
     * source (acte ou libellé), service (centre analytique), moyen.
     */
    public function revenue(Request $request): View
    {
        $filters = $this->filters($request);
        $base = $filters->payments()->where('status', Payment::STATUS_VALID);

        $byCenter = (clone $base)->with('act.center')->get()
            ->groupBy(fn (Payment $p): string => $p->act?->center?->name ?? ($p->act === null ? 'Hors catalogue' : 'Sans centre analytique'))
            ->map(fn ($group): int => (int) $group->sum('amount'))
            ->sortDesc();

        return view('finance::ledger.revenue', [
            'filters' => $filters,
            'items' => (clone $base)->with(['method', 'act.center', 'session.register'])
                ->latest('created_at')->latest('id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'total' => (int) (clone $base)->sum('amount'),
            'count' => (clone $base)->count(),
            'byCenter' => $byCenter,
            'methods' => PaymentMethod::query()->orderBy('name')->get(),
            'centers' => AnalyticCenter::query()->orderBy('name')->get(),
            'acts' => Act::query()->orderBy('name')->get(),
            'scoped' => $filters->cashierScope !== null,
        ]);
    }

    /**
     * Dépenses : les décaissements, avec leur catégorie, leur bénéficiaire et
     * leur statut.
     */
    public function expenses(Request $request): View
    {
        $filters = $this->filters($request);
        $categories = Disbursement::categories();

        $category = $request->query('categorie');
        $category = is_string($category) && ($category === 'aucune' || isset($categories[$category])) ? $category : null;

        $base = $filters->disbursements()
            ->when($category === 'aucune', fn ($q) => $q->whereNull('category'))
            ->when($category !== null && $category !== 'aucune', fn ($q) => $q->where('category', $category));
        $valid = (clone $base)->where('status', Disbursement::STATUS_VALID);

        return view('finance::ledger.expenses', [
            'filters' => $filters,
            'category' => $category,
            'categories' => $categories,
            'items' => (clone $base)->with(['method', 'session.register'])
                ->latest('created_at')->latest('id')
                ->paginate(self::PER_PAGE)->withQueryString(),
            'stats' => [
                'total' => (int) (clone $valid)->sum('amount'),
                'count' => (clone $valid)->count(),
                'cancelled' => (clone $base)->where('status', Disbursement::STATUS_CANCELLED)->count(),
            ],
            'byCategory' => (clone $valid)->get(['category', 'amount'])
                ->groupBy(fn (Disbursement $d): string => $d->categoryLabel())
                ->map(fn ($group): int => (int) $group->sum('amount'))
                ->sortDesc(),
            'methods' => PaymentMethod::query()->orderBy('name')->get(),
            'scoped' => $filters->cashierScope !== null,
        ]);
    }

    private function filters(Request $request): LedgerFilters
    {
        $user = $this->user($request);

        // Le contrôle voit toutes les caisses ; un caissier, les siennes.
        $scope = Gate::forUser($user)->allows('finance.sessions.validate') ? null : Actor::id($user);

        return LedgerFilters::fromRequest($request, $scope);
    }
}
