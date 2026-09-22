<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Cashiers\HostCashier;
use Keneya\FinanceCaisse\Cashiers\NoCashierDirectory;
use Keneya\FinanceCaisse\Contracts\CashierDirectory;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Les caissiers déclarés par l'hôte apparaissent sur l'écran « Caisses » avant
 * même leur première session : on règle d'avance qui ouvre quelles caisses.
 */
class CashierDirectoryHttpTest extends HttpTestCase
{
    /**
     * @param  list<HostCashier>  $cashiers
     */
    private function hostDeclares(array $cashiers): void
    {
        $this->app->instance(CashierDirectory::class, new class($cashiers) implements CashierDirectory
        {
            /** @param list<HostCashier> $cashiers */
            public function __construct(private readonly array $cashiers) {}

            public function cashiers(): array
            {
                return $this->cashiers;
            }
        });
    }

    public function test_without_a_host_the_directory_is_empty(): void
    {
        $this->assertInstanceOf(NoCashierDirectory::class, Finance::cashiers());
        $this->assertSame([], Finance::cashiers()->cashiers());
    }

    public function test_declared_cashiers_are_listed_before_their_first_session(): void
    {
        $awa = $this->cashier();
        $this->hostDeclares([new HostCashier((string) $awa->id, 'Awa Keita', 'Caissière')]);

        $this->actingAs($this->admin())
            ->get('/finance/caisses')
            ->assertOk()
            ->assertSee('Awa Keita')
            ->assertSee('Caissière')
            ->assertDontSee('Aucun caissier connu');

        $this->assertSame(0, CashSession::count());
    }

    public function test_registers_can_be_assigned_in_advance_and_the_rule_applies_at_first_opening(): void
    {
        $awa = $this->cashier();
        $this->hostDeclares([new HostCashier((string) $awa->id, 'Awa Keita')]);

        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        $this->actingAs($this->admin())
            ->post(route('finance.registers.access.store'), [
                'cashier_id' => (string) $awa->id,
                'registers' => [$ticket->id],
                'max_open_sessions' => '1',
            ])
            ->assertRedirect(route('finance.registers.index'))
            ->assertSessionHas('finance_status');

        $this->assertSame([$ticket->id], CashierRegister::assignedIdsFor((string) $awa->id));
        $this->assertSame('Awa Keita', CashierSetting::query()->where('cashier_id', (string) $awa->id)->value('cashier_name'));

        // Première ouverture : seule la caisse affectée est proposée, l'autre refusée.
        $this->actingAs($awa)->get('/finance/caisse')
            ->assertSee('<option value="'.$ticket->id.'"', false)
            ->assertDontSee('<option value="'.$services->id.'"', false);

        $this->from('/finance/caisse')
            ->post('/finance/caisse/sessions', ['cash_register_id' => $services->id, 'opening_float' => '0'])
            ->assertSessionHas('finance_error');

        $this->post('/finance/caisse/sessions', ['cash_register_id' => $ticket->id, 'opening_float' => '0'])
            ->assertRedirect();

        $this->assertSame($ticket->id, CashSession::query()->sole()->cash_register_id);
    }

    public function test_a_cashier_neither_declared_nor_known_is_still_refused(): void
    {
        $this->hostDeclares([new HostCashier('1', 'Awa Keita')]);

        $this->actingAs($this->admin())
            ->post(route('finance.registers.access.store'), ['cashier_id' => '999', 'registers' => []])
            ->assertSessionHas('finance_error');

        $this->assertSame(0, CashierRegister::count());
    }

    public function test_a_former_cashier_with_history_stays_listed(): void
    {
        $ancien = $this->cashier();
        $this->openSession($ancien);
        $this->hostDeclares([new HostCashier('4242', 'Nouvelle recrue')]);

        $this->actingAs($this->admin())
            ->get('/finance/caisses')
            ->assertSee('Nouvelle recrue')
            ->assertSee($ancien->name);
    }
}
