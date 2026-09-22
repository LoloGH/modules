<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\Refund;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Services\PatientAccount;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Demander un remboursement : rendre au patient de l'argent déjà reçu.
 *
 * Trois origines, et jamais plus que ce que l'établissement a reçu :
 *
 *   - un ENCAISSEMENT précis, dans la limite de son montant ;
 *   - une FACTURE, dans la limite de ce qui y a été réglé ;
 *   - le SOLDE DU COMPTE du patient (une avance qu'il ne consommera pas).
 *
 * Les demandes déjà faites retiennent leur montant : deux demandes
 * successives ne rendent pas deux fois la même somme.
 */
final class RequestRefund
{
    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
        private readonly PatientAccount $accounts,
    ) {}

    /**
     * @param  array{payment_id?: ?int, invoice_id?: ?int, patient_id?: ?string, patient_name?: ?string}  $details
     */
    public function handle(string $source, int $amount, string $reason, Authenticatable $requester, array $details = []): Refund
    {
        if ($amount <= 0) {
            throw new FinanceRuleViolation('Le montant du remboursement doit être supérieur à zéro.');
        }

        $reason = Text::clean($reason);

        if ($reason === null) {
            throw new FinanceRuleViolation('Un remboursement doit avoir un motif.');
        }

        if (! array_key_exists($source, Refund::sourceLabels())) {
            throw new FinanceRuleViolation('Origine du remboursement inconnue.');
        }

        return DB::transaction(function () use ($source, $amount, $reason, $requester, $details): Refund {
            $attributes = match ($source) {
                Refund::SOURCE_PAYMENT => $this->fromPayment($details['payment_id'] ?? null, $amount),
                Refund::SOURCE_INVOICE => $this->fromInvoice($details['invoice_id'] ?? null, $amount),
                default => $this->fromAccount($details['patient_id'] ?? null, $amount),
            };

            $refund = Refund::create($attributes + [
                'number' => $this->numbers->next('refund'),
                'source' => $source,
                'amount' => $amount,
                'reason' => $reason,
                'status' => Refund::STATUS_REQUESTED,
                'patient_name' => Text::clean($details['patient_name'] ?? null) ?? ($attributes['patient_name'] ?? null),
                'requested_by_id' => Actor::id($requester),
                'requested_by_name' => Actor::name($requester),
            ]);

            $this->auditor->record(
                'refund_requested',
                $refund,
                sprintf('Remboursement %s demandé : %s (%s), motif : %s', $refund->number, Money::format($amount), $refund->sourceLabel(), $reason),
                [],
                ['amount' => $amount, 'source' => $source, 'patient_id' => $refund->patient_id, 'reason' => $reason],
                $requester,
            );

            return $refund;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fromPayment(?int $paymentId, int $amount): array
    {
        $payment = Payment::query()->whereKey($paymentId)->lockForUpdate()->first();

        if ($payment === null) {
            throw new FinanceRuleViolation('Choisissez l\'encaissement à rembourser.');
        }

        if ($payment->isCancelled()) {
            throw new FinanceRuleViolation("L'encaissement {$payment->number} est annulé : il n'y a rien à rembourser.");
        }

        $this->assertRoom(
            (int) $payment->amount - $this->claimed(Refund::SOURCE_PAYMENT, 'payment_id', $payment->id),
            $amount,
            "l'encaissement {$payment->number}",
        );

        return [
            'payment_id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'patient_id' => (string) ($payment->patient_id ?? ''),
            'patient_name' => $payment->patient_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromInvoice(?int $invoiceId, int $amount): array
    {
        $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();

        if ($invoice === null) {
            throw new FinanceRuleViolation('Choisissez la facture à rembourser.');
        }

        $this->assertRoom(
            (int) $invoice->paid - $this->claimed(Refund::SOURCE_INVOICE, 'invoice_id', $invoice->id),
            $amount,
            "la facture {$invoice->number}",
        );

        return [
            'invoice_id' => $invoice->id,
            'patient_id' => (string) ($invoice->patient_id ?? ''),
            'patient_name' => $invoice->patient_name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fromAccount(?string $patientId, int $amount): array
    {
        $patientId = Text::clean($patientId);

        if ($patientId === null) {
            throw new FinanceRuleViolation('Un remboursement sur compte doit désigner le patient.');
        }

        $this->assertRoom($this->accounts->available($patientId), $amount, 'le compte de ce patient');

        return ['patient_id' => $patientId];
    }

    private function assertRoom(int $room, int $amount, string $what): void
    {
        if ($amount > $room) {
            throw new FinanceRuleViolation(sprintf(
                'Il ne reste que %s à rembourser sur %s : %s ne peut pas être rendu.',
                Money::format(max(0, $room)),
                $what,
                Money::format($amount),
            ));
        }
    }

    /**
     * Ce qui est déjà demandé, approuvé ou payé sur la même origine.
     */
    private function claimed(string $source, string $column, int $id): int
    {
        return (int) Refund::query()
            ->where('source', $source)
            ->where($column, $id)
            ->whereIn('status', [Refund::STATUS_REQUESTED, Refund::STATUS_APPROVED, Refund::STATUS_PAID])
            ->sum('amount');
    }
}
