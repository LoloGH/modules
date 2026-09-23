<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Services\AlertCenter;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Les alertes : ce qui attend un geste, pour celui qui peut le faire.
 */
class AlertsHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_an_empty_pharmacy_has_nothing_to_signal(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/alertes')
            ->assertOk()
            ->assertSee('Rien à signaler');
    }

    public function test_expired_and_expiring_batches_are_raised(): void
    {
        $product = $this->makeProduct(['name' => 'Sirop', 'min_threshold' => 0]);
        $this->stockUp($this->makeBatch($product, 'LOT-PERIME', now()->subDays(5)->toDateString()), 12);
        $this->stockUp($this->makeBatch($product, 'LOT-PROCHE', now()->addDays(20)->toDateString()), 30);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/alertes')
            ->assertOk()
            ->assertSee('1 lot(s) périmé(s) encore en stock')
            ->assertSee('À faire maintenant')
            ->assertSee('périment dans moins de 90 jours');
    }

    public function test_shortages_are_raised_with_a_way_to_order(): void
    {
        $this->makeProduct(['name' => 'Produit en rupture', 'min_threshold' => 10]);
        $bas = $this->makeProduct(['name' => 'Produit au plus bas', 'min_threshold' => 50]);
        $this->stockUp($this->makeBatch($bas, 'LOT-B', now()->addYear()->toDateString()), 10);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/alertes')
            ->assertOk()
            ->assertSee('1 produit(s) en rupture')
            ->assertSee('Produit en rupture')
            ->assertSee('1 produit(s) sous leur seuil')
            ->assertSee(route('pharmacie.stock.index', ['alerte' => 'rupture']), false);
    }

    public function test_a_shortfall_and_an_unsent_bill_are_raised_to_the_counter(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => 1_000, 'min_threshold' => 0]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 5);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_id' => 'PAT-000123',
                'lines' => [['product_id' => $product->id, 'quantity' => '20', 'prescribed_quantity' => '20']],
            ]);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->get('/pharmacie/alertes')
            ->assertOk()
            ->assertSee('1 dispensation(s) avec un reliquat')
            ->assertSee('1 dispensation(s) à envoyer à la caisse');
    }

    public function test_each_alert_is_addressed_to_who_can_act_on_it(): void
    {
        $product = $this->makeProduct(['name' => 'Sirop', 'min_threshold' => 0]);
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-BLOQUE', now()->addYear()->toDateString()), 10);
        $batch->update(['status' => Batch::STATUS_BLOCKED, 'block_reason' => 'Rappel']);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);
        $dispenser = $this->userWithRole(Rbac::ROLE_DISPENSER);

        // Le lot bloqué regarde qui lit le stock : le préparateur le lit aussi.
        $this->assertGreaterThan(0, app(AlertCenter::class)->count($pharmacist));

        // Une commande en attente ne regarde que qui réceptionne.
        $this->actingAs($dispenser)->get('/pharmacie/alertes')
            ->assertOk()
            ->assertDontSee('commande(s) en attente de livraison');
    }

    public function test_the_bell_carries_the_count_on_every_page(): void
    {
        $this->makeProduct(['name' => 'Produit en rupture', 'min_threshold' => 5]);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie')
            ->assertOk()
            ->assertSee('alerte(s) a traiter')
            ->assertSee(route('pharmacie.alerts.index'), false);
    }
}
