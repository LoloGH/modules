<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\CancelInvoice;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * Documents imprimables : facture (A4), reçu d'encaissement et bon de
 * décaissement (ticket), proposés juste après l'opération.
 */
class PrintHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('finance.facility.name', 'Hôpital Fousseyni Daou');
        $app['config']->set('finance.facility.address', 'Kayes, Mali');
    }

    private function invoice(): Invoice
    {
        $act = $this->makeAct('ECHO');
        $this->setTariff($act, 7_500);

        return app(CreateInvoice::class)->handle('PAT-00001', 'Aminata Traoré', [['act_id' => $act->id, 'quantity' => 2]], 'Urgent', $this->makeUser());
    }

    // ------------------------------------------------------------ Facture

    public function test_an_invoice_prints_with_its_layout(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->cashier())->get(route('finance.invoices.print', $invoice))
            ->assertOk()
            ->assertSee('FACTURE')
            ->assertSee($invoice->number)
            ->assertSee('Hôpital Fousseyni Daou')
            ->assertSee('Kayes, Mali')
            ->assertSee('Aminata Traoré')
            ->assertSee('PAT-00001')
            ->assertSee('Acte ECHO')
            ->assertSee('15 000 FCFA')
            ->assertSee('Reste à payer')
            ->assertSee('Impayée')
            ->assertSee('window.print()', false)
            ->assertDontSee('ANNULÉE');
    }

    public function test_issuing_an_invoice_offers_to_print_it_and_the_sheet_has_the_button(): void
    {
        $act = $this->makeAct('CONS');
        $this->setTariff($act, 2_000);
        $cashier = $this->cashier();

        $this->actingAs($cashier)->post(route('finance.invoices.store'), [
            'patient_name' => 'Awa Keita', 'lines' => [['act_id' => $act->id, 'quantity' => '1']],
        ]);

        $invoice = Invoice::query()->sole();

        $this->get(route('finance.invoices.show', $invoice))
            ->assertSee('Imprimer la facture')
            ->assertSee(route('finance.invoices.print', $invoice).'?auto=1', false);
    }

    public function test_a_cancelled_invoice_prints_stamped(): void
    {
        $invoice = $this->invoice();
        app(CancelInvoice::class)->handle($invoice, 'Erreur', $this->makeUser());

        $this->actingAs($this->accountant())->get(route('finance.invoices.print', $invoice))
            ->assertOk()
            ->assertSee('ANNULÉE');
    }

    public function test_the_host_can_provide_the_facility_printed_on_documents(): void
    {
        Finance::facilityUsing(fn () => ['name' => 'Hôpital de Kayes', 'phone' => '']);
        $invoice = $this->invoice();

        $this->actingAs($this->cashier())->get(route('finance.invoices.print', $invoice))
            ->assertSee('Hôpital de Kayes')
            ->assertDontSee('Hôpital Fousseyni Daou')
            ->assertSee('Kayes, Mali')
            ->assertDontSee('Tél.');
    }

    // ------------------------------------------------------------ Reçus

    public function test_after_a_payment_the_receipt_is_offered_and_prints(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'amount' => '1 000',
            'patient_id' => 'PAT-00042',
            'patient_name' => 'Moussa Diarra',
            'reference' => 'OM-778',
        ])->assertSessionHas('finance_print');

        $payment = Payment::query()->sole();
        $receipt = route('finance.cash.payments.receipt', $payment);

        // Le bouton sur l'écran d'arrivée, et dans la liste des opérations.
        $this->get(route('finance.cash.sessions.show', $session))->assertSee($receipt, false);

        $this->get($receipt)
            ->assertOk()
            ->assertSee('REÇU D\'ENCAISSEMENT', false)
            ->assertSee($payment->number)
            ->assertSee('1 000 FCFA')
            ->assertSee('Moussa Diarra')
            ->assertSee('PAT-00042')
            ->assertSee('OM-778')
            ->assertSee($session->register->name)
            ->assertDontSee('ANNULÉ');
    }

    public function test_an_invoice_payment_receipt_shows_the_invoice_and_what_remains(): void
    {
        $invoice = $this->invoice(); // 15 000
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id, 'amount' => '5000', 'invoice_id' => $invoice->id,
        ])->assertSessionHas('finance_print');

        $this->get(route('finance.cash.payments.receipt', Payment::query()->sole()))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Reste à payer')
            ->assertSee('10 000 FCFA');
    }

    public function test_a_cancelled_payment_receipt_is_stamped(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id, 'amount' => '1000',
        ]);
        $payment = Payment::query()->sole();
        app(CancelCashMovement::class)->payment($payment, 'Erreur de saisie', $cashier);

        $this->get(route('finance.cash.payments.receipt', $payment))
            ->assertOk()
            ->assertSee('ANNULÉ')
            ->assertSee('Erreur de saisie');
    }

    public function test_after_a_disbursement_the_voucher_is_offered_and_prints(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 20_000);

        $this->actingAs($cashier)->post(route('finance.cash.disbursements.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'amount' => '3 000',
            'reason' => 'Achat de fournitures',
            'beneficiary' => 'Librairie du Kasso',
        ])->assertSessionHas('finance_print');

        $disbursement = Disbursement::query()->sole();

        $this->get(route('finance.cash.disbursements.receipt', $disbursement).'?auto=1')
            ->assertOk()
            ->assertSee('BON DE DÉCAISSEMENT')
            ->assertSee($disbursement->number)
            ->assertSee('3 000 FCFA')
            ->assertSee('Achat de fournitures')
            ->assertSee('Librairie du Kasso')
            ->assertSee('Le bénéficiaire')
            ->assertSee('window.addEventListener(\'load\'', false);
    }

    public function test_a_cashier_prints_only_his_own_receipts_control_prints_all(): void
    {
        $owner = $this->cashier();
        $session = $this->openSession($owner);

        $this->actingAs($owner)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id, 'amount' => '1000',
        ]);
        $payment = Payment::query()->sole();

        $this->actingAs($this->cashier())->get(route('finance.cash.payments.receipt', $payment))->assertForbidden();
        $this->actingAs($this->accountant())->get(route('finance.cash.payments.receipt', $payment))->assertOk();
        $this->actingAs($this->makeUser())->get(route('finance.cash.payments.receipt', $payment))->assertForbidden();
    }
}
