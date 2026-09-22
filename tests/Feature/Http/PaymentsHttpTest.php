<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * L'écran Paiements : les encaissements réels, filtrables, avec leur
 * facture et leur reçu. Un caissier ne voit que les siens.
 */
class PaymentsHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    /**
     * @param  array<string, mixed>  $details
     */
    private function pay(TestUser $cashier, CashSession $session, int $amount, array $details = [], ?PaymentMethod $method = null): Payment
    {
        return app(RecordPayment::class)->handle($session, $method ?? $this->cashMethod(), $amount, $cashier, $details);
    }

    public function test_the_menu_leads_to_payments(): void
    {
        $this->actingAs($this->cashier())->get('/finance/paiements')
            ->assertOk()
            ->assertSee('Paiements')
            ->assertSee('Aucun encaissement pour ces critères');
    }

    public function test_the_list_shows_every_column_and_the_totals(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $act = $this->makeAct('ECHO');
        $this->setTariff($act, 7_500);
        $invoice = app(CreateInvoice::class)->handle('PAT-00009', 'Awa Keita', [['act_id' => $act->id, 'quantity' => 1]], null, $cashier);

        $this->pay($cashier, $session, 7_500, ['invoice_id' => $invoice->id]);
        $momo = $this->pay($cashier, $session, 2_000, ['patient_id' => 'PAT-00001', 'patient_name' => 'Aminata Traoré', 'reference' => 'OM-4411'], $this->momoMethod());
        $cancelled = $this->pay($cashier, $session, 500, ['patient_name' => 'Moussa Diarra']);
        app(CancelCashMovement::class)->payment($cancelled, 'Erreur', $cashier);

        $this->actingAs($cashier)->get('/finance/paiements')
            ->assertOk()
            ->assertSee($momo->number)
            ->assertSee('Aminata Traoré')
            ->assertSee('PAT-00001')
            ->assertSee('OM-4411')
            ->assertSee($invoice->number)
            ->assertSee(route('finance.invoices.show', $invoice))
            ->assertSee(route('finance.cash.payments.receipt', $momo))
            ->assertSee('Valide')
            ->assertSee('Annulé')
            ->assertSee('9 500 FCFA')      // total des valides, l'annulé exclu
            ->assertSee('Par moyen');
    }

    public function test_filters_by_method_status_search_and_period(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $cash = $this->pay($cashier, $session, 1_000, ['patient_name' => 'Aminata Traoré']);
        $momo = $this->pay($cashier, $session, 2_000, ['patient_name' => 'Moussa Diarra', 'reference' => 'OM-1'], $this->momoMethod());
        $old = $this->pay($cashier, $session, 3_000, ['patient_name' => 'Ancien Patient']);
        $old->forceFill(['created_at' => Carbon::now()->subMonths(3)])->save();
        $cancelled = $this->pay($cashier, $session, 500, ['patient_name' => 'Annulé Patient']);
        app(CancelCashMovement::class)->payment($cancelled, 'Erreur', $cashier);

        $this->actingAs($cashier);

        $this->get('/finance/paiements?moyen='.$this->momoMethod()->id)
            ->assertSee('Moussa Diarra')->assertDontSee('Aminata Traoré');

        $this->get('/finance/paiements?statut=cancelled')
            ->assertSee('Annulé Patient')->assertDontSee('Moussa Diarra');

        $this->get('/finance/paiements?q=OM-1')
            ->assertSee('Moussa Diarra')->assertDontSee('Aminata Traoré');

        // Par défaut : le mois en cours ; l'ancien n'y est pas.
        $this->get('/finance/paiements')->assertDontSee('Ancien Patient');

        $this->get('/finance/paiements?du='.Carbon::now()->subMonths(4)->toDateString())
            ->assertSee('Ancien Patient');

        // Une date illisible retombe sur le défaut, sans erreur.
        $this->get('/finance/paiements?du=pas-une-date')->assertOk();
    }

    public function test_search_finds_a_payment_by_its_invoice_number(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $act = $this->makeAct('CONS');
        $this->setTariff($act, 2_000);
        $invoice = app(CreateInvoice::class)->handle(null, 'Awa Keita', [['act_id' => $act->id, 'quantity' => 1]], null, $cashier);

        $this->pay($cashier, $session, 2_000, ['invoice_id' => $invoice->id]);
        $this->pay($cashier, $session, 1_000, ['patient_name' => 'Autre']);

        $this->actingAs($cashier)->get('/finance/paiements?q='.$invoice->number)
            ->assertSee('Awa Keita')->assertDontSee('Autre');
    }

    public function test_a_cashier_sees_only_his_payments_control_sees_all(): void
    {
        $alice = $this->cashier();
        $bob = $this->cashier();
        $this->pay($alice, $this->openSession($alice, 0, $this->makeRegister('A')), 1_000, ['patient_name' => 'Patient Alice']);
        $this->pay($bob, $this->openSession($bob, 0, $this->makeRegister('B')), 2_000, ['patient_name' => 'Patient Bob']);

        $this->actingAs($alice)->get('/finance/paiements')
            ->assertSee('Patient Alice')->assertDontSee('Patient Bob')->assertSee('de vos sessions');

        $this->actingAs($this->accountant())->get('/finance/paiements')
            ->assertSee('Patient Alice')->assertSee('Patient Bob')->assertSee('de toutes les caisses');
    }

    public function test_without_the_right_it_is_forbidden(): void
    {
        $this->actingAs($this->makeUser())->get('/finance/paiements')->assertForbidden();
    }
}
