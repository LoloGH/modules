<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashRegister;

class RegistersHttpTest extends HttpTestCase
{
    public function test_only_the_administrator_manages_registers(): void
    {
        $this->actingAs($this->cashier())->get('/finance/caisses')->assertForbidden();
        $this->actingAs($this->accountant())->get('/finance/caisses')->assertForbidden();
        $this->actingAs($this->cashier())->post('/finance/caisses', ['code' => 'X', 'name' => 'X'])->assertForbidden();

        $this->actingAs($this->admin())->get('/finance/caisses')->assertOk();
    }

    public function test_the_administrator_creates_a_register_and_it_is_audited(): void
    {
        $this->actingAs($this->admin())
            ->post('/finance/caisses', ['code' => 'caisse-ticket', 'name' => 'Caisse Ticket'])
            ->assertRedirect(route('finance.registers.index'));

        $register = CashRegister::query()->sole();

        $this->assertSame('CAISSE-TICKET', $register->code);
        $this->assertTrue($register->is_active);
        $this->assertSame(1, AuditLog::where('event', 'register_created')->count());

        $this->get('/finance/caisses')->assertOk()->assertSee('Caisse Ticket');
    }

    public function test_a_duplicate_code_is_refused(): void
    {
        $this->makeRegister('CAISSE-1');

        $this->from('/finance/caisses')->actingAs($this->admin())
            ->post('/finance/caisses', ['code' => 'CAISSE-1', 'name' => 'Autre'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, CashRegister::count());
    }

    public function test_a_register_can_be_deactivated_and_reactivated(): void
    {
        $register = $this->makeRegister();
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('finance.registers.toggle', $register))
            ->assertRedirect(route('finance.registers.index'));
        $this->assertFalse(CashRegister::findOrFail($register->id)->is_active);

        $this->post(route('finance.registers.toggle', $register));
        $this->assertTrue(CashRegister::findOrFail($register->id)->is_active);

        $this->assertSame(1, AuditLog::where('event', 'register_deactivated')->count());
        $this->assertSame(1, AuditLog::where('event', 'register_activated')->count());
    }

    public function test_a_register_with_an_open_session_cannot_be_deactivated(): void
    {
        $register = $this->makeRegister();
        $this->openSession($this->cashier(), 0, $register);

        $this->from('/finance/caisses')->actingAs($this->admin())
            ->post(route('finance.registers.toggle', $register))
            ->assertRedirect('/finance/caisses')
            ->assertSessionHas('finance_error');

        $this->assertTrue(CashRegister::findOrFail($register->id)->is_active);
    }

    public function test_a_deactivated_register_is_no_longer_offered_at_the_desk(): void
    {
        $register = $this->makeRegister('CAISSE-1');

        $this->actingAs($this->admin())->post(route('finance.registers.toggle', $register));

        // On cherche l'option de la liste, pas le nom : le message de la
        // requête précédente (« La caisse « … » est désactivée ») porte le nom.
        $this->actingAs($this->cashier())
            ->get('/finance/caisse')
            ->assertOk()
            ->assertSee('Aucune caisse active')
            ->assertDontSee('<option value="'.$register->id.'"', false);
    }
}
