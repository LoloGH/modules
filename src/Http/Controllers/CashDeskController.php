<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\OpenCashSession;
use Keneya\FinanceCaisse\Http\Requests\CloseSessionRequest;
use Keneya\FinanceCaisse\Http\Requests\OpenSessionRequest;
use Keneya\FinanceCaisse\Models\CashRegister;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Le bureau du caissier : ouvrir sa session, la suivre, la clôturer.
 */
final class CashDeskController extends FinanceController
{
    public function index(Request $request): View|RedirectResponse
    {
        $cashierId = Actor::id($this->user($request));

        $open = CashSession::query()->open()->where('cashier_id', $cashierId)->first();

        if ($open !== null) {
            return redirect()->route('finance.cash.sessions.show', $open);
        }

        return view('finance::cash.index', [
            'registers' => CashRegister::query()->active()->get(),
            'recent' => CashSession::query()->with('register')->where('cashier_id', $cashierId)->latest('id')->limit(10)->get(),
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

        $movements = $session->payments()->with('method')->get()
            ->map(fn (Payment $payment): array => ['is_payment' => true, 'model' => $payment])
            ->concat(
                $session->disbursements()->with('method')->get()
                    ->map(fn (Disbursement $disbursement): array => ['is_payment' => false, 'model' => $disbursement])
            )
            ->sortByDesc(fn (array $movement): array => [$movement['model']->created_at->getTimestamp(), $movement['model']->id])
            ->values();

        return view('finance::cash.session', [
            'session' => $session,
            'totals' => $totals,
            'movements' => $movements,
            'methods' => PaymentMethod::query()->active()->get(),
            'isOwner' => $isOwner,
            'canReview' => $canReview,
        ]);
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
