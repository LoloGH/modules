<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Contracts\CashQueueProvider;
use Keneya\FinanceCaisse\Contracts\VisitAdvancer;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\Support\FakeCashQueue;
use Keneya\FinanceCaisse\Tests\Support\FakeVisitAdvancer;

/**
 * La couverture se règle écran par écran, se voit sur la fiche acte, et
 * s'applique au moment d'encaisser.
 */
class CoverageHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private function insurer(string $kind = Insurer::KIND_INSURANCE, int $rate = 80, string $scope = Insurer::SCOPE_ALL): Insurer
    {
        static $n = 0;
        $n++;

        return Insurer::create(['code' => 'ORG-'.$n, 'name' => 'Organisme '.$n, 'kind' => $kind, 'default_rate' => $rate, 'coverage_scope' => $scope, 'is_active' => true]);
    }

    public function test_the_control_sets_the_coverage_act_by_act(): void
    {
        $cons = $this->makeAct('CONS');
        $this->setTariff($cons, 2_000);
        $echo = $this->makeAct('ECHO');
        $this->setTariff($echo, 10_000);
        $insurer = $this->insurer();

        $this->actingAs($this->accountant())->get(route('finance.insurers.show', $insurer))
            ->assertOk()
            ->assertSee('Acte CONS')
            ->assertSee('Enregistrer la couverture');

        $this->post(route('finance.insurers.coverage', $insurer), [
            'coverage_scope' => 'all',
            'default_rate' => '80',
            'acts' => [
                $cons->id => ['covered' => '1', 'rate' => ''],
                $echo->id => ['rate' => ''],           // décoché : exclu
            ],
        ])->assertRedirect(route('finance.insurers.show', $insurer));

        $insurer->refresh();
        $this->assertSame(80, $insurer->rateFor($cons->id));
        $this->assertSame(0, $insurer->rateFor($echo->id));

        // La fiche acte dit qui le couvre.
        $this->get(route('finance.catalog.acts.show', $cons))->assertSee('Prises en charge')->assertSee($insurer->name.' · 80 %');
        $this->get(route('finance.catalog.acts.show', $echo))->assertSee('entièrement à la charge du patient');
    }

    public function test_the_cashier_reads_the_coverage_but_cannot_change_it(): void
    {
        $insurer = $this->insurer();

        $this->actingAs($this->cashier())->get(route('finance.insurers.show', $insurer))
            ->assertOk()->assertDontSee('Enregistrer la couverture');
        $this->actingAs($this->cashier())->post(route('finance.insurers.coverage', $insurer), ['coverage_scope' => 'all', 'default_rate' => '50'])
            ->assertForbidden();
    }

    public function test_at_the_cash_desk_the_patient_pays_his_share_and_the_rest_is_followed_on_an_invoice(): void
    {
        $echo = $this->makeAct('ECHO', $this->makeCenter('IMAGERIE'));
        $this->setTariff($echo, 10_000);
        $aid = $this->insurer(Insurer::KIND_SOCIAL_AID, 70);
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $session))
            ->assertSee('Prise en charge')
            ->assertSee('Aide sociale')
            ->assertSee($aid->name);

        $this->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'act_id' => $echo->id,
            'amount' => '3 000',
            'patient_id' => 'PAT-00005',
            'patient_name' => 'Awa Keita',
            'insurer_id' => $aid->id,
            'policy_number' => 'AIDE-12',
        ])
            ->assertRedirect(route('finance.cash.sessions.show', $session))
            ->assertSessionHas('finance_status')
            ->assertSessionHas('finance_print');

        $invoice = Invoice::query()->sole();
        $this->assertSame($aid->id, $invoice->insurer_id);
        $this->assertSame(7_000, $invoice->insurer_share);
        $this->assertSame(3_000, $invoice->patient_share);
        $this->assertSame('AIDE-12', $invoice->policy_number);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(7_000, $invoice->insurerOutstanding());

        // L'encaissement garde l'acte : il compte dans les recettes du service.
        $payment = Payment::query()->sole();
        $this->assertSame($invoice->id, $payment->invoice_id);
        $this->assertSame($echo->id, $payment->act_id);
        $this->assertSame(3_000, $payment->amount);

        $this->get('/finance/recettes')->assertSee('Imagerie');
        $this->actingAs($this->accountant())->get('/finance/creances?type=assurances')->assertSee('Awa Keita')->assertSee('7 000 FCFA');
    }

    public function test_more_than_the_patient_share_is_refused_and_nothing_is_kept(): void
    {
        $act = $this->makeAct('ECHO');
        $this->setTariff($act, 10_000);
        $insurer = $this->insurer(rate: 80);
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->from(route('finance.cash.sessions.show', $session))
            ->post(route('finance.cash.payments.store', $session), [
                'payment_method_id' => $this->cashMethod()->id, 'act_id' => $act->id, 'amount' => '10000', 'insurer_id' => $insurer->id,
            ])->assertSessionHas('finance_error');

        $this->assertSame(0, Invoice::count());
        $this->assertSame(0, Payment::count());
    }

    public function test_a_coverage_needs_an_act(): void
    {
        $insurer = $this->insurer();
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->from(route('finance.cash.sessions.show', $session))
            ->post(route('finance.cash.payments.store', $session), [
                'payment_method_id' => $this->cashMethod()->id, 'amount' => '1000', 'insurer_id' => $insurer->id,
            ])->assertSessionHas('finance_error', "Choisissez l'acte encaissé : la prise en charge s'applique à un acte.");

        $this->assertSame(0, Invoice::count());
    }

    public function test_a_queued_patient_can_be_collected_with_a_coverage(): void
    {
        $queue = new FakeCashQueue([new CashQueue('10', 'Caisse Ticket')]);
        $advancer = new FakeVisitAdvancer;
        $this->app->instance(CashQueueProvider::class, $queue);
        $this->app->instance(VisitAdvancer::class, $advancer);

        $act = $this->makeAct('TICKET');
        $this->setTariff($act, 1_000);
        $insurer = $this->insurer(rate: 50);
        $queue->add('10', '7', 1, 'Aminata Traoré', Finance::catalog()->findAct($act->id), 'called');

        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id, 'act_id' => $act->id, 'amount' => '500',
            'patient_name' => 'Aminata Traoré', 'queue_ref' => '10', 'visit_ref' => '7', 'insurer_id' => $insurer->id,
        ])->assertRedirect(route('finance.queue.index', ['file' => '10', 'session' => $session->id]));

        $this->assertCount(1, $advancer->advanced);
        $this->assertSame(500, Invoice::query()->sole()->insurer_share);
        $this->assertSame(Invoice::query()->sole()->id, Payment::query()->sole()->invoice_id);
    }

    public function test_the_dashboard_shows_the_coverage_of_the_period(): void
    {
        $act = $this->makeAct('CONS');
        $this->setTariff($act, 10_000);
        app(CreateInvoice::class)->handle(null, 'Awa', [['act_id' => $act->id, 'quantity' => 1]], null, $this->makeUser(),
            ['insurer_id' => $this->insurer(Insurer::KIND_SOCIAL_AID, 100)->id]);

        // Le tableau de bord suit la période regardée : la facture émise
        // aujourd'hui se lit dans le jour comme dans le mois.
        $this->actingAs($this->accountant())->get('/finance')
            ->assertSee('Prises en charge du jour')
            ->assertSee('Part des aides sociales')
            ->assertSee('10 000 FCFA');

        $this->get('/finance?periode=mois')
            ->assertSee('Prises en charge du mois')
            ->assertSee('10 000 FCFA');
    }
}
