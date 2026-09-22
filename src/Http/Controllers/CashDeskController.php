<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\OpenCashSession;
use Keneya\FinanceCaisse\Actions\OpenCashSessions;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Http\Requests\CloseSessionRequest;
use Keneya\FinanceCaisse\Http\Requests\OpenSessionRequest;
use Keneya\FinanceCaisse\Http\Requests\OpenSessionsRequest;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Queue\QueuedVisit;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Le bureau du caissier : ouvrir ses sessions, les suivre, les clôturer.
 *
 * Un caissier peut tenir plusieurs caisses à la fois si l'établissement
 * l'autorise (voir `finance.cash.max_open_sessions_per_cashier`). Avec la
 * limite à 1 — le cas courant — le bureau se comporte comme avant : une
 * session ouverte mène directement à sa page.
 */
final class CashDeskController extends FinanceController
{
    public function index(Request $request): View|RedirectResponse
    {
        $cashierId = Actor::id($this->user($request));

        $open = CashSession::query()->open()->with('register')
            ->where('cashier_id', $cashierId)
            ->orderBy('id')
            ->get();

        $limit = CashierSetting::limitFor($cashierId);

        // Un caissier, un tiroir : on l'amène droit à sa session, comme avant.
        if ($limit === 1 && $open->count() === 1) {
            return redirect()->route('finance.cash.sessions.show', $open->first());
        }

        // Liste vide = aucune restriction, donc toutes les caisses.
        $assigned = CashierRegister::assignedIdsFor($cashierId);

        return view('finance::cash.index', [
            'openSessions' => $open,
            'limit' => $limit,
            'canOpenMore' => $open->count() < $limit,
            // Combien de caisses il peut encore ouvrir : au-delà d'une, le
            // bureau propose de les ouvrir toutes en une fois.
            'remaining' => max(0, $limit - $open->count()),
            // On ne propose que les caisses libres ET auxquelles il est
            // affecté : une caisse déjà tenue ne s'ouvre pas deux fois, pas
            // même par son propre caissier.
            'registers' => CashRegister::query()->active()
                ->whereDoesntHave('sessions', fn ($query) => $query->where('status', CashSession::STATUS_OPEN))
                ->when($assigned !== [], fn ($query) => $query->whereIn('id', $assigned))
                ->get(),
            'isRestricted' => $assigned !== [],
            'recent' => CashSession::query()->with('register')
                ->where('cashier_id', $cashierId)
                ->latest('id')->limit(10)->get(),
        ]);
    }

    public function open(OpenSessionRequest $request, OpenCashSession $action): RedirectResponse
    {
        $register = CashRegister::query()->findOrFail((int) $request->validated('cash_register_id'));

        $session = $action->handle($register, $this->user($request), (int) $request->validated('opening_float'));

        return redirect()
            ->route('finance.cash.sessions.show', $session)
            ->with('finance_status', "Session {$session->number} ouverte sur {$register->name}.");
    }

    /**
     * Plusieurs caisses d'un coup : une session par caisse cochée, chacune avec
     * son fonds initial. Tout ou rien (voir `OpenCashSessions`).
     */
    public function openMany(OpenSessionsRequest $request, OpenCashSessions $action): RedirectResponse
    {
        $sessions = $action->handle($request->floatsByRegister(), $this->user($request));

        $total = array_sum(array_map(static fn (CashSession $session): int => (int) $session->opening_float, $sessions));

        return redirect()->route('finance.cash.index')->with('finance_status', sprintf(
            '%d session(s) ouverte(s) : %s. Fonds initial total : %s.',
            count($sessions),
            collect($sessions)->map(fn (CashSession $session): string => $session->register->name)->implode(', '),
            Money::format($total),
        ));
    }

    public function show(Request $request, CashSession $session, CashSessionCalculator $calculator): View
    {
        $user = $this->user($request);

        $isOwner = (string) $session->cashier_id === Actor::id($user);
        $canReview = Gate::forUser($user)->allows('finance.sessions.validate');

        // Un caissier ne voit que ses sessions ; le contrôle voit toutes.
        abort_unless($isOwner || $canReview, 403);

        $session->load('register');

        // Ouverte : calcul en direct. Clôturée : les totaux figés à la clôture.
        $totals = $session->isOpen()
            ? $calculator->totals($session)
            : ($session->totals ?? $calculator->totals($session));

        $movements = $session->payments()->with(['method', 'act'])->get()
            ->map(fn (Payment $payment): array => ['is_payment' => true, 'model' => $payment])
            ->concat(
                $session->disbursements()->with('method')->get()
                    ->map(fn (Disbursement $disbursement): array => ['is_payment' => false, 'model' => $disbursement])
            )
            ->sortByDesc(fn (array $movement): array => [$movement['model']->created_at->getTimestamp(), $movement['model']->id])
            ->values();

        return view('finance::cash.session', [
            'session' => $session,
            // Venu de la file : « Encaisser » pré-remplit patient, acte et
            // montant. Le caissier relit et valide ; rien n'est enregistré ici.
            'fromQueue' => $session->isOpen() && $isOwner ? $this->queuedVisit($request) : null,
            'totals' => $totals,
            'movements' => $movements,
            'methods' => PaymentMethod::query()->active()->get(),
            // Le motif d'encaissement : le catalogue des actes, avec leur
            // centre et leur tarif standard du jour.
            'acts' => Act::query()->active()->with(['center', 'standardTariff'])->get(),
            'isOwner' => $isOwner,
            'canReview' => $canReview,
            // Tous ses tiroirs ouverts, celui-ci compris, pour basculer sans
            // repasser par le bureau. La vue marque celui qu'on regarde.
            'openSessions' => $isOwner
                ? CashSession::query()->open()->with('register')
                    ->where('cashier_id', $session->cashier_id)
                    ->orderBy('id')->get()
                : collect(),
        ]);
    }

    /**
     * La visite appelée depuis la file, si l'URL en désigne une qui attend
     * encore un encaissement. Une référence périmée ne pré-remplit rien.
     */
    private function queuedVisit(Request $request): ?QueuedVisit
    {
        $queue = $request->query('file');
        $visit = $request->query('visite');

        if (! is_string($queue) || $queue === '' || ! is_string($visit) || $visit === '') {
            return null;
        }

        return Finance::cashQueue()->findVisit($queue, $visit);
    }

    public function close(CloseSessionRequest $request, CashSession $session, CloseCashSession $action): RedirectResponse
    {
        $closed = $action->handle(
            $session,
            (int) $request->validated('counted_cash'),
            $this->user($request),
            $request->validated('variance_reason'),
        );

        return redirect()
            ->route('finance.cash.sessions.show', $closed)
            ->with('finance_status', sprintf('Session %s clôturée. Écart : %s.', $closed->number, Money::format((int) $closed->variance)));
    }
}
