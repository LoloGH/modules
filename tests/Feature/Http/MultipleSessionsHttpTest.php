<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Le bureau du caissier quand il tient plusieurs tiroirs, et le réglage de
 * la limite depuis l'écran « Caisses ».
 */
class MultipleSessionsHttpTest extends HttpTestCase
{
    private function allowTwo(): void
    {
        config()->set('finance.cash.max_open_sessions_per_cashier', 2);
    }

    public function test_with_the_default_limit_the_desk_still_goes_straight_to_the_session(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)
            ->get('/finance/caisse')
            ->assertRedirect(route('finance.cash.sessions.show', $session));
    }

    public function test_the_desk_lists_every_open_session(): void
    {
        $this->allowTwo();

        $cashier = $this->cashier();
        $ticket = $this->openSession($cashier, 0, $this->makeRegister('CAISSE-TICKET'));
        $services = $this->openSession($cashier, 0, $this->makeRegister('CAISSE-SERVICES'));

        $this->actingAs($cashier)
            ->get('/finance/caisse')
            ->assertOk()
            ->assertSee('Mes sessions ouvertes')
            ->assertSee($ticket->number)
            ->assertSee($services->number)
            ->assertSee('2 caisse(s), 2 tiroir(s) sur 2 autorisé(s)');
    }

    public function test_the_desk_only_offers_registers_that_are_free(): void
    {
        $this->allowTwo();

        $cashier = $this->cashier();
        $taken = $this->makeRegister('CAISSE-TICKET');
        $free = $this->makeRegister('CAISSE-SERVICES');

        $this->openSession($cashier, 0, $taken);

        // On vise l'option de la liste, pas le nom : celui-ci apparaît aussi
        // dans le tableau des sessions ouvertes.
        $this->actingAs($cashier)
            ->get('/finance/caisse')
            ->assertOk()
            ->assertSee('<option value="'.$free->id.'"', false)
            ->assertDontSee('<option value="'.$taken->id.'"', false);
    }

    public function test_a_register_held_by_someone_else_is_not_offered_either(): void
    {
        $this->allowTwo();

        $taken = $this->makeRegister('CAISSE-TICKET');
        $this->openSession($this->cashier(), 0, $taken);

        $this->actingAs($this->cashier())
            ->get('/finance/caisse')
            ->assertOk()
            ->assertDontSee('<option value="'.$taken->id.'"', false);
    }

    public function test_the_form_disappears_once_the_limit_is_reached(): void
    {
        $this->allowTwo();

        $cashier = $this->cashier();
        $this->openSession($cashier, 0, $this->makeRegister('A'));
        $this->openSession($cashier, 0, $this->makeRegister('B'));
        $this->makeRegister('C');

        $this->actingAs($cashier)
            ->get('/finance/caisse')
            ->assertOk()
            ->assertSee('Limite atteinte')
            ->assertDontSee('Ouvrir la session');
    }

    public function test_the_switcher_shows_every_open_register_including_the_current_one(): void
    {
        $this->allowTwo();

        $cashier = $this->cashier();
        $ticket = $this->openSession($cashier, 0, $this->makeRegister('CAISSE-TICKET'));
        $services = $this->openSession($cashier, 0, $this->makeRegister('CAISSE-SERVICES'));

        $response = $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', $ticket))
            ->assertOk()
            ->assertSee('Mes caisses ouvertes')
            // Les deux caisses sont proposées, celle qu'on regarde comprise.
            ->assertSee(route('finance.cash.sessions.show', $ticket), false)
            ->assertSee(route('finance.cash.sessions.show', $services), false);

        // Celle qu'on regarde porte l'état actif.
        $this->assertStringContainsString('btn sm on', $response->getContent());
        $this->assertStringContainsString('aria-current="page"', $response->getContent());
    }

    public function test_a_single_session_page_shows_no_switcher(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertDontSee('Mes caisses ouvertes');
    }

    public function test_the_switcher_is_personal(): void
    {
        $this->allowTwo();

        $mine = $this->cashier();
        $theirs = $this->cashier();

        $session = $this->openSession($mine, 0, $this->makeRegister('A'));
        $other = $this->openSession($theirs, 0, $this->makeRegister('B'));

        // Le comptable qui contrôle la session ne voit pas les tiroirs du caissier.
        $this->actingAs($this->accountant())
            ->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertDontSee('Mes caisses ouvertes')
            ->assertDontSee(route('finance.cash.sessions.show', $other), false);
    }

    public function test_opening_beyond_the_limit_comes_back_with_a_readable_message(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier, 0, $this->makeRegister('A'));
        $other = $this->makeRegister('B');

        $this->from('/finance/caisse')
            ->actingAs($cashier)
            ->post('/finance/caisse/sessions', ['cash_register_id' => $other->id, 'opening_float' => '0'])
            ->assertRedirect('/finance/caisse')
            ->assertSessionHas('finance_error');

        $this->assertSame(1, CashSession::count());
    }

    // ---------------------------------------------------------- Administration

    public function test_only_the_administrator_sets_a_cashier_limit(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        foreach ([$this->cashier(), $this->accountant()] as $user) {
            $this->actingAs($user)
                ->post(route('finance.registers.access.store'), ['cashier_id' => (string) $cashier->id, 'max_open_sessions' => 3])
                ->assertForbidden();
        }

        $this->assertSame(0, CashierSetting::count());
    }

    public function test_the_administrator_sets_a_limit_and_it_is_audited(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $this->actingAs($this->admin())
            ->post(route('finance.registers.access.store'), ['cashier_id' => (string) $cashier->id, 'max_open_sessions' => 3])
            ->assertRedirect(route('finance.registers.index'))
            ->assertSessionHas('finance_status');

        $setting = CashierSetting::query()->sole();

        $this->assertSame((string) $cashier->id, $setting->cashier_id);
        $this->assertSame(3, $setting->max_open_sessions);
        $this->assertSame(3, CashierSetting::limitFor((string) $cashier->id));
        $this->assertSame(1, AuditLog::where('event', 'cashier_limit_set')->count());
    }

    public function test_an_empty_value_returns_the_cashier_to_the_default(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $admin = $this->admin();

        $this->actingAs($admin)->post(route('finance.registers.access.store'), [
            'cashier_id' => (string) $cashier->id, 'max_open_sessions' => 3,
        ]);

        $this->post(route('finance.registers.access.store'), [
            'cashier_id' => (string) $cashier->id, 'max_open_sessions' => '',
        ])->assertRedirect(route('finance.registers.index'));

        $this->assertNull(CashierSetting::query()->sole()->max_open_sessions);
        $this->assertSame(1, CashierSetting::limitFor((string) $cashier->id));
    }

    public function test_an_unknown_cashier_is_refused(): void
    {
        $this->from('/finance/caisses')
            ->actingAs($this->admin())
            ->post(route('finance.registers.access.store'), ['cashier_id' => 'fantome', 'max_open_sessions' => 2])
            ->assertRedirect('/finance/caisses')
            ->assertSessionHas('finance_error');

        $this->assertSame(0, CashierSetting::count());
    }

    public function test_an_out_of_range_limit_is_a_field_error(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $this->from('/finance/caisses')
            ->actingAs($this->admin())
            ->post(route('finance.registers.access.store'), ['cashier_id' => (string) $cashier->id, 'max_open_sessions' => 0])
            ->assertSessionHasErrors('max_open_sessions');

        $this->assertSame(0, CashierSetting::count());
    }

    public function test_the_registers_screen_lists_known_cashiers_with_their_limit(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $this->actingAs($this->admin())
            ->get('/finance/caisses')
            ->assertOk()
            ->assertSee('Caisses autorisées')
            ->assertSee($cashier->name)
            ->assertSee('Défaut');
    }

    /** Sans hôte pour les déclarer, un caissier n'est connu qu'à sa première session. */
    public function test_without_a_host_a_cashier_who_never_opened_a_session_is_not_listed(): void
    {
        $this->actingAs($this->admin())
            ->get('/finance/caisses')
            ->assertOk()
            ->assertSee('Aucun caissier connu');
    }

    // ------------------------------------------- Affectation aux caisses

    public function test_the_administrator_assigns_registers_to_a_cashier(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $ticket = $this->makeRegister('CAISSE-TICKET');

        $this->actingAs($this->admin())
            ->post(route('finance.registers.access.store'), [
                'cashier_id' => (string) $cashier->id,
                'registers' => [$ticket->id],
            ])
            ->assertRedirect(route('finance.registers.index'))
            ->assertSessionHas('finance_status');

        $this->assertSame([$ticket->id], CashierRegister::assignedIdsFor((string) $cashier->id));
        $this->assertSame(1, AuditLog::where('event', 'cashier_registers_set')->count());
    }

    public function test_unchecking_every_register_gives_access_to_all_of_them_again(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $ticket = $this->makeRegister('CAISSE-TICKET');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('finance.registers.access.store'), [
            'cashier_id' => (string) $cashier->id, 'registers' => [$ticket->id],
        ]);

        $this->post(route('finance.registers.access.store'), ['cashier_id' => (string) $cashier->id]);

        $this->assertSame([], CashierRegister::assignedIdsFor((string) $cashier->id));
        $this->assertTrue(CashierRegister::allows((string) $cashier->id, $ticket->id));
    }

    public function test_the_desk_only_offers_the_registers_the_cashier_is_assigned_to(): void
    {
        $this->allowTwo();

        $cashier = $this->cashier();
        $mine = $this->makeRegister('CAISSE-TICKET');
        $notMine = $this->makeRegister('CAISSE-SERVICES');

        CashierRegister::create(['cashier_id' => (string) $cashier->id, 'cash_register_id' => $mine->id]);

        $this->actingAs($cashier)
            ->get('/finance/caisse')
            ->assertOk()
            ->assertSee('<option value="'.$mine->id.'"', false)
            ->assertDontSee('<option value="'.$notMine->id.'"', false);
    }

    public function test_opening_a_register_one_is_not_assigned_to_is_refused(): void
    {
        $cashier = $this->cashier();
        $mine = $this->makeRegister('CAISSE-TICKET');
        $notMine = $this->makeRegister('CAISSE-SERVICES');

        CashierRegister::create(['cashier_id' => (string) $cashier->id, 'cash_register_id' => $mine->id]);

        $this->from('/finance/caisse')
            ->actingAs($cashier)
            ->post('/finance/caisse/sessions', ['cash_register_id' => $notMine->id, 'opening_float' => '0'])
            ->assertRedirect('/finance/caisse')
            ->assertSessionHas('finance_error');

        $this->assertSame(0, CashSession::count());
    }

    public function test_a_restricted_cashier_is_told_why_no_register_is_offered(): void
    {
        $cashier = $this->cashier();
        $mine = $this->makeRegister('CAISSE-TICKET');
        $this->makeRegister('CAISSE-SERVICES');

        CashierRegister::create(['cashier_id' => (string) $cashier->id, 'cash_register_id' => $mine->id]);

        // Sa seule caisse est tenue par un collègue.
        $this->openSession($this->cashier(), 0, $mine);

        $this->actingAs($cashier)
            ->get('/finance/caisse')
            ->assertOk()
            ->assertSee('auxquelles vous êtes affecté');
    }

    public function test_the_registers_screen_shows_the_assignment_checkboxes(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier);

        $register = $this->makeRegister('CAISSE-TICKET');

        $this->actingAs($this->admin())
            ->get('/finance/caisses')
            ->assertOk()
            ->assertSee('name="registers[]" value="'.$register->id.'"', false);
    }
}
