<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\CashSession;

class CashDeskHttpTest extends HttpTestCase
{
    public function test_a_visitor_is_sent_to_the_host_login(): void
    {
        $this->get('/finance/caisse')->assertRedirect(route('login'));
    }

    public function test_a_user_without_a_cash_role_is_forbidden(): void
    {
        $this->actingAs($this->makeUser())->get('/finance/caisse')->assertForbidden();
    }

    public function test_the_cashier_sees_the_form_with_active_registers_only(): void
    {
        $this->makeRegister('CAISSE-1');
        $this->makeRegister('ANCIENNE', false);

        $this->actingAs($this->cashier())
            ->get('/finance/caisse')
            ->assertOk()
            ->assertSee('Ouvrir ma session')
            ->assertSee('Caisse CAISSE-1')
            ->assertDontSee('Caisse ANCIENNE');
    }

    public function test_opening_a_session_redirects_to_it_and_accepts_spaced_amounts(): void
    {
        $register = $this->makeRegister();
        $cashier = $this->cashier();

        $response = $this->actingAs($cashier)->post('/finance/caisse/sessions', [
            'cash_register_id' => $register->id,
            'opening_float' => '25 000',
        ]);

        $session = CashSession::query()->sole();

        $response->assertRedirect(route('finance.cash.sessions.show', $session));
        $this->assertSame(25_000, $session->opening_float);
        $this->assertSame((string) $cashier->id, $session->cashier_id);

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee($session->number)
            ->assertSee('25 000 FCFA');
    }

    public function test_the_form_reports_missing_fields(): void
    {
        $this->from('/finance/caisse')
            ->actingAs($this->cashier())
            ->post('/finance/caisse/sessions', [])
            ->assertRedirect('/finance/caisse')
            ->assertSessionHasErrors(['cash_register_id', 'opening_float']);

        $this->assertSame(0, CashSession::count());
    }

    public function test_a_negative_or_decimal_float_is_refused(): void
    {
        $register = $this->makeRegister();

        $this->from('/finance/caisse')
            ->actingAs($this->cashier())
            ->post('/finance/caisse/sessions', ['cash_register_id' => $register->id, 'opening_float' => '-5'])
            ->assertSessionHasErrors('opening_float');

        $this->from('/finance/caisse')
            ->post('/finance/caisse/sessions', ['cash_register_id' => $register->id, 'opening_float' => '10.5'])
            ->assertSessionHasErrors('opening_float');
    }

    public function test_a_cashier_with_an_open_session_is_sent_straight_to_it(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)
            ->get('/finance/caisse')
            ->assertRedirect(route('finance.cash.sessions.show', $session));
    }

    public function test_an_occupied_register_gives_a_readable_message(): void
    {
        $register = $this->makeRegister();
        $this->openSession($this->cashier(), 0, $register);

        $this->from('/finance/caisse')
            ->actingAs($this->cashier())
            ->post('/finance/caisse/sessions', ['cash_register_id' => $register->id, 'opening_float' => '0'])
            ->assertRedirect('/finance/caisse')
            ->assertSessionHas('finance_error');

        $this->assertSame(1, CashSession::count());
    }

    public function test_the_history_lists_my_own_sessions_only(): void
    {
        $mine = $this->cashier();
        $other = $this->cashier();

        $closedMine = $this->openSession($mine, 0, $this->makeRegister('A'));
        app(\Keneya\FinanceCaisse\Actions\CloseCashSession::class)->handle($closedMine, 0, $mine);
        $theirs = $this->openSession($other, 0, $this->makeRegister('B'));

        $this->actingAs($mine)
            ->get('/finance/caisse')
            ->assertOk()
            ->assertSee($closedMine->number)
            ->assertDontSee($theirs->number);
    }
}
