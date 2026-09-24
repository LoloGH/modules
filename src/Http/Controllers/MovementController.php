<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\CollectQueuedVisit;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\PayRefund;
use Keneya\FinanceCaisse\Actions\RecordDeposit;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Http\Requests\CancelMovementRequest;
use Keneya\FinanceCaisse\Http\Requests\DepositRequest;
use Keneya\FinanceCaisse\Http\Requests\DisbursementRequest;
use Keneya\FinanceCaisse\Http\Requests\PaymentRequest;
use Keneya\FinanceCaisse\Http\Requests\RefundPaymentRequest;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\PatientDeposit;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Models\Refund;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Encaissements et décaissements d'une session, et leur annulation.
 */
final class MovementController extends FinanceController
{
    public function storePayment(PaymentRequest $request, CashSession $session, RecordPayment $action, CollectQueuedVisit $collect, CreateInvoice $invoices): RedirectResponse
    {
        // Avec une prise en charge : une facture de l'acte, prise en charge,
        // et l'encaissement de la part patient qui s'y rattache, ensemble,
        // ou pas du tout. Sans elle, pas de transaction englobante : l'échec
        // d'un encaissement venu de la file doit garder sa trace d'audit.
        if (empty($request->validated('insurer_id'))) {
            return $this->recordPayment($request, $session, $action, $collect, $invoices);
        }

        return DB::transaction(fn (): RedirectResponse => $this->recordPayment($request, $session, $action, $collect, $invoices));
    }

