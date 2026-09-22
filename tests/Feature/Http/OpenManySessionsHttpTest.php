<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Un caissier qui tient plusieurs caisses les ouvre en une fois, chacune avec
 * son fonds initial, et lit le total. Tout ou rien.
 */
class OpenManySessionsHttpTest extends HttpTestCase
{
    private function allow(int $limit, string $cashierId): void
    {
        CashierSetting::create(['cashier_id' => $cashierId, 'max_open_sessions' => $limit]);
    }

    public function test_with_one_session_allowed_the_single_form_remains(): void
    {
        $this->makeRegister('CAISSE-TICKET');
        $this->makeRegister('CAISSE-SERVICES');

        $this->actingAs($this->cashier())->get('/finance/caisse')
            ->assertOk()
            ->assertSee('Ouvrir la session')
            ->assertDontSee('Ouvrir les caisses cochées');
    }

    public function test_a_cashier_allowed_three_registers_sees_them_all_with_a_total(): void
    {
        $cashier = $this->cashier();
        $this->allow(3, (string) $cashier->id);
        foreach (['CAISSE-TICKET', 'CAISSE-SERVICES', 'CAISSE-URGENCES'] as $code) {
            $this->makeRegister($code);
        }

        $this->actingAs($cashier)->get('/finance/caisse')
            ->assertOk()
            ->assertSee('Ouvrir les caisses cochées')
            ->assertSee('Fonds initial total')
            ->assertSee('Caisse CAISSE-TICKET')
            ->assertSee('Caisse CAISSE-SERVICES')
            ->assertSee('Caisse CAISSE-URGENCES');
    }

    public function test_opening_all_registers_at_once_gives_one_session_each_and_the_total(): void
    {
        $cashier = $this->cashier();
        $this->allow(3, (string) $cashier->id);
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');
        $urgences = $this->makeRegister('CAISSE-URGENCES');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'registers' => [$ticket->id, $services->id, $urgences->id],
            'floats' => [$ticket->id => '10 000', $services->id => '20000', $urgences->id => '15 000'],
        ])
            ->assertRedirect(route('finance.cash.index'))
            ->assertSessionHas('finance_status', '3 session(s) ouverte(s) : Caisse CAISSE-TICKET, Caisse CAISSE-SERVICES, Caisse CAISSE-URGENCES. Fonds initial total : 45 000 FCFA.');

        $floats = CashSession::query()->open()->where('cashier_id', (string) $cashier->id)
            ->pluck('opening_float', 'cash_register_id')->map(fn ($v) => (int) $v)->all();

        $this->assertSame([$ticket->id => 10_000, $services->id => 20_000, $urgences->id => 15_000], $floats);
        $this->assertSame(3, AuditLog::where('event', 'session_opened')->count());
    }

    public function test_only_the_checked_registers_are_opened(): void
    {
        $cashier = $this->cashier();
        $this->allow(3, (string) $cashier->id);
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'registers' => [$services->id],
            'floats' => [$services->id => '5000'],
        ])->assertSessionHas('finance_status');

        $this->assertSame([$services->id], CashSession::query()->pluck('cash_register_id')->map(fn ($v) => (int) $v)->all());
        $this->assertFalse(CashSession::query()->where('cash_register_id', $ticket->id)->exists());
    }

    public function test_one_refused_register_opens_none_and_names_it(): void
    {
        $cashier = $this->cashier();
        $this->allow(3, (string) $cashier->id);
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        // Quelqu'un tient déjà la Caisse Services.
        $this->openSession($this->cashier(), 0, $services);

        $this->actingAs($cashier)->from('/finance/caisse')->post(route('finance.cash.sessions.open-many'), [
            'registers' => [$ticket->id, $services->id],
            'floats' => [$ticket->id => '1000', $services->id => '1000'],
        ])
            ->assertRedirect('/finance/caisse')
            ->assertSessionHas('finance_error', 'Aucune session ouverte. Caisse CAISSE-SERVICES : Une session est déjà ouverte sur cette caisse.');

        $this->assertSame(0, CashSession::query()->where('cashier_id', (string) $cashier->id)->count());
    }

    public function test_the_limit_still_applies_to_the_whole_batch(): void
    {
        $cashier = $this->cashier();
        $this->allow(2, (string) $cashier->id);
        $ids = collect(['A', 'B', 'C'])->map(fn ($code) => $this->makeRegister('CAISSE-'.$code)->id)->all();

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), ['registers' => $ids])
            ->assertSessionHas('finance_error');

        $this->assertSame(0, CashSession::count());
    }

    public function test_assignments_still_apply(): void
    {
        $cashier = $this->cashier();
        $this->allow(3, (string) $cashier->id);
        $mine = $this->makeRegister('CAISSE-TICKET');
        $notMine = $this->makeRegister('CAISSE-SERVICES');
        CashierRegister::create(['cashier_id' => (string) $cashier->id, 'cash_register_id' => $mine->id]);

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), ['registers' => [$mine->id, $notMine->id]])
            ->assertSessionHas('finance_error');

        $this->assertSame(0, CashSession::count());
    }

    public function test_nothing_checked_is_refused_and_negative_floats_too(): void
    {
        $cashier = $this->cashier();
        $this->allow(3, (string) $cashier->id);
        $ticket = $this->makeRegister('CAISSE-TICKET');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [])
            ->assertSessionHasErrors('registers');

        $this->post(route('finance.cash.sessions.open-many'), ['registers' => [$ticket->id], 'floats' => [$ticket->id => '-5']])
            ->assertSessionHasErrors('floats.'.$ticket->id);

        $this->assertSame(0, CashSession::count());
    }

    public function test_without_the_right_to_open_it_is_forbidden(): void
    {
        $ticket = $this->makeRegister('CAISSE-TICKET');

        $this->actingAs($this->accountant())
            ->post(route('finance.cash.sessions.open-many'), ['registers' => [$ticket->id]])
            ->assertForbidden();
    }

    // ------------------------------------------- Identifiant du patient

    public function test_the_payment_block_asks_for_the_patient_identifier_and_the_list_shows_it(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $session))
            ->assertSee('Identifiant du patient')
            ->assertSee('name="patient_id"', false);

        $this->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'amount' => '1000',
            'patient_id' => 'PAT-00042',
            'patient_name' => 'Aminata Traoré',
        ]);

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertSee('Aminata Traoré')
            ->assertSee('PAT-00042');
    }
}
