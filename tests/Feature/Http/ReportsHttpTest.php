<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Loss;
use Keneya\Pharmacie\Services\Analytics;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Analyse et prévision : la consommation se lit du grand livre, ce qui est
 * revenu ne compte pas, et le besoin se calcule sur le rythme observé.
 */
class ReportsHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_consumption_counts_what_left_minus_what_came_back(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => 1_000]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 100);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->post(route('pharmacie.dispensing.store'), [
            'location_id' => $location->id,
            'patient_name' => 'Aminata Traoré',
            'lines' => [['product_id' => $product->id, 'quantity' => '30', 'prescribed_quantity' => '30']],
        ])->assertSessionHas('pharmacie_status');

        $this->post(route('pharmacie.dispensing.store'), [
            'location_id' => $location->id,
            'patient_name' => 'Moussa Keïta',
            'lines' => [['product_id' => $product->id, 'quantity' => '10', 'prescribed_quantity' => '10']],
        ]);

        // La seconde est annulée : le produit est revenu, il n'a pas été
        // consommé.
        $cancelled = Dispensation::query()->latest('id')->first();
        $this->post(route('pharmacie.dispensing.cancel', $cancelled), ['reason' => 'Erreur de comptoir']);

        $summary = app(Analytics::class)->summary(Carbon::today()->subDays(7), Carbon::today());

        $this->assertSame(30, $summary['consumed_units']);
        // 30 unités à 500 FCFA d'achat, et il reste 70 unités valorisées.
        $this->assertSame(15_000, $summary['consumed_value']);
        $this->assertSame(35_000, $summary['stock_value']);
        $this->assertSame(1, $summary['dispensations']);
    }

    public function test_the_forecast_deducts_the_stock_and_what_is_already_ordered(): void
    {
        config([
            'pharmacie.forecast.horizon_days' => 30,
            'pharmacie.forecast.lead_time_days' => 10,
            'pharmacie.forecast.safety_days' => 0,
        ]);

        $product = $this->makeProduct(['name' => 'Paracétamol']);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 100);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'lines' => [['product_id' => $product->id, 'quantity' => '40', 'prescribed_quantity' => '40']],
            ]);

        // Une seule journée observée : 40 par jour. Il faut couvrir
        // 30 jours d'horizon plus 10 jours de délai, soit 1 600 unités,
        // moins les 60 qui restent en stock. Et comme le stock ne tient
        // qu'un jour et demi, la rupture arrive avant la livraison.
        $rows = app(Analytics::class)->forecast(Carbon::today(), Carbon::today());
        $row = $rows->firstWhere(fn (array $row): bool => (int) $row['product']->id === (int) $product->id);

        $this->assertSame(60, $row['on_hand']);
        $this->assertSame(1_540, $row['needed']);
        $this->assertSame('rupture', $row['risk']);
    }

    public function test_the_reports_screen_shows_the_period_and_the_losses(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline']);
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->subDay()->toDateString()), 20);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.inventory.losses.store'), [
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'kind' => Loss::KIND_EXPIRED,
                'quantity' => '20',
                'reason' => 'Lot périmé',
                'witness' => 'Dr Diallo',
                'destroyed' => '1',
            ])->assertSessionHas('pharmacie_status');

        $this->get(route('pharmacie.reports.index'))
            ->assertOk()
            ->assertSee('Pertes de la période')
            ->assertSee('Perime');

        $this->get(route('pharmacie.reports.forecast'))
            ->assertOk()
            ->assertSee('Prévision de réapprovisionnement', false);
    }

    public function test_the_export_gives_the_same_figures_as_the_screen(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'code' => 'AMOX500']);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 50);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'lines' => [['product_id' => $product->id, 'quantity' => '12', 'prescribed_quantity' => '12']],
            ]);

        $response = $this->get(route('pharmacie.reports.export', ['quoi' => 'consommation']));
        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('AMOX500', $csv);
        $this->assertStringContainsString('12;6000', $csv);
    }

    public function test_the_dashboard_says_what_the_stock_holds(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline']);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addDays(10)->toDateString()), 40);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'lines' => [['product_id' => $product->id, 'quantity' => '5', 'prescribed_quantity' => '5']],
            ]);

        $figures = app(Analytics::class)->dashboard();

        $this->assertSame(1, $figures['products_in_stock']);
        $this->assertSame(17_500, $figures['stock_value']);
        $this->assertSame(1, $figures['expiring']);
        $this->assertSame(1, $figures['dispensations_today']);

        $this->get(route('pharmacie.home'))->assertOk()->assertSee('Dispensations du jour');
    }

    public function test_reading_reports_is_a_right_of_its_own(): void
    {
        // Le préparateur sert au comptoir : il ne lit pas les rapports.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->get(route('pharmacie.reports.index'))
            ->assertForbidden();
    }
}
