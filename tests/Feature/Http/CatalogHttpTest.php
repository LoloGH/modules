<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * Écrans du catalogue : tout le monde consulte, seul l'administrateur
 * structure.
 */
class CatalogHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    public function test_everyone_with_access_reads_the_catalog(): void
    {
        $this->makeAct('CONS-GEN', $this->makeCenter('CONSULTATION'));

        foreach ([$this->cashier(), $this->accountant(), $this->admin()] as $user) {
            $this->actingAs($user)->get('/finance/catalogue/actes')->assertOk()->assertSee('CONS-GEN');
            $this->actingAs($user)->get('/finance/catalogue/centres')->assertOk()->assertSee('Consultation');
        }
    }

    public function test_a_user_without_the_view_right_is_refused(): void
    {
        $this->actingAs($this->makeUser())->get('/finance/catalogue/actes')->assertForbidden();
        $this->actingAs($this->makeUser())->get('/finance/catalogue/centres')->assertForbidden();
    }

    public function test_only_the_administrator_structures_the_catalog(): void
    {
        foreach ([$this->cashier(), $this->accountant()] as $user) {
            $this->actingAs($user)
                ->post('/finance/catalogue/centres', ['code' => 'X', 'name' => 'X', 'kind' => 'revenue'])
                ->assertForbidden();

            $this->actingAs($user)
                ->post('/finance/catalogue/actes', ['code' => 'X', 'name' => 'X'])
                ->assertForbidden();
        }

        $this->assertSame(0, AnalyticCenter::count());
        $this->assertSame(0, Act::count());
    }

    public function test_the_administrator_creates_a_center_and_it_is_audited(): void
    {
        $this->actingAs($this->admin())
            ->post('/finance/catalogue/centres', ['code' => 'laboratoire', 'name' => 'Laboratoire', 'kind' => 'revenue'])
            ->assertRedirect(route('finance.catalog.centers.index'));

        $center = AnalyticCenter::query()->sole();

        $this->assertSame('LABORATOIRE', $center->code);
        $this->assertTrue($center->is_active);
        $this->assertNull($center->parent_id);
        $this->assertSame(1, AuditLog::where('event', 'analytic_center_created')->count());
    }

    public function test_a_center_can_be_attached_to_a_parent_and_the_tree_is_readable(): void
    {
        $pole = $this->makeCenter('PLATEAU-TECHNIQUE');

        $this->actingAs($this->admin())->post('/finance/catalogue/centres', [
            'code' => 'LABORATOIRE',
            'name' => 'Laboratoire',
            'kind' => 'revenue',
            'parent_id' => $pole->id,
        ])->assertRedirect(route('finance.catalog.centers.index'));

        $this->assertSame($pole->id, AnalyticCenter::where('code', 'LABORATOIRE')->sole()->parent_id);

        $this->get('/finance/catalogue/centres')->assertOk()->assertSee('Laboratoire');
    }

    public function test_a_duplicate_center_code_is_refused(): void
    {
        $this->makeCenter('LABORATOIRE');

        $this->from('/finance/catalogue/centres')->actingAs($this->admin())
            ->post('/finance/catalogue/centres', ['code' => 'LABORATOIRE', 'name' => 'Autre', 'kind' => 'revenue'])
            ->assertSessionHasErrors('code');

        $this->assertSame(1, AnalyticCenter::count());
    }

    public function test_a_center_is_deactivated_rather_than_deleted(): void
    {
        $center = $this->makeCenter('LABORATOIRE');
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('finance.catalog.centers.toggle', $center))
            ->assertRedirect(route('finance.catalog.centers.index'));

        $this->assertFalse(AnalyticCenter::findOrFail($center->id)->is_active);

        $this->post(route('finance.catalog.centers.toggle', $center));

        $this->assertTrue(AnalyticCenter::findOrFail($center->id)->is_active);
        $this->assertSame(1, AuditLog::where('event', 'analytic_center_deactivated')->count());
        $this->assertSame(1, AuditLog::where('event', 'analytic_center_activated')->count());
    }

    public function test_a_center_carrying_active_acts_cannot_be_deactivated(): void
    {
        $center = $this->makeCenter('LABORATOIRE');
        $this->makeAct('LAB-GE', $center);

        $this->from('/finance/catalogue/centres')->actingAs($this->admin())
            ->post(route('finance.catalog.centers.toggle', $center))
            ->assertRedirect('/finance/catalogue/centres')
            ->assertSessionHas('finance_error');

        $this->assertTrue(AnalyticCenter::findOrFail($center->id)->is_active);
    }

    public function test_a_center_carrying_active_children_cannot_be_deactivated(): void
    {
        $pole = $this->makeCenter('PLATEAU-TECHNIQUE');
        $this->makeCenter('LABORATOIRE', $pole);

        $this->from('/finance/catalogue/centres')->actingAs($this->admin())
            ->post(route('finance.catalog.centers.toggle', $pole))
            ->assertRedirect('/finance/catalogue/centres')
            ->assertSessionHas('finance_error');

        $this->assertTrue(AnalyticCenter::findOrFail($pole->id)->is_active);
    }

    public function test_the_administrator_creates_an_act_and_lands_on_its_sheet(): void
    {
        $center = $this->makeCenter('CONSULTATION');

        $this->actingAs($this->admin())->post('/finance/catalogue/actes', [
            'code' => 'cons-gen',
            'name' => 'Consultation générale',
            'analytic_center_id' => $center->id,
            'dme_service_id' => 12,
            'description' => 'Consultation de médecine générale',
        ]);

        $act = Act::query()->sole();

        $this->assertSame('CONS-GEN', $act->code);
        $this->assertSame($center->id, $act->analytic_center_id);
        $this->assertSame(12, $act->dme_service_id);
        $this->assertTrue($act->is_active);
        $this->assertSame(1, AuditLog::where('event', 'act_created')->count());

        $this->get(route('finance.catalog.acts.show', $act))
            ->assertOk()
            ->assertSee('Consultation générale')
            ->assertSee('Aucun tarif fixé');
    }

    public function test_an_act_is_deactivated_rather_than_deleted(): void
    {
        $act = $this->makeAct('CONS-GEN', $this->makeCenter('CONSULTATION'));

        $this->actingAs($this->admin())->post(route('finance.catalog.acts.toggle', $act))
            ->assertRedirect(route('finance.catalog.acts.index'));

        $this->assertFalse(Act::findOrFail($act->id)->is_active);
        $this->assertSame(1, Act::count());
        $this->assertSame(1, AuditLog::where('event', 'act_deactivated')->count());
    }

    public function test_the_act_list_shows_the_active_standard_tariff(): void
    {
        $act = $this->makeAct('CONS-GEN', $this->makeCenter('CONSULTATION'));
        $this->setTariff($act, 2_000);
        $this->setTariff($act, 2_500);

        $this->actingAs($this->cashier())
            ->get('/finance/catalogue/actes')
            ->assertOk()
            ->assertSee('2 500 FCFA')
            ->assertDontSee('2 000 FCFA');
    }

    public function test_the_act_list_says_when_a_price_is_missing(): void
    {
        $this->makeAct('CONS-GEN');

        $this->actingAs($this->cashier())
            ->get('/finance/catalogue/actes')
            ->assertOk()
            ->assertSee('Non fixé');
    }
}
