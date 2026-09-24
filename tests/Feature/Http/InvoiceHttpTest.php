<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * Écrans des factures : liste avec solde et badges, émission, fiche,
 * encaissement depuis la facture, annulation par le contrôle.
 */
class InvoiceHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private function pricedAct(string $code, int $amount): Act
    {
        $act = $this->makeAct($code);
        $this->setTariff($act, $amount);

        return $act;
    }

    private function invoice(string $patient = 'Aminata Traoré', int $amount = 2_000): Invoice
    {
        static $n = 0;
        $n++;

        return app(CreateInvoice::class)->handle(
            'PAT-0000'.$n, $patient, [['act_id' => $this->pricedAct('ACTE-'.$n, $amount)->id, 'quantity' => 1]], null, $this->makeUser(),
        );
    }

    public function test_the_menu_now_leads_to_invoices(): void
    {
        $this->actingAs($this->cashier())->get('/finance/factures')
            ->assertOk()
            ->assertSee('Factures')
            ->assertSee(route('finance.invoices.index'));
    }

    public function test_the_list_shows_number_patient_date_amounts_and_status_badges(): void
    {
        $unpaid = $this->invoice('Aminata Traoré', 2_000);
        $partial = $this->invoice('Moussa Diarra', 5_000);
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id, 'amount' => '1 500', 'invoice_id' => $partial->id,
        ])->assertRedirect(route('finance.invoices.show', $partial));

        $this->get('/finance/factures')
            ->assertOk()
            ->assertSee($unpaid->number)
            ->assertSee('Aminata Traoré')
            ->assertSee('PAT-00001')
            ->assertSee('Impayée')
            ->assertSee('Partielle')
            ->assertSee('3 500 FCFA')          // solde de la partielle
            ->assertSee('Solde à encaisser')
            ->assertSee('5 500 FCFA');         // 2 000 + 3 500

        // Onglet « Partielles » et recherche.
        $this->get('/finance/factures?statut=partial')->assertSee('Moussa Diarra')->assertDontSee('Aminata Traoré');
        $this->get('/finance/factures?q=Aminata')->assertSee('Aminata Traoré')->assertDontSee('Moussa Diarra');
    }

    public function test_the_cashier_issues_an_invoice_from_the_catalog(): void
    {
        $cons = $this->pricedAct('CONS-GEN', 2_000);
        $echo = $this->pricedAct('ECHO', 7_500);

        $this->actingAs($this->cashier())->get(route('finance.invoices.create'))
            ->assertOk()
            ->assertSee('Acte CONS-GEN · 2 000 FCFA')
            ->assertSee('Émettre la facture');

        $response = $this->post(route('finance.invoices.store'), [
            'patient_id' => 'PAT-00007',
            'patient_name' => 'Awa Keita',
            'lines' => [
                ['act_id' => $cons->id, 'quantity' => '2'],
                ['act_id' => $echo->id, 'quantity' => '1'],
                ['act_id' => '', 'quantity' => '1'],
            ],
        ]);

        $invoice = Invoice::query()->sole();
        $response->assertRedirect(route('finance.invoices.show', $invoice));
        $this->assertSame(11_500, $invoice->total);
        $this->assertSame(2, $invoice->lines()->count());

        $this->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee($invoice->number)
            ->assertSee('Awa Keita')
            ->assertSee('Impayée')
            ->assertSee('11 500 FCFA');
    }

    public function test_an_empty_invoice_is_refused_with_a_message(): void
    {
        $this->pricedAct('CONS-GEN', 2_000);

        $this->actingAs($this->cashier())
            ->from(route('finance.invoices.create'))
            ->post(route('finance.invoices.store'), ['patient_name' => 'Awa', 'lines' => [['act_id' => '', 'quantity' => '1']]])
            ->assertRedirect(route('finance.invoices.create'))
            ->assertSessionHas('finance_error', 'Une facture doit avoir au moins une ligne.');

        $this->assertSame(0, Invoice::count());
    }

    public function test_encaisser_from_the_invoice_prefills_the_session_and_comes_back(): void
    {
        $invoice = $this->invoice('Aminata Traoré', 9_500);
        $cashier = $this->cashier();

        // Sans session ouverte : l'invitation à l'ouvrir.
        $this->actingAs($cashier)->get(route('finance.invoices.show', $invoice))->assertSee('Ouvrez d\'abord votre session', false);

        $session = $this->openSession($cashier);

        $this->get(route('finance.invoices.show', $invoice))
            ->assertSee(route('finance.cash.sessions.show', ['session' => $session, 'facture' => $invoice->id]));

        $this->get(route('finance.cash.sessions.show', ['session' => $session, 'facture' => $invoice->id]))
            ->assertOk()
            ->assertSee('Facture <strong>'.$invoice->number.'</strong>', false)
            ->assertSee('name="invoice_id" value="'.$invoice->id.'"', false)
            ->assertSee('value="9500"', false)
            ->assertSee('value="Aminata Traoré"', false);

        $this->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id, 'amount' => '9 500', 'invoice_id' => $invoice->id,
        ])
            ->assertRedirect(route('finance.invoices.show', $invoice))
            ->assertSessionHas('finance_status');

        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
        $this->get(route('finance.invoices.show', $invoice))->assertSee('Payée')->assertDontSee('Encaisser dans');
    }

    public function test_overpaying_an_invoice_is_refused_with_a_message(): void
    {
        $invoice = $this->invoice('Aminata Traoré', 2_000);
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->from(route('finance.cash.sessions.show', $session))
            ->post(route('finance.cash.payments.store', $session), [
                'payment_method_id' => $this->cashMethod()->id, 'amount' => '5000', 'invoice_id' => $invoice->id,
            ])
            ->assertSessionHas('finance_error');

        $this->assertSame(0, Payment::count());
    }

    public function test_only_control_cancels_an_invoice(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->cashier())
            ->post(route('finance.invoices.cancel', $invoice), ['reason' => 'Erreur'])
            ->assertForbidden();

        $this->actingAs($this->accountant())
            ->post(route('finance.invoices.cancel', $invoice), ['reason' => 'Émise par erreur'])
            ->assertRedirect(route('finance.invoices.show', $invoice));

        $this->assertSame(Invoice::STATUS_CANCELLED, $invoice->fresh()->status);

        $this->get(route('finance.invoices.show', $invoice))->assertSee('Annulée')->assertSee('Émise par erreur');
        $this->get('/finance/factures?statut=cancelled')->assertSee($invoice->number);
    }

    public function test_rights_are_checked_route_by_route(): void
    {
        $this->actingAs($this->makeUser())->get('/finance/factures')->assertForbidden();

        // Le comptable consulte, il n'émet pas.
        $this->actingAs($this->accountant())->get('/finance/factures')->assertOk()->assertDontSee('Nouvelle facture');
        $this->actingAs($this->accountant())->get(route('finance.invoices.create'))->assertForbidden();

        $this->actingAs($this->cashier())->get('/finance/factures')->assertSee('Nouvelle facture');
    }
}
