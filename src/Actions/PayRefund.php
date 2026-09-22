<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Models\Refund;
use Keneya\FinanceCaisse\Support\Money;

/**
 * Payer un remboursement approuvé : l'argent sort du tiroir.
 *
 * Le paiement est un décaissement ordinaire, avec ses propres règles (session
 * ouverte, tenue par le caissier, espèces disponibles) : le remboursement ne
 * crée pas de chemin de sortie parallèle. Le décaissement lui reste attaché,
 * et une facture entièrement remboursée est marquée comme telle.
 */
final class PayRefund
{
    public function __construct(
        private readonly RecordDisbursement $disbursements,
        private readonly Auditor $auditor,
    ) {}

    public function handle(Refund $refund, CashSession $session, PaymentMethod $method, Authenticatable $cashier): Refund
    {
        return DB::transaction(function () use ($refund, $session, $method, $cashier): Refund {
            $refund = Refund::query()->whereKey($refund->getKey())->lockForUpdate()->firstOrFail();

            if (! $refund->isPayable()) {
                throw new FinanceRuleViolation(
                    "Le remboursement {$refund->number} n'est pas à payer : {$refund->statusLabel()}."
                );
            }

            $disbursement = $this->disbursements->handle(
                $session,
                $method,
                (int) $refund->amount,
                sprintf('Remboursement %s : %s', $refund->number, $refund->reason),
                $cashier,
                [
                    'beneficiary' => $refund->patient_name ?? $refund->patient_id,
                    'category' => 'remboursement',
                ],
            );

            $refund->update([
                'status' => Refund::STATUS_PAID,
                'disbursement_id' => $disbursement->id,
                'paid_at' => now(),
            ]);

            $this->markInvoiceRefunded($refund);

            $this->auditor->record(
                'refund_paid',
                $refund,
                sprintf('Remboursement %s payé : %s (décaissement %s)', $refund->number, Money::format((int) $refund->amount), $disbursement->number),
                ['status' => Refund::STATUS_APPROVED],
                ['status' => Refund::STATUS_PAID, 'disbursement' => $disbursement->number],
                $cashier,
            );

            return $refund;
        });
    }

    /**
     * Une facture dont tout ce qui a été réglé est rendu ne se lit plus comme
     * payée : elle est remboursée, et rien ne s'y encaisse plus.
     */
    private function markInvoiceRefunded(Refund $refund): void
    {
        if ($refund->invoice_id === null) {
            return;
        }

        $invoice = Invoice::query()->whereKey($refund->invoice_id)->lockForUpdate()->first();

        if ($invoice === null || $invoice->isClosed() || (int) $invoice->paid <= 0) {
            return;
        }

        $refunded = (int) Refund::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', Refund::STATUS_PAID)
            ->sum('amount');

        if ($refunded >= (int) $invoice->paid) {
            $invoice->update(['status' => Invoice::STATUS_REFUNDED]);
        }
    }
}
