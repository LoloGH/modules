<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Support\Actor;

/**
 * Documents imprimables : la facture (A4), le reçu d'encaissement et le bon
 * de décaissement (ticket de caisse, 80 mm). Pages autonomes, sans le menu,
 * prêtes pour l'impression du navigateur.
 *
 * Un reçu se réimprime autant qu'il faut ; annulé, il porte la mention
 * « ANNULÉ ». Le caissier n'imprime que les mouvements de ses sessions, le
 * contrôle ceux de toutes.
 */
final class PrintController extends FinanceController
{
    public function invoice(Invoice $invoice): View
    {
        $invoice->load(['lines', 'insurer', 'payments' => fn ($query) => $query->where('status', Payment::STATUS_VALID)->with('method')]);

        return view('finance::print.invoice', ['invoice' => $invoice] + $this->facility());
    }

    public function payment(Request $request, Payment $payment): View
    {
        $payment->load(['session.register', 'method', 'act', 'invoice']);
        $this->assertCanSee($request, $payment->session);

        return view('finance::print.payment', ['payment' => $payment] + $this->facility());
    }

    public function disbursement(Request $request, Disbursement $disbursement): View
    {
        $disbursement->load(['session.register', 'method']);
        $this->assertCanSee($request, $disbursement->session);

        return view('finance::print.disbursement', ['disbursement' => $disbursement] + $this->facility());
    }

    private function assertCanSee(Request $request, CashSession $session): void
    {
        $user = $this->user($request);

        abort_unless(
            (string) $session->cashier_id === Actor::id($user) || Gate::forUser($user)->allows('finance.sessions.validate'),
            403,
        );
    }

    /**
     * @return array{facility: array<string, string>}
     */
    private function facility(): array
    {
        return ['facility' => Finance::facility()];
    }
}
