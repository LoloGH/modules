<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
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