    private function recordPayment(PaymentRequest $request, CashSession $session, RecordPayment $action, CollectQueuedVisit $collect, CreateInvoice $invoices): RedirectResponse
    {
        $data = $request->validated();
        $fromInvoice = isset($data['invoice_id']);
        $covered = null;

        if (! empty($data['insurer_id'])) {
            if (empty($data['act_id'])) {
                throw new FinanceRuleViolation("Choisissez l'acte encaissé : la prise en charge s'applique à un acte.");
            }

            $covered = $invoices->handle(
                $data['patient_id'] ?? null,
                $data['patient_name'] ?? null,
                [['act_id' => (int) $data['act_id'], 'quantity' => 1]],
                null,
                $this->user($request),
                ['insurer_id' => (int) $data['insurer_id'], 'policy_number' => $data['policy_number'] ?? null],
            );

            $data['invoice_id'] = $covered->id;
        }

        $method = PaymentMethod::query()->findOrFail((int) $data['payment_method_id']);
        $details = [
            'reference' => $data['reference'] ?? null,
            'patient_id' => $data['patient_id'] ?? null,
            'patient_name' => $data['patient_name'] ?? null,
            'description' => $data['description'] ?? null,
            'act_id' => isset($data['act_id']) ? (int) $data['act_id'] : null,
            'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
        ];

        // Venu de la file avec une prise en charge : le patient paie sa part.
        if ($covered !== null && isset($data['visit_ref'], $data['queue_ref'])) {
            $details['invoice_id'] = $covered->id;
        }

        // Un patient appelé depuis la file : l'encaissement fait aussi avancer
        // sa visite chez l'hôte, ou rien n'est enregistré.
        if (isset($data['visit_ref'], $data['queue_ref'])) {
            $payment = $collect->handle(
                $session, $method, (int) $data['amount'], $this->user($request),
                (string) $data['queue_ref'], (string) $data['visit_ref'], $details,
            );

            return redirect()
                ->route('finance.queue.index', ['file' => $data['queue_ref'], 'session' => $session->id])
                ->with('finance_print', $this->receipt(route('finance.cash.payments.receipt', $payment), 'Imprimer le reçu'))
                ->with('finance_status', sprintf(
                    'Encaissement %s enregistré : %s. %s poursuit son parcours.',
                    $payment->number,
                    Money::format($payment->amount),
                    $payment->patient_name ?? 'Le patient',
                ));
        }

        $payment = $action->handle($session, $method, (int) $data['amount'], $this->user($request), $details);

        // Réglée sur facture : on revient à la facture, qui montre son solde.
        if ($fromInvoice) {
            return redirect()->route('finance.invoices.show', $payment->invoice_id)
                ->with('finance_print', $this->receipt(route('finance.cash.payments.receipt', $payment), 'Imprimer le reçu'))
                ->with('finance_status', sprintf(
                    'Encaissement %s enregistré : %s. Solde de la facture : %s.',
                    $payment->number,
                    Money::format($payment->amount),
                    Money::format($payment->invoice->fresh()->balance()),
                ));
        }

        return redirect()
            ->route('finance.cash.sessions.show', $session)
            ->with('finance_print', $this->receipt(route('finance.cash.payments.receipt', $payment), 'Imprimer le reçu'))
            ->with('finance_status', $covered === null
                ? sprintf('Encaissement %s enregistré : %s.', $payment->number, Money::format($payment->amount))
                : sprintf(
                    'Encaissement %s enregistré : %s (part patient). %s prend en charge %s, suivi sur la facture %s.',
                    $payment->number,
                    Money::format($payment->amount),
                    $covered->insurer?->name,
                    Money::format($covered->insurer_share),
                    $covered->number,
                ));
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
                'category' => $data['category'] ?? null,
                'analytic_center_id' => isset($data['analytic_center_id']) ? (int) $data['analytic_center_id'] : null,
            ],
        );

        return redirect()
            ->route('finance.cash.sessions.show', $session)
            ->with('finance_print', $this->receipt(route('finance.cash.disbursements.receipt', $disbursement), 'Imprimer le bon de décaissement'))
            ->with('finance_status', sprintf('Décaissement %s enregistré : %s.', $disbursement->number, Money::format($disbursement->amount)));
    }

    /**
     * Une avance : de l'argent reçu d'avance, qui entre dans le tiroir sans
     * être une recette.
     */
    public function storeDeposit(DepositRequest $request, CashSession $session, RecordDeposit $action): RedirectResponse
    {
        $data = $request->validated();

        $deposit = $action->handle(
            $session,
            PaymentMethod::query()->findOrFail((int) $data['payment_method_id']),
            (int) $data['amount'],
            (string) $data['patient_id'],
            $this->user($request),
            [
                'patient_name' => $data['patient_name'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
            ],
        );

        return redirect()
            ->route('finance.cash.sessions.show', $session)
            ->with('finance_print', $this->receipt(route('finance.cash.deposits.receipt', $deposit), 'Imprimer le reçu d\'avance'))
            ->with('finance_status', sprintf('Avance %s enregistrée : %s.', $deposit->number, Money::format($deposit->amount)));
    }

    /**
     * Payer un remboursement approuvé : l'argent sort du tiroir, par un
     * décaissement ordinaire qui reste attaché au remboursement.
     */
    public function payRefund(RefundPaymentRequest $request, CashSession $session, Refund $refund, PayRefund $action): RedirectResponse
    {
        $paid = $action->handle(
            $refund,
            $session,
            PaymentMethod::query()->findOrFail((int) $request->validated('payment_method_id')),
            $this->user($request),
        );

        return redirect()
            ->route('finance.cash.sessions.show', $session)
            ->with('finance_print', $this->receipt(route('finance.cash.disbursements.receipt', $paid->disbursement_id), 'Imprimer le bon de remboursement'))
            ->with('finance_status', sprintf('Remboursement %s payé : %s.', $paid->number, Money::format((int) $paid->amount)));
    }

    public function cancelDeposit(CancelMovementRequest $request, PatientDeposit $deposit, CancelCashMovement $action): RedirectResponse
    {
        $cancelled = $action->deposit($deposit, (string) $request->validated('reason'), $this->user($request));

        return redirect()
            ->route('finance.cash.sessions.show', $cancelled->cash_session_id)
            ->with('finance_status', "Avance {$cancelled->number} annulée.");
    }

    /**
     * Le document à imprimer juste après l'opération ; l'écran d'arrivée
     * l'affiche sous forme de bouton.
     *
     * @return array{url: string, label: string}
     */
    private function receipt(string $url, string $label): array
    {
        return ['url' => $url.'?auto=1', 'label' => $label];
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
