<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Contracts\CashQueueProvider;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Queue\NoCashQueue;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\Support\FakeCashQueue;

/**
 * La file de caisse dans Finance : fournie par l'hôte, montrée et actionnée
 * ici. On n'appelle ni n'encaisse sans session de caisse ouverte.
 */
class CashQueueHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private FakeCashQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new FakeCashQueue([new CashQueue('10', 'Caisse Ticket'), new CashQueue('11', 'Caisse Services')]);
        $this->app->instance(CashQueueProvider::class, $this->queue);
    }

    public function test_without_a_host_the_module_has_no_queue(): void
    {
        $this->app->forgetInstance(CashQueueProvider::class);
        $this->app->singleton(CashQueueProvider::class, NoCashQueue::class);

        $this->assertInstanceOf(NoCashQueue::class, Finance::cashQueue());

        $this->actingAs($this->cashier())->get('/finance/file')
            ->assertOk()
            ->assertSee('Aucune file de caisse');
    }

    public function test_the_host_implementation_is_the_one_finance_reads(): void
    {
        $this->assertSame($this->queue, Finance::cashQueue());
    }

    public function test_without_an_open_session_the_cashier_is_invited_to_open_one(): void
    {
        $this->queue->add('10', '1', 1, 'Aminata Traoré');

        $this->actingAs($this->cashier())->get('/finance/file')
            ->assertOk()
            ->assertSee('Ouvrez votre session de caisse')
            ->assertSee('Aminata Traoré')
            ->assertDontSee('Appeler le suivant');
    }

    public function test_calling_without_an_open_session_is_refused(): void
    {
        $this->queue->add('10', '1', 1, 'Aminata Traoré');

        $this->actingAs($this->cashier())->post('/finance/file/appeler', ['file' => '10'])
            ->assertRedirect()
            ->assertSessionHas('finance_error');

        $this->assertSame([], $this->queue->calls);
    }

    public function test_the_cashier_sees_the_queue_of_the_chosen_register_with_the_expected_act(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $act = $this->makeAct('TICKET');
        $this->setTariff($act, 1_000);
        $dto = Finance::catalog()->findAct($act->id);

        $this->queue->add('10', '1', 3, 'Aminata Traoré', $dto);
        $this->queue->add('11', '2', 1, 'Moussa Diarra');

        $this->actingAs($cashier)->get('/finance/file')
            ->assertOk()
            ->assertSee('Caisse Ticket')
            ->assertSee('Caisse Services')
            ->assertSee('Appeler le suivant')
            ->assertSee('Aminata Traoré')
            ->assertSee('Acte TICKET')
            ->assertSee('1 000 FCFA')
            ->assertDontSee('Moussa Diarra');

        $this->get('/finance/file?file=11')->assertSee('Moussa Diarra')->assertDontSee('Aminata Traoré');
    }

    public function test_calling_the_next_patient_goes_through_the_host_contract(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $this->queue->add('10', '1', 1, 'Aminata Traoré');

        $this->actingAs($cashier)->post('/finance/file/appeler', ['file' => '10', 'session' => $session->id])
            ->assertRedirect(route('finance.queue.index', ['file' => '10', 'session' => $session->id]))
            ->assertSessionHas('finance_status', 'Ticket n° 1 appelé : Aminata Traoré (PAT-1).');

        $this->assertSame(['10:'.$cashier->id], $this->queue->calls);

        // Appelé : le bouton « Encaisser » apparaît et mène à la session.
        $this->get(route('finance.queue.index', ['file' => '10']))
            ->assertSee('Encaisser')
            ->assertSee(route('finance.cash.sessions.show', ['session' => $session, 'file' => '10', 'visite' => '1']));
    }

    public function test_an_empty_queue_says_so_when_calling(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $this->actingAs($cashier)->post('/finance/file/appeler', ['file' => '10'])
            ->assertSessionHas('finance_error', 'Aucun patient en attente à Caisse Ticket.');
    }

    public function test_encaisser_prefills_patient_act_and_amount_and_the_cashier_validates(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $method = $this->cashMethod();

        $act = $this->makeAct('ECHO');
        $this->setTariff($act, 7_500);
        $this->queue->add('11', '42', 5, 'Moussa Diarra', Finance::catalog()->findAct($act->id), 'called');

        $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', ['session' => $session, 'file' => '11', 'visite' => '42']))
            ->assertOk()
            ->assertSee('Patient appelé')
            ->assertSee('value="Moussa Diarra"', false)
            ->assertSee('value="7500"', false)
            ->assertSee('value="PAT-42"', false);

        // Rien n'est encaissé tant que le caissier n'a pas validé.
        $this->assertSame(0, Payment::count());

        $this->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $method->id,
            'act_id' => $act->id,
            'amount' => '7 500',
            'patient_id' => 'PAT-42',
            'patient_name' => 'Moussa Diarra',
        ])->assertRedirect(route('finance.cash.sessions.show', $session));

        $payment = Payment::query()->sole();
        $this->assertSame(7_500, $payment->amount);
        $this->assertSame('PAT-42', $payment->patient_id);
        $this->assertSame($act->id, $payment->act_id);
    }

    public function test_an_unknown_visit_prefills_nothing(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', ['session' => $session, 'file' => '11', 'visite' => '999']))
            ->assertOk()
            ->assertDontSee('Patient appelé');
    }

    public function test_rights_are_checked_route_by_route(): void
    {
        $this->actingAs($this->makeUser())->get('/finance/file')->assertForbidden();

        // Le comptable consulte, il n'appelle pas les patients.
        $this->actingAs($this->accountant())->get('/finance/file')->assertOk();
        $this->actingAs($this->accountant())->post('/finance/file/appeler', ['file' => '10'])->assertForbidden();

        $this->assertSame([], $this->queue->calls);
    }

    public function test_the_menu_offers_the_queue(): void
    {
        $this->actingAs($this->cashier())->get('/finance/caisse')->assertSee('File de caisse');
    }
}
