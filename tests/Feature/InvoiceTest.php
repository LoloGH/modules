<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\CancelInvoice;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Factures : émises depuis le catalogue, réglées en caisse, statut et solde
 * tenus à jour par les encaissements et leurs annulations.
 */
class InvoiceTest extends TestCase
{
    use CashFixtures;
    use CatalogFixtures;

    private function invoice(int $consultations = 1, int $echos = 1): Invoice
    {
        $cons = $this->makeAct('CONS-GEN');
        $echo = $this->makeAct('ECHO');

        // Tarifs fixés une fois : un même test peut émettre plusieurs factures.
        if ($cons->activeTariff() === null) {
            $this->setTariff($cons, 2_000);
            $this->setTariff($echo, 7_500);
        }

        $lines = array_values(array_filter([
            $consultations > 0 ? ['act_id' => $cons->id, 'quantity' => $consultations] : null,
            $echos > 0 ? ['act_id' => $echo->id, 'quantity' => $echos] : null,
        ]));

        return app(CreateInvoice::class)->handle('PAT-00001', 'Aminata Traoré', $lines, null, $this->makeUser());
    }

    private function pay(Invoice $invoice, int $amount, $cashier = null, $session = null): Payment
    {
        $cashier ??= $this->makeUser();
        // Chaque caissier sur sa propre caisse : une caisse n'a qu'un tiroir.
        $session ??= $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-'.$cashier->id));

        return app(RecordPayment::class)->handle($session, $this->cashMethod(), $amount, $cashier, ['invoice_id' => $invoice->id]);
    }

    public function test_an_invoice_is_numbered_priced_from_the_catalog_and_audited(): void
    {
        $invoice = $this->invoice(consultations: 2, echos: 1);

        $this->assertMatchesRegularExpression('/^FAC-\d{4}-\d{6}$/', $invoice->number);
        $this->assertSame(11_500, $invoice->total);
        $this->assertSame(0, $invoice->paid);
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame(11_500, $invoice->balance());
        $this->assertSame([4_000, 7_500], $invoice->lines()->orderBy('id')->pluck('amount')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(1, AuditLog::where('event', 'invoice_created')->count());
    }

    public function test_the_line_price_is_frozen_when_the_tariff_changes(): void
    {
        $invoice = $this->invoice(consultations: 1, echos: 0);

        $this->setTariff($invoice->lines()->first()->act, 3_000);

        $this->assertSame(2_000, $invoice->fresh()->total);
        $this->assertSame(2_000, (int) $invoice->lines()->first()->unit_price);
    }

    public function test_an_invoice_needs_a_patient_lines_and_priced_active_acts(): void
    {
        $action = app(CreateInvoice::class);
        $user = $this->makeUser();

        $sansTarif = $this->makeAct('SANS-TARIF');
        $inactif = $this->makeAct('INACTIF');
        $this->setTariff($inactif, 1_000);
        $inactif->update(['is_active' => false]);

        $this->assertViolation('Indiquez le patient', fn () => $action->handle(null, '  ', [['act_id' => $sansTarif->id, 'quantity' => 1]], null, $user));
        $this->assertViolation('au moins une ligne', fn () => $action->handle('PAT-1', null, [], null, $user));
        $this->assertViolation("n'a pas de tarif", fn () => $action->handle('PAT-1', null, [['act_id' => $sansTarif->id, 'quantity' => 1]], null, $user));
        $this->assertViolation("n'est plus proposé", fn () => $action->handle('PAT-1', null, [['act_id' => $inactif->id, 'quantity' => 1]], null, $user));

        $this->assertSame(0, Invoice::count());
    }

    public function test_payments_move_the_status_from_unpaid_to_partial_to_paid(): void
    {
        $invoice = $this->invoice(); // 9 500
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $first = $this->pay($invoice, 4_000, $cashier, $session);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame(4_000, $invoice->paid);
        $this->assertSame(5_500, $invoice->balance());

        // Le patient et le libellé viennent de la facture.
        $this->assertSame('PAT-00001', $first->patient_id);
        $this->assertSame('Aminata Traoré', $first->patient_name);
        $this->assertSame('Facture '.$invoice->number, $first->description);

        $this->pay($invoice, 5_500, $cashier, $session);
        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(0, $invoice->balance());
    }

    public function test_a_payment_cannot_exceed_the_balance(): void
    {
        $invoice = $this->invoice(); // 9 500

        $this->assertViolation('ne doit plus que', fn () => $this->pay($invoice, 10_000));

        $this->assertSame(0, Payment::count());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->fresh()->status);
    }

    public function test_cancelling_a_payment_gives_the_balance_back(): void
    {
        $invoice = $this->invoice();
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $payment = $this->pay($invoice, 9_500, $cashier, $session);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        app(CancelCashMovement::class)->payment($payment, 'Erreur de caisse', $cashier);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame(0, $invoice->paid);
        $this->assertSame(9_500, $invoice->balance());
    }

    public function test_an_invoice_is_cancelled_with_a_reason_and_only_without_payments(): void
    {
        $invoice = $this->invoice();
        $controller = $this->makeUser();

        $this->pay($invoice, 1_000);
        $this->assertViolation('a des encaissements', fn () => app(CancelInvoice::class)->handle($invoice, 'Erreur', $controller));

        $other = $this->invoice();
        $this->assertViolation('doit avoir un motif', fn () => app(CancelInvoice::class)->handle($other, '  ', $controller));

        app(CancelInvoice::class)->handle($other, 'Émise par erreur', $controller);
        $other->refresh();

        $this->assertSame(Invoice::STATUS_CANCELLED, $other->status);
        $this->assertSame(0, $other->balance());
        $this->assertSame('Émise par erreur', $other->cancellation_reason);
        $this->assertSame(1, AuditLog::where('event', 'invoice_cancelled')->count());

        // Une facture annulée ne s'encaisse plus.
        $this->assertViolation('ne s\'encaisse plus', fn () => $this->pay($other, 500));
    }

    public function test_the_statuses_and_their_badges(): void
    {
        $this->assertSame(
            ['paid' => 'Payée', 'partial' => 'Partielle', 'unpaid' => 'Impayée', 'cancelled' => 'Annulée', 'refunded' => 'Remboursée'],
            Invoice::statusLabels(),
        );

        $tones = collect(array_keys(Invoice::statusLabels()))
            ->mapWithKeys(fn ($status) => [$status => (new Invoice(['status' => $status]))->statusTone()])
            ->all();

        $this->assertSame(['paid' => 'ok', 'partial' => 'warn', 'unpaid' => 'danger', 'cancelled' => 'off', 'refunded' => 'info'], $tones);
    }
}
