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
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\PatientDeposit;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Models\Refund;
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

        $drawers = CashSession::openDrawersFor($cashierId);

        // Un caissier, un tiroir, une caisse : on l'amène droit à sa session.
        if ($limit === 1 && $open->count() === 1) {
            return redirect()->route('finance.cash.sessions.show', $open->first());
        }

        // Liste vide = aucune restriction, donc toutes les caisses.
        $assigned = CashierRegister::assignedIdsFor($cashierId);

        return view('finance::cash.index', [
            'openSessions' => $open,
            'limit' => $limit,
            // La limite se compte en tiroirs : des caisses ouvertes ensemble
            // avec un seul fonds n'en font qu'un.
            'canOpenMore' => $drawers < $limit,
            'drawers' => $drawers,
            'remaining' => max(0, $limit - $drawers),
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
     * Plusieurs caisses d'un coup, groupées (un seul fonds, un tiroir) ou
     * séparées (un fonds par caisse). Une session par caisse, chacune avec sa
     * clôture. Tout ou rien (voir `OpenCashSessions`).
     */
    public function openMany(OpenSessionsRequest $request, OpenCashSessions $action): RedirectResponse
    {
        $user = $this->user($request);

        $sessions = $request->isGrouped()
            ? $action->grouped($request->registerIds(), (int) $request->validated('opening_float'), $user)
            : $action->separate($request->floatsByRegister(), $user);

        $names = collect($sessions)->map(fn (CashSession $session): string => $session->register->name)->implode(', ');
        $total = array_sum(array_map(static fn (CashSession $session): int => (int) $session->opening_float, $sessions));

        return redirect()->route('finance.cash.index')->with('finance_status', $request->isGrouped()
            ? sprintf('%d caisse(s) ouverte(s) ensemble : %s. Fonds initial unique de %s, porté par %s.', count($sessions), $names, Money::format($total), $sessions[0]->register->name)
            : sprintf('%d caisse(s) ouverte(s) séparément : %s. Fonds initiaux : %s au total.', count($sessions), $names, Money::format($total)));
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
            ->map(fn (Payment $payment): array => ['kind' => 'payment', 'model' => $payment])
            ->concat(
                $session->disbursements()->with('method')->get()
                    ->map(fn (Disbursement $disbursement): array => ['kind' => 'disbursement', 'model' => $disbursement])
            )
            ->concat(
                PatientDeposit::query()->where('cash_session_id', $session->getKey())->with('method')->get()
                    ->map(fn (PatientDeposit $deposit): array => ['kind' => 'deposit', 'model' => $deposit])
            )
            ->sortByDesc(fn (array $movement): array => [$movement['model']->created_at->getTimestamp(), $movement['model']->id])
            ->values();

        return view('finance::cash.session', [
            'session' => $session,
            // Venu de la file : « Encaisser » pré-remplit patient, acte et
            // montant. Le caissier relit et valide ; rien n'est enregistré ici.
            'fromQueue' => $fromQueue = ($session->isOpen() && $isOwner ? $this->queuedVisit($request) : null),
            // Venu d'une facture : patient et solde pré-remplis.
            'fromInvoice' => $fromQueue === null && $session->isOpen() && $isOwner ? $this->invoiceToCollect($request) : null,
            // La file d'où venait un encaissement qui n'a pas abouti (refusé, ou
            // lien périmé) : l'écran propose d'y retourner.
            'reopenQueue' => $fromQueue === null && $session->isOpen() && $isOwner
                ? $this->reopenQueueRef($request)
                : null,
            'totals' => $totals,
            'movements' => $movements,
            'methods' => PaymentMethod::query()->active()->get(),
            // Le motif d'encaissement : le catalogue des actes, avec leur
            // centre et leur tarif standard du jour.
            'acts' => $acts = Act::query()->active()->with(['center', 'standardTariff'])->get(),
            // Prises en charge proposables à l'encaissement, et leur taux par acte.
            'coverage' => Insurer::coverageMap($acts->pluck('id')),
            // Les remboursements approuvés attendent la caisse : c'est ici
            // qu'ils sortent du tiroir.
            'refunds' => $session->isOpen() && $isOwner
                ? Refund::query()->toPay()->latest('id')->get()
                : collect(),
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

    private function invoiceToCollect(Request $request): ?Invoice
    {
        $id = $request->query('facture');

        if (! is_string($id) || ! ctype_digit($id)) {
            return null;
        }

        $invoice = Invoice::query()->find((int) $id);

        return $invoice?->canBePaid() ? $invoice : null;
    }

    private function reopenQueueRef(Request $request): ?string
    {
        $failed = session()->has('finance_error') ? $request->old('queue_ref') : null;
        $stale = $request->query('visite') !== null ? $request->query('file') : null;
        $ref = $failed ?? $stale;

        return is_string($ref) && $ref !== '' ? $ref : null;
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
