<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Actions\Concerns\GuardsCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\NumberGenerator;
use Keneya\FinanceCaisse\Services\PatientAccount;
use Keneya\FinanceCaisse\Support\Money;
use Keneya\FinanceCaisse\Support\Text;

/**
 * Enregistre un encaissement dans la session ouverte de ce caissier.
 */
final class RecordPayment
{
    use GuardsCashSession;

    public function __construct(
        private readonly NumberGenerator $numbers,
        private readonly Auditor $auditor,
        private readonly PatientAccount $accounts,
    ) {}

    /**
     * @param  array{reference?: ?string, patient_id?: string|int|null, patient_name?: ?string, description?: ?string, invoice_id?: ?int, act_id?: ?int, host_visit_ref?: ?string}  $details
     */
    public function handle(
        CashSession $session,
        PaymentMethod $method,
        int $amount,
        Authenticatable $cashier,
        array $details = [],
    ): Payment {
        if ($amount <= 0) {
            throw new FinanceRuleViolation('Le montant doit être supérieur à zéro.');
        }

        return DB::transaction(function () use ($session, $method, $amount, $cashier, $details): Payment {
            $session = $this->lockSession($session);
            $this->assertOpen($session);
            $this->assertOwnedBy($session, $cashier);

            $method = PaymentMethod::query()->findOrFail($method->getKey());

            if (! $method->is_active) {
                throw new FinanceRuleViolation("Le moyen de paiement « {$method->name} » est désactivé.");
            }

            $reference = Text::clean($details['reference'] ?? null);

            if ($method->requires_reference && $reference === null) {
                throw new FinanceRuleViolation("Le moyen de paiement « {$method->name} » exige une référence.");
            }

            $patientId = $details['patient_id'] ?? null;

            // Réglé sur le compte du patient : l'argent est déjà dans le
            // tiroir depuis son avance. On ne puise que ce qui y reste.
            if ($method->kind === PaymentMethod::KIND_PATIENT_ACCOUNT) {
                $patientId = $this->assertAccountCovers($patientId, $details['invoice_id'] ?? null, $amount);
            }

            $act = $this->act($details['act_id'] ?? null);

            // Un encaissement sur facture : la facture doit pouvoir le
            // recevoir, sans dépasser ce qui reste dû.
            $invoice = $this->invoice($details['invoice_id'] ?? null, $amount);

            $payment = Payment::create([
                'number' => $this->numbers->next('payment'),
                'cash_session_id' => $session->id,
                'payment_method_id' => $method->id,
                'act_id' => $act?->id,
                // Le centre est gravé ici : rattacher l'acte ailleurs plus
                // tard ne déplace pas les recettes déjà encaissées.
                'analytic_center_id' => $act?->analytic_center_id,
                'amount' => $amount,
                'reference' => $reference,
                'patient_id' => $patientId === null ? $invoice?->patient_id : (string) $patientId,
                'patient_name' => Text::clean($details['patient_name'] ?? null) ?? $invoice?->patient_name,
                // Sans libellé saisi, l'acte ou la facture fait office de
                // motif : une ligne de caisse ne doit jamais rester muette.
                'description' => Text::clean($details['description'] ?? null)
                    ?? $act?->name
                    ?? ($invoice === null ? null : 'Facture '.$invoice->number),
                'invoice_id' => $invoice?->id,
                // La visite de l'hôte réglée par cet encaissement, venu de la file.
                'host_visit_ref' => $details['host_visit_ref'] ?? null,
                'status' => Payment::STATUS_VALID,
            ]);

            $this->auditor->record(
                'payment_recorded',
                $payment,
                sprintf(
                    'Encaissement %s : %s (%s), session %s%s',
                    $payment->number,
                    Money::format($amount),
                    $method->name,
                    $session->number,
                    $act === null ? '' : ', acte '.$act->code,
                ),
                [],
                array_filter([
                    'amount' => $amount,
                    'method' => $method->code,
                    'cash_session' => $session->number,
                    'act' => $act?->code,
                ], static fn ($value): bool => $value !== null),
                $cashier,
            );

            $invoice?->recalculate();

            return $payment;
        });
    }

    /**
     * La facture réglée par cet encaissement, verrouillée : ni annulée ni
     * remboursée, et le montant ne dépasse pas son solde.
     */
    private function invoice(?int $invoiceId, int $amount): ?Invoice
    {
        if ($invoiceId === null) {
            return null;
        }

        $invoice = Invoice::query()->whereKey($invoiceId)->lockForUpdate()->first();

        if ($invoice === null) {
            throw new FinanceRuleViolation("La facture choisie n'existe pas.");
        }

        if ($invoice->isClosed()) {
            throw new FinanceRuleViolation("La facture {$invoice->number} est {$invoice->statusLabel()} : elle ne s'encaisse plus.");
        }

        if ($amount > $invoice->balance()) {
            throw new FinanceRuleViolation(sprintf(
                "La facture %s ne doit plus que %s : impossible d'encaisser %s.",
                $invoice->number,
                Money::format($invoice->balance()),
                Money::format($amount),
            ));
        }

        return $invoice;
    }

    /**
     * L'acte encaissé, s'il y en a un. Un acte désactivé ne se facture plus :
     * il reste lisible sur les encaissements passés, mais on n'en crée pas de
     * nouveaux.
     */
    /**
     * Le compte du patient couvre-t-il cet encaissement ?
     *
     * Sans identifiant de patient, aucun compte ne se désigne : un
     * encaissement anonyme ne peut pas puiser dans les avances de quelqu'un.
     */
    private function assertAccountCovers(mixed $patientId, ?int $invoiceId, int $amount): string
    {
        $patientId = Text::clean($patientId === null ? null : (string) $patientId)
            ?? Invoice::query()->whereKey($invoiceId)->value('patient_id');

        if ($patientId === null) {
            throw new FinanceRuleViolation(
                'Un encaissement réglé sur le compte patient doit désigner le patient.'
            );
        }

        $balance = $this->accounts->balance((string) $patientId);

        if ($amount > $balance) {
            throw new FinanceRuleViolation(sprintf(
                'Le compte de ce patient ne contient que %s : %s ne peut pas y être prélevé.',
                Money::format($balance),
                Money::format($amount),
            ));
        }

        return (string) $patientId;
    }

    private function act(?int $actId): ?Act
    {
        if ($actId === null) {
            return null;
        }

        $act = Act::query()->find($actId);

        if ($act === null) {
            throw new FinanceRuleViolation("L'acte choisi n'existe pas.");
        }

        if (! $act->is_active) {
            throw new FinanceRuleViolation("L'acte « {$act->name} » est désactivé : il ne peut plus être encaissé.");
        }

        return $act;
    }
}
