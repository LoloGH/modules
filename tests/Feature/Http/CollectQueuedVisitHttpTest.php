<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use InvalidArgumentException;
use Keneya\FinanceCaisse\Contracts\CashQueueProvider;
use Keneya\FinanceCaisse\Contracts\VisitAdvancer;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Queue\CashQueue;
use Keneya\FinanceCaisse\Queue\NoVisitAdvancer;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\Support\FakeCashQueue;
use Keneya\FinanceCaisse\Tests\Support\FakeVisitAdvancer;
use Keneya\FinanceCaisse\Tests\Support\TestUser;
use RuntimeException;

/**
 * Après encaissement d'un patient appelé depuis la file, sa visite avance chez
 * l'hôte ; si l'hôte échoue, l'encaissement est annulé. Jamais d'argent
 * encaissé pour un patient resté à la caisse, jamais deux fois le même passage.
 */
class CollectQueuedVisitHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private FakeCashQueue $queue;

    private FakeVisitAdvancer $advancer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new FakeCashQueue([new CashQueue('10', 'Caisse Ticket')]);
        $this->advancer = new FakeVisitAdvancer;
        $this->app->instance(CashQueueProvider::class, $this->queue);
        $this->app->instance(VisitAdvancer::class, $this->advancer);
    }

    public function test_without_a_host_nothing_advances(): void
    {
        $this->app->forgetInstance(VisitAdvancer::class);
        $this->app->singleton(VisitAdvancer::class, NoVisitAdvancer::class);

        $this->assertInstanceOf(NoVisitAdvancer::class, Finance::visitAdvancer());
    }

    public function test_the_prefilled_form_carries_the_visit(): void
    {
        [$cashier, $session] = $this->calledPatient();

        $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', ['session' => $session, 'file' => '10', 'visite' => '7']))
            ->assertSee('name="visit_ref" value="7"', false)
            ->assertSee('name="queue_ref" value="10"', false);
    }

    public function test_collecting_records_the_payment_then_advances_the_visit(): void
    {
        [$cashier, $session, $act] = $this->calledPatient();

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), $this->form($act))
            ->assertRedirect(route('finance.queue.index', ['file' => '10', 'session' => $session->id]))
            ->assertSessionHas('finance_status');

        $payment = Payment::query()->sole();
        $this->assertSame('7', $payment->host_visit_ref);
        $this->assertSame(1_000, $payment->amount);

        $this->assertCount(1, $this->advancer->advanced);
        $this->assertSame('7', $this->advancer->advanced[0]['visit']);
        $this->assertSame($payment->number, $this->advancer->advanced[0]['payment']->number);
        $this->assertSame(1_000, $this->advancer->advanced[0]['payment']->amount);
        $this->assertSame('Acte TICKET', $this->advancer->advanced[0]['payment']->actName);
        $this->assertSame('Caisse Ticket', $this->advancer->advanced[0]['payment']->queueName);
    }

    public function test_when_the_host_fails_the_payment_is_rolled_back_and_logged(): void
    {
        [$cashier, $session, $act] = $this->calledPatient();
        $this->advancer->failWith = static fn () => new InvalidArgumentException('Ce dossier est cloture.');

        $this->actingAs($cashier)
            ->from(route('finance.cash.sessions.show', $session))
            ->post(route('finance.cash.payments.store', $session), $this->form($act))
            ->assertRedirect(route('finance.cash.sessions.show', $session))
            ->assertSessionHas('finance_error', "L'encaissement n'a pas été enregistré : le patient n'a pas pu être orienté (Ce dossier est cloture.)");

        // Pas d'encaissement orphelin, ni de trace d'encaissement au journal.
        $this->assertSame(0, Payment::count());
        $this->assertSame(0, AuditLog::where('event', 'payment_recorded')->count());

        // Mais l'échec, lui, est tracé.
        $this->assertSame(1, AuditLog::where('event', 'queued_payment_rolled_back')->count());
    }

    public function test_an_unexpected_host_error_is_not_shown_raw(): void
    {
        [$cashier, $session, $act] = $this->calledPatient();
        $this->advancer->failWith = static fn () => new RuntimeException('SQLSTATE secret');

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), $this->form($act))
            ->assertSessionHas('finance_error', "L'encaissement n'a pas été enregistré : le patient n'a pas pu être orienté (une erreur est survenue.)");

        $this->assertSame(0, Payment::count());
    }

    public function test_a_visit_that_no_longer_waits_is_refused_before_anything_is_recorded(): void
    {
        [$cashier, $session, $act] = $this->calledPatient();

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), ['visit_ref' => '999'] + $this->form($act))
            ->assertSessionHas('finance_error', "Ce patient n'attend plus d'encaissement à cette caisse.");

        $this->assertSame(0, Payment::count());
        $this->assertSame([], $this->advancer->advanced);
    }

    public function test_the_same_visit_is_never_collected_twice(): void
    {
        [$cashier, $session, $act] = $this->calledPatient();

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), $this->form($act));
        $this->post(route('finance.cash.payments.store', $session), $this->form($act))
            ->assertSessionHas('finance_error');

        $this->assertSame(1, Payment::count());
        $this->assertCount(1, $this->advancer->advanced);
    }

    public function test_an_ordinary_payment_does_not_touch_the_host(): void
    {
        [$cashier, $session, $act] = $this->calledPatient();

        $form = $this->form($act);
        unset($form['visit_ref'], $form['queue_ref']);

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), $form)
            ->assertRedirect(route('finance.cash.sessions.show', $session));

        $this->assertNull(Payment::query()->sole()->host_visit_ref);
        $this->assertSame([], $this->advancer->advanced);
    }

    /**
     * @return array{0: TestUser, 1: CashSession, 2: Act}
     */
    private function calledPatient(): array
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $act = $this->makeAct('TICKET');
        $this->setTariff($act, 1_000);
        $this->queue->add('10', '7', 4, 'Aminata Traoré', Finance::catalog()->findAct($act->id), 'called');

        return [$cashier, $session, $act];
    }

    /**
     * @return array<string, mixed>
     */
    private function form(Act $act): array
    {
        return [
            'payment_method_id' => $this->cashMethod()->id,
            'act_id' => $act->id,
            'amount' => '1 000',
            'patient_id' => 'PAT-7',
            'patient_name' => 'Aminata Traoré',
            'queue_ref' => '10',
            'visit_ref' => '7',
        ];
    }
}
