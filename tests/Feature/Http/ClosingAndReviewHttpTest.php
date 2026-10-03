<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\OpenCashSessions;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\CashSession;

class ClosingAndReviewHttpTest extends HttpTestCase
{
    public function test_the_cashier_closes_with_an_exact_count(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 5_000, $cashier);

        $this->actingAs($cashier)
            ->post(route('finance.cash.sessions.close', $session), ['counted_cash' => '15 000'])
            ->assertRedirect(route('finance.cash.sessions.show', $session))
            ->assertSessionHas('finance_status');

        $closed = CashSession::findOrFail($session->id);

        $this->assertTrue($closed->isClosed());
        $this->assertSame(0, $closed->variance);

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee('Écart')
            ->assertSee('15 000 FCFA');
    }

    public function test_a_variance_without_justification_keeps_the_session_open(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000);
        $back = route('finance.cash.sessions.show', $session);

        $this->from($back)->actingAs($cashier)
            ->post(route('finance.cash.sessions.close', $session), ['counted_cash' => '9000'])
            ->assertRedirect($back)
            ->assertSessionHas('finance_error')
            ->assertSessionHasInput('counted_cash', '9000');

        $this->assertTrue(CashSession::findOrFail($session->id)->isOpen());
    }

    public function test_a_justified_variance_is_recorded_and_shown(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000);

        $this->actingAs($cashier)
            ->post(route('finance.cash.sessions.close', $session), ['counted_cash' => '9000', 'variance_reason' => 'Monnaie rendue en trop'])
            ->assertRedirect(route('finance.cash.sessions.show', $session));

        $this->assertSame(-1_000, CashSession::findOrFail($session->id)->variance);

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertSee('Monnaie rendue en trop')
            ->assertSee('1 000 FCFA');
    }

    public function test_a_missing_count_is_a_form_error(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->from(route('finance.cash.sessions.show', $session))->actingAs($cashier)
            ->post(route('finance.cash.sessions.close', $session), [])
            ->assertSessionHasErrors('counted_cash');
    }

    public function test_only_reviewers_see_the_list_of_sessions_to_validate(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 0);
        app(CloseCashSession::class)->handle($session, 0, $cashier);

        $this->actingAs($cashier)->get('/finance/sessions')->assertForbidden();

        $this->actingAs($this->accountant())
            ->get('/finance/sessions')
            ->assertOk()
            ->assertSee($session->number);
    }

    public function test_the_accountant_validates_a_closed_session(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 0);
        app(CloseCashSession::class)->handle($session, 0, $cashier);

        $this->actingAs($this->accountant())
            ->post(route('finance.review.approve', $session), ['note' => 'Conforme'])
            ->assertRedirect(route('finance.review.index'));

        $validated = CashSession::findOrFail($session->id);

        $this->assertTrue($validated->isValidated());
        $this->assertSame('Conforme', $validated->validation_note);

        $this->get('/finance/sessions?status=validated')->assertOk()->assertSee($session->number);
    }

    /**
     * Le controle voit UNE ligne par tiroir, avec les chiffres du tiroir, et
     * une seule validation les couvre toutes.
     *
     * Trois lignes, dont deux a zero, donnaient trois gestes pour un seul
     * fait : un tiroir compte une fois n'a qu'un ecart a controler.
     */
    public function test_a_shared_drawer_is_one_line_and_one_validation(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');
        $pharmacie = $this->makeRegister('CAISSE-PHARMACIE');

        [$premiere] = app(OpenCashSessions::class)->grouped(
            [$ticket->id, $services->id, $pharmacie->id],
            50_000,
            $cashier,
        );

        app(CloseCashSession::class)->handle($premiere, 50_000, $cashier);

        $controle = $this->accountant();

        $page = $this->actingAs($controle)->get('/finance/sessions')->assertOk();

        // Une seule ligne, qui nomme les trois caisses et porte le fonds.
        $page->assertSee('Tiroir commun')
            ->assertSee('et 2 autre(s)')
            ->assertSee('50 000 FCFA');
        $this->assertSame(1, substr_count((string) $page->getContent(), 'Tiroir commun'));

        $this->post(route('finance.review.approve', $premiere), ['note' => 'Conforme'])
            ->assertRedirect(route('finance.review.index'))
            ->assertSessionHas('finance_status', function (string $message): bool {
                return str_contains($message, 'Tiroir validé avec ses 3 caisses');
            });

        $this->assertSame(3, CashSession::query()->where('status', CashSession::STATUS_VALIDATED)->count());
        $this->assertSame(0, CashSession::query()->where('status', CashSession::STATUS_CLOSED)->count());
    }

    /**
     * Le tiroir est commun, les recettes ne le sont pas : on doit pouvoir
     * lire ce que chaque caisse y a apporte.
     */
    public function test_each_register_share_of_the_drawer_is_readable(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        [$premiere, $seconde] = app(OpenCashSessions::class)->grouped(
            [$ticket->id, $services->id],
            10_000,
            $cashier,
        );

        app(RecordPayment::class)->handle($premiere, $this->cashMethod(), 4_000, $cashier);
        app(RecordPayment::class)->handle($seconde, $this->cashMethod(), 25_000, $cashier);

        // La page de la session detaille les parts, et leur somme fait le tiroir.
        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $seconde))
            ->assertOk()
            ->assertSee('Ce que chaque caisse apporte au tiroir')
            ->assertSeeInOrder(['Caisse CAISSE-TICKET', '14 000 FCFA', 'Caisse CAISSE-SERVICES', '25 000 FCFA'], false)
            ->assertSee('39 000 FCFA');

        app(CloseCashSession::class)->handle($premiere, 39_000, $cashier);

        // Et le controle les lit sans ouvrir la session.
        $this->actingAs($this->accountant())->get('/finance/sessions')
            ->assertOk()
            ->assertSeeInOrder(['Caisse CAISSE-TICKET 14 000 FCFA', 'Caisse CAISSE-SERVICES 25 000 FCFA'], false);
    }

    public function test_the_cashier_cannot_validate(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 0);
        app(CloseCashSession::class)->handle($session, 0, $cashier);

        $this->actingAs($cashier)
            ->post(route('finance.review.approve', $session))
            ->assertForbidden();

        $this->assertTrue(CashSession::findOrFail($session->id)->isClosed());
    }

    public function test_an_open_session_cannot_be_validated_and_says_why(): void
    {
        $session = $this->openSession($this->cashier());

        $this->from('/finance/sessions')->actingAs($this->accountant())
            ->post(route('finance.review.approve', $session))
            ->assertSessionHas('finance_error');

        $this->assertTrue(CashSession::findOrFail($session->id)->isOpen());
    }
}
