<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Tariff;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * Écran des tarifs : fixer un prix est une décision de gestion. Le caissier
 * qui encaisse ne la prend jamais.
 */
class TariffsHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    public function test_the_cashier_cannot_set_a_price(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->actingAs($this->cashier())
            ->post(route('finance.catalog.tariffs.store', $act), ['kind' => 'standard', 'amount' => '2000'])
            ->assertForbidden();

        $this->assertSame(0, Tariff::count());
    }

    public function test_the_cashier_does_not_even_see_the_form(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->actingAs($this->cashier())
            ->get(route('finance.catalog.acts.show', $act))
            ->assertOk()
            ->assertDontSee('Fixer ou changer un tarif');

        $this->actingAs($this->accountant())
            ->get(route('finance.catalog.acts.show', $act))
            ->assertOk()
            ->assertSee('Fixer ou changer un tarif');
    }

    public function test_the_accountant_sets_a_price_and_it_is_audited(): void
    {
        $act = $this->makeAct('CONS-GEN', $this->makeCenter('CONSULTATION'));

        $this->actingAs($this->accountant())
            ->post(route('finance.catalog.tariffs.store', $act), [
                // Les espaces d'une saisie « 2 000 » sont nettoyés.
                'kind' => 'standard',
                'amount' => '2 000',
                'effective_from' => '2026-01-01',
            ])
            ->assertRedirect(route('finance.catalog.acts.show', $act))
            ->assertSessionHas('finance_status');

        $tariff = Tariff::query()->sole();

        $this->assertSame(2_000, $tariff->amount);
        $this->assertTrue($tariff->is_active);
        $this->assertSame('2026-01-01', $tariff->effective_from->toDateString());
        $this->assertSame(1, AuditLog::where('event', 'tariff_set')->count());
    }

    public function test_changing_a_price_keeps_the_previous_line_as_history(): void
    {
        $act = $this->makeAct('CONS-GEN');
        $this->setTariff($act, 2_000);

        $this->actingAs($this->accountant())
            ->post(route('finance.catalog.tariffs.store', $act), ['kind' => 'standard', 'amount' => '2500']);

        $this->assertSame(2, $act->tariffs()->count());
        $this->assertSame(2_500, $act->activeTariff()->amount);

        $this->get(route('finance.catalog.acts.show', $act))
            ->assertOk()
            ->assertSee('2 500 FCFA')
            ->assertSee('2 000 FCFA')
            ->assertSee('Historique');
    }

    public function test_an_unchanged_price_comes_back_with_a_readable_message(): void
    {
        $act = $this->makeAct('CONS-GEN');
        $this->setTariff($act, 2_000);

        $this->from(route('finance.catalog.acts.show', $act))
            ->actingAs($this->accountant())
            ->post(route('finance.catalog.tariffs.store', $act), ['kind' => 'standard', 'amount' => '2000'])
            ->assertRedirect(route('finance.catalog.acts.show', $act))
            ->assertSessionHas('finance_error');

        $this->assertSame(1, $act->tariffs()->count());
    }

    public function test_a_deactivated_act_comes_back_with_a_readable_message(): void
    {
        $act = $this->makeAct('CONS-GEN');
        $act->update(['is_active' => false]);

        $this->from(route('finance.catalog.acts.show', $act))
            ->actingAs($this->accountant())
            ->post(route('finance.catalog.tariffs.store', $act), ['kind' => 'standard', 'amount' => '2000'])
            ->assertRedirect(route('finance.catalog.acts.show', $act))
            ->assertSessionHas('finance_error');

        $this->assertSame(0, Tariff::count());
    }

    public function test_a_missing_amount_is_a_field_error(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->from(route('finance.catalog.acts.show', $act))
            ->actingAs($this->accountant())
            ->post(route('finance.catalog.tariffs.store', $act), ['kind' => 'standard'])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Tariff::count());
    }

    public function test_the_administrator_may_price_a_second_context(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->actingAs($this->admin())->post(route('finance.catalog.tariffs.store', $act), [
            'kind' => 'conventionne',
            'label' => 'Tarif conventionné',
            'amount' => '1200',
        ]);

        $this->actingAs($this->admin())->post(route('finance.catalog.tariffs.store', $act), [
            'kind' => 'standard',
            'amount' => '2000',
        ]);

        $this->assertSame(1_200, $act->activeTariff('conventionne')->amount);
        $this->assertSame(2_000, $act->activeTariff()->amount);
    }
}
