<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Contracts\CashQueueProvider;
use Keneya\FinanceCaisse\Contracts\VisitAdvancer;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Tests\Support\FakeCashQueue;
use Keneya\FinanceCaisse\Tests\Support\FakeVisitAdvancer;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * La file de caisse : un point rouge sur l'onglet d'une caisse où l'on
 * attend, l'encaissement dans la session de la caisse d'où vient le patient,
 * et un retour à la file quand un encaissement n'aboutit pas.
 */
class QueueRoutingHttpTest extends HttpTestCase
{
    private FakeCashQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new FakeCashQueue([new CashQueue('10', 'Caisse Ticket'), new CashQueue('11', 'Caisse Services')]);
        $this->app->instance(CashQueueProvider::class, $this->queue);
        $this->app->instance(VisitAdvancer::class, new FakeVisitAdvancer);
    }

    /**
     * Un caissier qui tient les deux caisses : « Caisse Ticket » et
     * « Caisse Services », du même nom que les files.
     *
     * @return array{0: TestUser, 1: CashSession, 2: CashSession}
     */
    private function cashierHoldingBoth(): array
    {
        $cashier = $this->cashier();
        CashierSetting::create(['cashier_id' => (string) $cashier->id, 'max_open_sessions' => 2]);

        $ticket = $this->openSession($cashier, 0, $this->makeRegister('Ticket'));
        $services = $this->openSession($cashier, 0, $this->makeRegister('Services'));

        return [$cashier, $ticket, $services];
    }

    // ------------------------------------------------------ Point rouge

    public function test_a_red_dot_marks_the_tab_of_a_queue_where_patients_wait(): void
    {
        [$cashier] = $this->cashierHoldingBoth();
        $this->queue->add('11', '1', 1, 'Moussa Diarra');
        $this->queue->add('11', '2', 2, 'Awa Keita');

        $this->actingAs($cashier)->get('/finance/file?file=10')
            ->assertOk()
            ->assertSee('title="2 patient(s) en attente"', false)
            ->assertSee('class="pip"', false);

        // Personne n'attend nulle part : aucun point.
        $this->queue = new FakeCashQueue([new CashQueue('10', 'Caisse Ticket'), new CashQueue('11', 'Caisse Services')]);
        $this->app->instance(CashQueueProvider::class, $this->queue);

        $this->get('/finance/file?file=10')->assertDontSee('class="pip"', false);
    }

    // ------------------------------------------------------ Bonne session

    public function test_each_queue_is_collected_in_the_session_of_its_own_register(): void
    {
        [$cashier, $ticket, $services] = $this->cashierHoldingBoth();
        $this->queue->add('10', '1', 1, 'Aminata Traoré', null, 'called');
        $this->queue->add('11', '2', 1, 'Moussa Diarra', null, 'called');

        $this->actingAs($cashier)->get('/finance/file?file=10')
            ->assertSee('Encaissement dans la session '.$ticket->number, false)
            ->assertSee(route('finance.cash.sessions.show', ['session' => $ticket, 'file' => '10', 'visite' => '1']));

        $this->get('/finance/file?file=11')
            ->assertSee('Encaissement dans la session '.$services->number, false)
            ->assertSee(route('finance.cash.sessions.show', ['session' => $services, 'file' => '11', 'visite' => '2']));
    }

    public function test_calling_the_next_patient_stays_on_the_matching_session(): void
    {
        [$cashier, , $services] = $this->cashierHoldingBoth();
        $this->queue->add('11', '2', 1, 'Moussa Diarra');

        $this->actingAs($cashier)->post('/finance/file/appeler', ['file' => '11'])
            ->assertRedirect(route('finance.queue.index', ['file' => '11', 'session' => $services->id]));
    }

    public function test_without_a_session_on_that_register_the_cashier_chooses(): void
    {
        $cashier = $this->cashier();
        $only = $this->openSession($cashier, 0, $this->makeRegister('Pharmacie'));

        $this->actingAs($cashier)->get('/finance/file?file=10')
            ->assertOk()
            ->assertSee('Vous n\'avez pas de session ouverte sur la caisse « Caisse Ticket »', false)
            ->assertSee('Encaisser dans')
            ->assertSee('Encaissement dans la session '.$only->number, false);
    }

    public function test_the_cashier_can_still_choose_another_session(): void
    {
        [$cashier, $ticket] = $this->cashierHoldingBoth();

        $this->actingAs($cashier)->get('/finance/file?file=11&session='.$ticket->id)
            ->assertSee('Encaissement dans la session '.$ticket->number, false)
            ->assertSee('Vous n\'avez pas de session ouverte sur la caisse', false);
    }

    // ------------------------------------------------------ Rouvrir depuis la file

    public function test_a_stale_link_offers_to_reopen_the_queue(): void
    {
        [$cashier, $ticket] = $this->cashierHoldingBoth();

        $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', ['session' => $ticket, 'file' => '10', 'visite' => 'perime']))
            ->assertOk()
            ->assertSee('Rouvrir depuis la file')
            ->assertSee(route('finance.queue.index', ['file' => '10']));
    }

    public function test_a_refused_collection_offers_to_reopen_the_queue(): void
    {
        [$cashier, $ticket] = $this->cashierHoldingBoth();

        $this->actingAs($cashier)
            ->from(route('finance.cash.sessions.show', $ticket))
            ->post(route('finance.cash.payments.store', $ticket), [
                'payment_method_id' => $this->cashMethod()->id,
                'amount' => '1000',
                'queue_ref' => '10',
                'visit_ref' => 'parti',
            ])
            ->assertSessionHas('finance_error');

        $this->get(route('finance.cash.sessions.show', $ticket))
            ->assertSee('Rouvrir depuis la file')
            ->assertSee(route('finance.queue.index', ['file' => '10']));
    }

    public function test_an_ordinary_session_page_has_no_reopen_button(): void
    {
        [$cashier, $ticket] = $this->cashierHoldingBoth();

        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $ticket))
            ->assertDontSee('Rouvrir depuis la file');
    }
}
