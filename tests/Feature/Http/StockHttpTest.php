<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Models\AuditLog;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Les écrans du stock : trois chiffres qui ne se confondent pas, l'histoire
 * d'un lot, les péremptions qui arrivent, et les corrections qui laissent une
 * trace.
 */
class StockHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_the_stock_screen_separates_physical_reserved_and_available(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'min_threshold' => 20]);
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 100);

        app(StockLedger::class)->reserve($batch, $location, 40);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/stock')
            ->assertOk()
            ->assertSee('Amoxicilline')
            ->assertSee('Suffisant')
            ->assertSeeInOrder(['100', '40', '60']);
    }

    public function test_the_screen_shows_what_is_out_of_stock_and_what_is_under_threshold(): void
    {
        $rupture = $this->makeProduct(['name' => 'Produit en rupture', 'min_threshold' => 10]);
        $bas = $this->makeProduct(['name' => 'Produit au plus bas', 'min_threshold' => 50]);

        $this->stockUp($this->makeBatch($bas, 'LOT-B', now()->addYear()->toDateString()), 10);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->get('/pharmacie/stock?alerte=rupture')
            ->assertSee('Produit en rupture')->assertDontSee('Produit au plus bas');

        $this->get('/pharmacie/stock?alerte=seuil')
            ->assertSee('Produit au plus bas')
            ->assertSee('Sous le seuil (50)');
    }

    public function test_the_expiry_screen_lists_what_expires_and_what_already_did(): void
    {
        $product = $this->makeProduct(['name' => 'Sirop']);
        $this->stockUp($this->makeBatch($product, 'LOT-PROCHE', now()->addDays(10)->toDateString()), 20);
        $this->stockUp($this->makeBatch($product, 'LOT-PERIME', now()->subDays(3)->toDateString()), 5);
        $this->stockUp($this->makeBatch($product, 'LOT-LOIN', now()->addYears(2)->toDateString()), 80);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/stock/peremptions')
            ->assertOk()
            ->assertSee('LOT-PROCHE')
            ->assertSee('LOT-PERIME')
            ->assertSee('Périmé')
            ->assertDontSee('LOT-LOIN');
    }

    public function test_an_adjustment_needs_a_reason_and_leaves_a_trace(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 30);

        $this->actingAs($this->userWithRole(Rbac::ROLE_STOREKEEPER))
            ->post(route('pharmacie.stock.adjust'), [
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'direction' => 'sortie',
                'quantity' => '4',
                'reason' => 'Flacon cassé au comptoir',
            ])
            ->assertRedirect(route('pharmacie.stock.batches.show', $batch))
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(26, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));
        $this->assertSame(1, AuditLog::where('event', 'stock_adjusted')->count());

        // Sans motif, rien ne bouge.
        $this->from(route('pharmacie.stock.batches.show', $batch))
            ->post(route('pharmacie.stock.adjust'), [
                'batch_id' => $batch->id, 'location_id' => $location->id,
                'direction' => 'sortie', 'quantity' => '1',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(26, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));
    }

    public function test_a_batch_is_blocked_and_its_history_stays_readable(): void
    {
        $product = $this->makeProduct();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-RAPPEL', now()->addYear()->toDateString()), 40);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.stock.batches.toggle', $batch), ['reason' => 'Rappel du fournisseur'])
            ->assertSessionHas('pharmacie_status');

        $batch->refresh();
        $this->assertSame(Batch::STATUS_BLOCKED, $batch->status);
        $this->assertFalse($batch->isDispensable());

        $this->get(route('pharmacie.stock.batches.show', $batch))
            ->assertOk()
            ->assertSee('Rappel du fournisseur')
            ->assertSee('Réception')
            ->assertSee('Bloqué');

        $this->assertSame(1, AuditLog::where('event', 'batch_blocked')->count());
    }

    public function test_a_location_holding_stock_is_not_deactivated(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 12, $location);

        $this->actingAs($this->userWithRole(Rbac::ROLE_STOREKEEPER))
            ->from('/pharmacie/stock/emplacements')
            ->post(route('pharmacie.stock.locations.toggle', $location))
            ->assertSessionHas('pharmacie_error');

        $this->assertTrue($location->refresh()->is_active);
    }

    public function test_reading_the_stock_and_correcting_it_are_two_rights(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        // Le préparateur lit le stock, il ne le corrige pas.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))->get('/pharmacie/stock')->assertOk();

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.stock.adjust'), [
                'batch_id' => $batch->id, 'location_id' => $location->id,
                'direction' => 'sortie', 'quantity' => '1', 'reason' => 'Essai',
            ])
            ->assertForbidden();

        $this->assertSame(1, StockMovement::query()->count());
    }
}
