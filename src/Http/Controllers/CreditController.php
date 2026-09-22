<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Keneya\FinanceCaisse\Actions\DecideDiscount;
use Keneya\FinanceCaisse\Actions\DecideRefund;
use Keneya\FinanceCaisse\Actions\RequestDiscount;
use Keneya\FinanceCaisse\Actions\RequestRefund;
use Keneya\FinanceCaisse\Http\Requests\DecisionRequest;
use Keneya\FinanceCaisse\Http\Requests\DiscountRequest;
use Keneya\FinanceCaisse\Http\Requests\RefundRequest;
use Keneya\FinanceCaisse\Models\Discount;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Refund;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Remises et remboursements : deux gestes qui coûtent de l'argent, demandés
 * par qui encaisse et tranchés par qui contrôle.
 *
 * Un seul écran, deux listes : ce qu'on renonce à réclamer (remises), et ce
 * qu'on rend (remboursements). Payer un remboursement se fait à la caisse,
 * comme tout ce qui sort du tiroir.
 */
final class CreditController extends FinanceController
{
    public function index(): View
    {
        return view('finance::credits.index', [
            'discounts' => Discount::query()->with('invoice')->latest('id')->limit(100)->get(),
            'refunds' => Refund::query()->with(['invoice', 'payment', 'disbursement'])->latest('id')->limit(100)->get(),
            // Les factures sur lesquelles une remise a encore un sens.
            'invoices' => Invoice::query()
                ->whereNotIn('status', [Invoice::STATUS_CANCELLED, Invoice::STATUS_REFUNDED])
                ->latest('id')->limit(200)->get()
                ->filter(fn (Invoice $invoice): bool => $invoice->balance() > 0 || (int) $invoice->paid > 0)
                ->values(),
            'sources' => Refund::sourceLabels(),
        ]);
    }

    public function storeDiscount(DiscountRequest $request, RequestDiscount $action): RedirectResponse
    {
        $invoice = Invoice::query()->findOrFail((int) $request->validated('invoice_id'));

        $discount = $action->handle(
            $invoice,
            (int) $request->validated('amount'),
            (string) $request->validated('reason'),
            $this->user($request),
        );

        return redirect()->route('finance.credits.index')->with('finance_status', sprintf(
            'Remise %s demandée sur la facture %s : %s. Elle attend une approbation.',
            $discount->number,
            $invoice->number,
            Money::format((int) $discount->amount),
        ));
    }

    public function decideDiscount(DecisionRequest $request, Discount $discount, DecideDiscount $action): RedirectResponse
    {
        $actor = $this->user($request);

        $decided = $request->approves()
            ? $action->approve($discount, $actor)
            : $action->refuse($discount, (string) $request->validated('reason'), $actor);

        return redirect()->route('finance.credits.index')->with('finance_status', sprintf(
            'Remise %s : %s.',
            $decided->number,
            strtolower($decided->statusLabel()),
        ));
    }

    public function storeRefund(RefundRequest $request, RequestRefund $action): RedirectResponse
    {
        $data = $request->validated();

        $refund = $action->handle(
            (string) $data['source'],
            (int) $data['amount'],
            (string) $data['reason'],
            $this->user($request),
            [
                'payment_id' => isset($data['payment_id']) ? (int) $data['payment_id'] : null,
                'invoice_id' => isset($data['invoice_id']) ? (int) $data['invoice_id'] : null,
                'patient_id' => $data['patient_id'] ?? null,
                'patient_name' => $data['patient_name'] ?? null,
            ],
        );

        return redirect()->route('finance.credits.index')->with('finance_status', sprintf(
            'Remboursement %s demandé : %s. Il attend une approbation.',
            $refund->number,
            Money::format((int) $refund->amount),
        ));
    }

    public function decideRefund(DecisionRequest $request, Refund $refund, DecideRefund $action): RedirectResponse
    {
        $actor = $this->user($request);

        $decided = $request->approves()
            ? $action->approve($refund, $actor)
            : $action->refuse($refund, (string) $request->validated('reason'), $actor);

        return redirect()->route('finance.credits.index')->with('finance_status', sprintf(
            'Remboursement %s : %s.',
            $decided->number,
            strtolower($decided->statusLabel()),
        ));
    }
}
