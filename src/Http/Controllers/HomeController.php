<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Services\DashboardMetrics;
use Keneya\FinanceCaisse\Support\Actor;

/**
 * Tableau de bord du module. Contrôleur et non closure : une route à
 * closure empêche `route:cache`, donc `artisan optimize` chez l'hôte.
 *
 * Les chiffres ne s'affichent que pour qui a le droit de les lire. Sans
 * aucun droit, la page reste une page d'accueil : le module est monté, il
 * n'a rien à montrer à cette personne.
 */
final class HomeController extends FinanceController
{
    public function __invoke(Request $request, DashboardMetrics $metrics, CashSessionCalculator $calculator): View
    {
        $user = $request->user(config('finance.access.guard') ?: null);

        $gate = $user === null ? null : Gate::forUser($user);

        $canReadFigures = $gate !== null
            && ($gate->allows('finance.dashboard.view') || $gate->allows('finance.payments.view'));

        // La caisse du caissier connecté : la session ouverte qui lui
        // appartient, s'il en a une.
        $session = null;
        $totals = null;

        if ($user !== null && $gate->allows('finance.sessions.view')) {
            $session = CashSession::query()->open()->with('register')->where('cashier_id', Actor::id($user))->first();

            if ($session !== null) {
                $totals = $calculator->totals($session);
            }
        }

        return view('finance::home', [
            'facility' => (string) config('finance.facility.name'),
            'canReadFigures' => $canReadFigures,
            'metrics' => $canReadFigures ? $metrics->overview() : null,
            'latest' => $canReadFigures ? $metrics->latestPayments() : null,
            'session' => $session,
            'totals' => $totals,
            'window' => DashboardMetrics::WINDOW,
        ]);
    }
}
