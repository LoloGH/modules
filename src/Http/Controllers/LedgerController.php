<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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

    private function filters(Request $request): LedgerFilters
    {
        $user = $this->user($request);

        // Le contrôle voit toutes les caisses ; un caissier, les siennes.
        $scope = Gate::forUser($user)->allows('finance.sessions.validate') ? null : Actor::id($user);

        return LedgerFilters::fromRequest($request, $scope);
    }
}
