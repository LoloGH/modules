<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Annule une facture. Elle ne s'efface pas : elle reste visible, avec son
 * auteur et son motif. Tant qu'un encaissement valide y est rattaché, on
 * refuse — l'argent reçu doit d'abord être annulé ou remboursé.
 */
final class CancelInvoice
{
    public function __construct(private readonly Auditor $auditor) {}

    public function handle(Invoice $invoice, string $reason, Authenticatable $actor): Invoice
    {
        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new FinanceRuleViolation("L'annulation doit avoir un motif.");
        }

        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            $invoice = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

            if ($invoice->isClosed()) {
                throw new FinanceRuleViolation("La facture {$invoice->number} est déjà {$invoice->statusLabel()}.");
            }

            if ($invoice->payments()->where('status', Payment::STATUS_VALID)->exists()) {
                throw new FinanceRuleViolation(
                    "La facture {$invoice->number} a des encaissements : annulez-les d'abord, ou remboursez le patient."
                );
            }

            $before = $invoice->status;

            $invoice->update([
                'status' => Invoice::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by_id' => Actor::id($actor),
                'cancelled_by_name' => Actor::name($actor),
                'cancellation_reason' => $reason,
            ]);

            $this->auditor->record(
                'invoice_cancelled',
                $invoice,
                "Facture {$invoice->number} annulée : {$reason}",
                ['status' => $before],
                ['status' => Invoice::STATUS_CANCELLED, 'reason' => $reason],
                $actor,
            );

            return $invoice;
        });
    }
}
