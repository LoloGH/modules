<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Keneya\FinanceCaisse\Actions\ValidateCashSession;
use Keneya\FinanceCaisse\Http\Requests\ApproveSessionRequest;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Le contrôle : sessions à valider, et validation.
 */
final class ReviewController extends FinanceController
{
    private const FILTERS = [CashSession::STATUS_CLOSED, CashSession::STATUS_VALIDATED, CashSession::STATUS_OPEN, 'all'];

    public function index(Request $request): View
    {
        $status = (string) $request->query('status', CashSession::STATUS_CLOSED);
        $status = in_array($status, self::FILTERS, true) ? $status : CashSession::STATUS_CLOSED;

        $sessions = CashSession::query()
            ->with('register')
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->limit(100)
            ->get();

        return view('finance::review.index', [
            'lignes' => $this->parTiroir($sessions),
            'status' => $status,
        ]);
    }

    public function approve(ApproveSessionRequest $request, CashSession $session, ValidateCashSession $action): RedirectResponse
    {
        $validated = $action->handle($session, $this->user($request), $request->validated('note'));

        // Un tiroir commun se valide d'un coup : le message nomme ce qui est
        // parti avec, pour que le controle sache qu'il n'a plus rien a reprendre.
        $tiroir = $validated->drawerSessions();

        return redirect()
            ->route('finance.review.index')
            ->with('finance_status', $tiroir->count() > 1
                ? sprintf(
                    'Tiroir validé avec ses %d caisses : %s.',
                    $tiroir->count(),
                    $tiroir->load('register')->pluck('register.name')->implode(', '),
                )
                : "Session {$validated->number} validée.");
    }

    /**
     * Une ligne par TIROIR, et non par caisse.
     *
     * Un tiroir commun se compte une fois et se clôture d'un coup : il n'a
     * qu'un théorique, qu'un compté, qu'un écart. En donner trois lignes, dont
     * deux à zéro, faisait croire à trois contrôles à mener, et laissait
     * craindre qu'on en valide un en oubliant les autres.
     *
     * Les sessions d'un même tiroir partagent toujours leur statut, puisqu'elles
     * se ferment et se valident ensemble : regrouper une liste filtrée par
     * statut ne mélange donc rien.
     *
     * @param  Collection<int, CashSession>  $sessions
     * @return Collection<int, array<string, mixed>>
     */
    private function parTiroir(Collection $sessions): Collection
    {
        return $sessions
            ->groupBy(fn (CashSession $session): string => $session->drawer_key ?? 'seule:'.$session->getKey())
            ->map(function (Collection $tiroir): array {
                $premiere = $tiroir->sortBy('id')->first();

                return [
                    'session' => $premiere,
                    'groupe' => $tiroir->count() > 1,
                    'caisses' => $tiroir->sortBy('id')->pluck('register.name')->implode(', '),
                    'nombre' => $tiroir->count(),
                    // Les colonnes d'argent sont celles du tiroir : la somme
                    // des parts rend exactement ce qui a été compté.
                    'expected_cash' => $tiroir->contains(fn (CashSession $s): bool => $s->expected_cash === null)
                        ? null
                        : (int) $tiroir->sum('expected_cash'),
                    'counted_cash' => $tiroir->contains(fn (CashSession $s): bool => $s->counted_cash === null)
                        ? null
                        : (int) $tiroir->sum('counted_cash'),
                    'variance' => $tiroir->contains(fn (CashSession $s): bool => $s->variance === null)
                        ? null
                        : (int) $tiroir->sum('variance'),
                ];
            })
            ->values();
    }
}
