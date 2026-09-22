<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Http\Requests\CancelMovementRequest;
use Keneya\FinanceCaisse\Http\Requests\DisbursementRequest;
use Keneya\FinanceCaisse\Http\Requests\PaymentRequest;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Encaissements et décaissements d'une session, et leur annulation.
 */
final class MovementController extends FinanceController
{
    public function storePayment(PaymentRequest $request, CashSession $session, RecordPayment $action): RedirectResponse
    {
        $data = $request->validated();

        $payment = $action->handle(
            $session,
            PaymentMethod::query()->findOrFail((int) $data['payment_method_id']),
            (int) $data['amount'],
            $this->user($request),
            [
                'reference' => $data['reference'] ?? null,
                'patient_id' => $data['patient_id'] ?? null,
                'patient_name' => $data['patient_name'] ?? null,
                'description' => $data['description'] ?? null,
                'act_id' => isset($data['act_id']) ? (int) $data['act_id'] : null,
            ],
        );

        return redirect()
            ->route('finance.cash.sessions.show', $session)
            ->with('finance_status', sprintf('Encaissement %s enregistré : %s.', $payment->number, Money::format($payment->amount)));
    }

    public function storeDisbursement(DisbursementRequest $request, CashSession $session, RecordDisbursement $action): RedirectResponse
    {
        $data = $request->validated();

        $disbursement = $action->handle(
            $session,
            PaymentMethod::query()->findOrFail((int) $data['payment_method_id']),
            (int) $data['amount'],
            (string) $data['reason'],
            $this->user($request),
            [
                'beneficiary' => $data['beneficiary'] ?? null,
                'reference' => $data['reference'] ?? null,
            ],
        );

        return redirect()
            ->route('finance.cash.sessions.show', $session)
            ->with('finance_status', sprintf('Décaissement %s enregistré : %s.', $disbursement->number, Money::format($disbursement->amount)));
    }

    public function cancelPayment(CancelMovementRequest $request, Payment $payment, CancelCashMovement $action): RedirectResponse
    {
        $cancelled = $action->payment($payment, (string) $request->validated('reason'), $this->user($request));

        return redirect()
            ->route('finance.cash.sessions.show', $cancelled->cash_session_id)
            ->with('finance_status', "Encaissement {$cancelled->number} annulé.");
    }

    public function cancelDisbursement(CancelMovementRequest $request, Disbursement $disbursement, CancelCashMovement $action): RedirectResponse
    {
        $cancelled = $action->disbursement($disbursement, (string) $request->validated('reason'), $this->user($request));

        return redirect()
            ->route('finance.cash.sessions.show', $cancelled->cash_session_id)
            ->with('finance_status', "Décaissement {$cancelled->number} annulé.");
    }
}
