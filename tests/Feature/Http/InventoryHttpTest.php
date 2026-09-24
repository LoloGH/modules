<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Inventory;
use Keneya\Pharmacie\Models\Loss;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Inventaires, pertes et destructions : l'écart se regarde en face, se
 * justifie, et se valide par quelqu'un d'autre.
 */
class InventoryHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_a_gap_corrects_the_stock_only_once_validated(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline']);
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 100);

        $storekeeper = $this->userWithRole(Rbac::ROLE_STOREKEEPER);

        $this->actingAs($storekeeper)->post(route('pharmacie.inventory.open'), [
            'location_id' => $location->id,
            'scope' => Inventory::SCOPE_FULL,
        ])->assertSessionHas('pharmacie_status');

        $inventory = Inventory::sole();
        $line = $inventory->lines()->sole();
        $this->assertSame(100, (int) $line->expected_quantity);

        // Compter : rien n'est corrigé.
        $this->post(route('pharmacie.inventory.count', $inventory), [
            'lines' => [$line->id => ['counted' => '94', 'reason' => 'Six flacons introuvables']],
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(-6, (int) $line->refresh()->gap);
        $this->assertSame(100, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));

        // Celui qui a compté ne valide pas.
        $this->from(route('pharmacie.inventory.show', $inventory))
            ->post(route('pharmacie.inventory.validate', $inventory))
            ->assertForbidden();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.inventory.validate', $inventory))
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(Inventory::STATUS_VALIDATED, $inventory->refresh()->status);
        $this->assertSame(94, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));

        // La correction reste au grand livre, avec son motif.
        $movement = StockMovement::query()->where('kind', StockMovement::KIND_ADJUSTMENT)->sole();
        $this->assertSame(-6, (int) $movement->quantity);
        $this->assertStringContainsString('Six flacons introuvables', (string) $movement->reason);
    }

    public function test_the_one_who_counted_never_validates_their_own_count(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->post(route('pharmacie.inventory.open'), [
            'location_id' => $location->id,
            'scope' => Inventory::SCOPE_FULL,
        ]);

        $inventory = Inventory::sole();
        $line = $inventory->lines()->sole();

        $this->post(route('pharmacie.inventory.count', $inventory), [
            'lines' => [$line->id => ['counted' => '10']],
        ]);

        // Le pharmacien a compté : il ne peut pas valider, même s'il en a le droit.
        $this->from(route('pharmacie.inventory.show', $inventory))
            ->post(route('pharmacie.inventory.validate', $inventory))
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(Inventory::STATUS_OPEN, $inventory->refresh()->status);
    }

    public function test_an_unjustified_gap_blocks_the_validation(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 30);

        $this->actingAs($this->userWithRole(Rbac::ROLE_STOREKEEPER))
            ->post(route('pharmacie.inventory.open'), ['location_id' => $location->id, 'scope' => Inventory::SCOPE_FULL]);

        $inventory = Inventory::sole();
        $line = $inventory->lines()->sole();

        // Un écart sans motif.
        $this->post(route('pharmacie.inventory.count', $inventory), [
            'lines' => [$line->id => ['counted' => '25']],
        ]);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->from(route('pharmacie.inventory.show', $inventory))
            ->post(route('pharmacie.inventory.validate', $inventory))
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(30, (int) Stock::query()->sum('quantity'));

        // Une ligne non comptée bloque aussi.
        $line->update(['counted_quantity' => null, 'gap' => 0]);

        $this->from(route('pharmacie.inventory.show', $inventory))
            ->post(route('pharmacie.inventory.validate', $inventory))
            ->assertSessionHas('pharmacie_error');
    }

    public function test_two_inventories_never_run_at_the_same_place(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        $storekeeper = $this->userWithRole(Rbac::ROLE_STOREKEEPER);

        $this->actingAs($storekeeper)
            ->post(route('pharmacie.inventory.open'), ['location_id' => $location->id, 'scope' => Inventory::SCOPE_FULL]);

        $this->from('/pharmacie/inventaires')
            ->post(route('pharmacie.inventory.open'), ['location_id' => $location->id, 'scope' => Inventory::SCOPE_FULL])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(1, Inventory::count());
    }

    public function test_a_destruction_needs_a_witness_and_closes_the_batch(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-PERIME', now()->subDays(10)->toDateString()), 20);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        // Sans témoin, une destruction est refusée.
        $this->actingAs($pharmacist)->from('/pharmacie/pertes')
            ->post(route('pharmacie.inventory.losses.store'), [
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'kind' => Loss::KIND_EXPIRED,
                'quantity' => '20',
                'reason' => 'Lot périmé',
                'destroyed' => '1',
            ])
            ->assertSessionHas('pharmacie_error');

        $this->post(route('pharmacie.inventory.losses.store'), [
            'batch_id' => $batch->id,
            'location_id' => $location->id,
            'kind' => Loss::KIND_EXPIRED,
            'quantity' => '20',
            'reason' => 'Lot périmé, retiré du comptoir',
            'witness' => 'Dr Diallo',
            'destroyed' => '1',
        ])->assertSessionHas('pharmacie_status');

        $loss = Loss::sole();
        $this->assertSame(20, (int) $loss->quantity);
        $this->assertSame(10_000, (int) $loss->value);  // 20 × 500 d'achat
        $this->assertTrue((bool) $loss->destroyed);

        // Le lot vidé par une destruction est marqué : il ne revient pas.
        $this->assertSame(Batch::STATUS_DESTROYED, $batch->refresh()->status);
        $this->assertSame(0, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));
    }

    public function test_a_loss_never_leaves_without_a_reason(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        $this->actingAs($this->userWithRole(Rbac::ROLE_STOREKEEPER))
            ->from('/pharmacie/pertes')
            ->post(route('pharmacie.inventory.losses.store'), [
                'batch_id' => $batch->id,
                'location_id' => $location->id,
                'kind' => Loss::KIND_BROKEN,
                'quantity' => '2',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(10, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));
    }
}
