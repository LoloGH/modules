<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Models\AuditLog;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\PurchaseOrder;
use Keneya\Pharmacie\Models\Reception;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\Supplier;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * L'approvisionnement : commander, recevoir, et faire entrer le stock, avec
 * ce que la réception refuse.
 */
class SupplyHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    private function supplier(): Supplier
    {
        return Supplier::create([
            'facility_id' => 1,
            'code' => 'UBIPHARM',
            'name' => 'Ubipharm Mali',
            'lead_time_days' => 7,
            'is_active' => true,
        ]);
    }

    public function test_the_cycle_goes_from_order_to_stock(): void
    {
        $supplier = $this->supplier();
        $location = $this->makeLocation();
        $product = $this->makeProduct(['name' => 'Amoxicilline']);
        $storekeeper = $this->userWithRole(Rbac::ROLE_STOREKEEPER);

        // Commander.
        $this->actingAs($storekeeper)
            ->post(route('pharmacie.supply.orders.store'), [
                'supplier_id' => $supplier->id,
                'lines' => [['product_id' => $product->id, 'quantity' => '100', 'unit_price' => '500']],
            ])
            ->assertSessionHas('pharmacie_status');

        $order = PurchaseOrder::sole();
        $this->assertStringStartsWith('CMD-', $order->number);
        $this->assertSame(50_000, (int) $order->total);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $order->status);

        // Envoyer : la commande devient un engagement.
        $this->post(route('pharmacie.supply.orders.send', $order))->assertSessionHas('pharmacie_status');
        $this->assertSame(PurchaseOrder::STATUS_SENT, $order->refresh()->status);

        // Recevoir la moitié : la commande le dit.
        $this->post(route('pharmacie.supply.receptions.store'), [
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'purchase_order_id' => $order->id,
            'delivery_note' => 'BL-4471',
            'lines' => [[
                'product_id' => $product->id,
                'batch_number' => 'LOT-2026-A',
                'quantity' => '60',
                'expires_on' => now()->addYear()->toDateString(),
                'unit_price' => '500',
            ]],
        ])->assertSessionHas('pharmacie_status');

        $reception = Reception::sole();
        $this->assertStringStartsWith('REC-', $reception->number);
        $this->assertSame(30_000, (int) $reception->total);
        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->refresh()->status);
        $this->assertSame(40, $order->outstanding());

        // Le lot est né, et le stock est entré par le grand livre.
        $batch = Batch::sole();
        $this->assertSame('LOT-2026-A', $batch->number);
        $this->assertSame($supplier->name, $batch->supplier_name);
        $this->assertSame(60, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));
        $this->assertSame(1, AuditLog::where('event', 'reception_recorded')->count());

        // Le reste : la commande est servie.
        $this->post(route('pharmacie.supply.receptions.store'), [
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'purchase_order_id' => $order->id,
            'lines' => [[
                'product_id' => $product->id,
                'batch_number' => 'LOT-2026-B',
                'quantity' => '40',
                'expires_on' => now()->addYear()->toDateString(),
                'unit_price' => '500',
            ]],
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->refresh()->status);
        $this->assertSame(0, $order->outstanding());
    }

    public function test_a_reception_refuses_what_would_make_the_stock_unreadable(): void
    {
        $supplier = $this->supplier();
        $location = $this->makeLocation();
        $product = $this->makeProduct(['name' => 'Amoxicilline']);
        $storekeeper = $this->userWithRole(Rbac::ROLE_STOREKEEPER);

        $base = [
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
        ];

        // Sans peremption pour un medicament : refus.
        $this->actingAs($storekeeper)->from('/pharmacie/receptions')
            ->post(route('pharmacie.supply.receptions.store'), $base + [
                'lines' => [['product_id' => $product->id, 'batch_number' => 'LOT-A', 'quantity' => '10']],
            ])
            ->assertSessionHas('pharmacie_error');

        // Deja perime : refus.
        $this->from('/pharmacie/receptions')
            ->post(route('pharmacie.supply.receptions.store'), $base + [
                'lines' => [[
                    'product_id' => $product->id, 'batch_number' => 'LOT-A', 'quantity' => '10',
                    'expires_on' => now()->subDay()->toDateString(),
                ]],
            ])
            ->assertSessionHas('pharmacie_error');

        // Sans numero de lot : refuse par la validation.
        $this->from('/pharmacie/receptions')
            ->post(route('pharmacie.supply.receptions.store'), $base + [
                'lines' => [['product_id' => $product->id, 'quantity' => '10', 'expires_on' => now()->addYear()->toDateString()]],
            ])
            ->assertSessionHasErrors('lines.0.batch_number');

        $this->assertSame(0, Reception::count());
        $this->assertSame(0, Batch::count());
    }

    public function test_the_same_batch_number_never_carries_two_expiry_dates(): void
    {
        $supplier = $this->supplier();
        $location = $this->makeLocation();
        $product = $this->makeProduct();
        $storekeeper = $this->userWithRole(Rbac::ROLE_STOREKEEPER);

        $line = [
            'product_id' => $product->id,
            'batch_number' => 'LOT-A',
            'quantity' => '10',
            'expires_on' => now()->addYear()->toDateString(),
        ];

        $this->actingAs($storekeeper)
            ->post(route('pharmacie.supply.receptions.store'), [
                'supplier_id' => $supplier->id, 'location_id' => $location->id, 'lines' => [$line],
            ])
            ->assertSessionHas('pharmacie_status');

        // Meme numero, autre date : on refuse plutot que d'ecraser.
        $this->from('/pharmacie/receptions')
            ->post(route('pharmacie.supply.receptions.store'), [
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                // array_merge, et non « + » : l'union de tableaux garde la
                // valeur de gauche, la date n'aurait pas change.
                'lines' => [array_merge($line, ['expires_on' => now()->addYears(2)->toDateString()])],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(1, Batch::count());

        // Meme numero, meme date : les quantites s'additionnent.
        $this->post(route('pharmacie.supply.receptions.store'), [
            'supplier_id' => $supplier->id, 'location_id' => $location->id, 'lines' => [$line],
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(1, Batch::count());
        $this->assertSame(20, (int) Stock::query()->where('batch_id', Batch::sole()->id)->value('quantity'));
    }

    public function test_nothing_is_received_beyond_what_the_order_still_expects(): void
    {
        $supplier = $this->supplier();
        $location = $this->makeLocation();
        $product = $this->makeProduct();
        $storekeeper = $this->userWithRole(Rbac::ROLE_STOREKEEPER);

        $this->actingAs($storekeeper)->post(route('pharmacie.supply.orders.store'), [
            'supplier_id' => $supplier->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '20', 'unit_price' => '100']],
        ]);

        $order = PurchaseOrder::sole();
        $this->post(route('pharmacie.supply.orders.send', $order));

        $this->from('/pharmacie/receptions')
            ->post(route('pharmacie.supply.receptions.store'), [
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'purchase_order_id' => $order->id,
                'lines' => [[
                    'product_id' => $product->id, 'batch_number' => 'LOT-A', 'quantity' => '21',
                    'expires_on' => now()->addYear()->toDateString(),
                ]],
            ])
            ->assertSessionHas('pharmacie_error');

        // Rien n'est entre : la reception est tout ou rien.
        $this->assertSame(0, Reception::count());
        $this->assertSame(0, Batch::count());
        $this->assertSame(0, (int) Stock::query()->sum('quantity'));
    }

    public function test_an_order_already_delivered_is_not_cancelled(): void
    {
        $supplier = $this->supplier();
        $location = $this->makeLocation();
        $product = $this->makeProduct();
        $storekeeper = $this->userWithRole(Rbac::ROLE_STOREKEEPER);

        $this->actingAs($storekeeper)->post(route('pharmacie.supply.orders.store'), [
            'supplier_id' => $supplier->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '10', 'unit_price' => '100']],
        ]);

        $order = PurchaseOrder::sole();
        $this->post(route('pharmacie.supply.orders.send', $order));

        // Sans motif, pas d'annulation.
        $this->from(route('pharmacie.supply.orders.show', $order))
            ->post(route('pharmacie.supply.orders.cancel', $order))
            ->assertSessionHasErrors('reason');

        $this->post(route('pharmacie.supply.receptions.store'), [
            'supplier_id' => $supplier->id,
            'location_id' => $location->id,
            'purchase_order_id' => $order->id,
            'lines' => [[
                'product_id' => $product->id, 'batch_number' => 'LOT-A', 'quantity' => '4',
                'expires_on' => now()->addYear()->toDateString(),
            ]],
        ])->assertSessionHas('pharmacie_status');

        $this->from(route('pharmacie.supply.orders.show', $order))
            ->post(route('pharmacie.supply.orders.cancel', $order), ['reason' => 'Fournisseur en rupture'])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(PurchaseOrder::STATUS_PARTIAL, $order->refresh()->status);
    }

    public function test_the_order_screen_suggests_what_is_under_threshold(): void
    {
        $this->supplier();
        $manquant = $this->makeProduct(['name' => 'Produit manquant', 'min_threshold' => 30, 'max_threshold' => 100]);
        $suffisant = $this->makeProduct(['name' => 'Produit suffisant', 'min_threshold' => 5]);

        $this->stockUp($this->makeBatch($suffisant, 'LOT-S', now()->addYear()->toDateString()), 50);

        $this->actingAs($this->userWithRole(Rbac::ROLE_STOREKEEPER))
            ->get('/pharmacie/commandes')
            ->assertOk()
            ->assertSee('À commander')
            // Le produit manquant est suggere ; celui qui est fourni ne l'est
            // pas (il figure ailleurs, dans la liste de choix du formulaire).
            ->assertSeeInOrder(['À commander', 'Produit manquant', 'Nouvelle commande']);
    }

    public function test_receiving_and_reading_are_two_rights(): void
    {
        $supplier = $this->supplier();
        $location = $this->makeLocation();
        $product = $this->makeProduct();

        // Le preparateur lit l'approvisionnement, il ne recoit pas.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->get('/pharmacie/receptions')->assertOk()->assertDontSee('Enregistrer une réception');

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.supply.receptions.store'), [
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'lines' => [[
                    'product_id' => $product->id, 'batch_number' => 'LOT-A', 'quantity' => '5',
                    'expires_on' => now()->addYear()->toDateString(),
                ]],
            ])
            ->assertForbidden();

        $this->assertSame(0, Reception::count());
    }
}
