<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Models\AuditLog;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Queue\PharmacyQueue;
use Keneya\Pharmacie\Queue\QueuedPatient;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakePharmacyQueue;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Le comptoir : FEFO d'office, dispensation partielle assumée, dérogation
 * justifiée, et une annulation qui fait revenir le stock.
 */
class DispensingHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_dispensing_takes_what_expires_first_and_lowers_the_stock(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => 1_000]);
        $location = $this->makeLocation();

        $loin = $this->stockUp($this->makeBatch($product, 'LOT-LOIN', now()->addYear()->toDateString()), 50);
        $proche = $this->stockUp($this->makeBatch($product, 'LOT-PROCHE', now()->addDays(15)->toDateString()), 20);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_id' => 'PAT-000123',
                'patient_name' => 'Aminata Traoré',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '30',
                    'prescribed_quantity' => '30',
                    'posology' => '1 gélule matin et soir',
                ]],
            ])
            ->assertSessionHas('pharmacie_status');

        $dispensation = Dispensation::sole();
        $this->assertStringStartsWith('DIS-', $dispensation->number);
        $this->assertSame(30_000, (int) $dispensation->total);
        $this->assertSame(0, (int) $dispensation->outstanding);
        $this->assertSame('Complète', $dispensation->statusLabel());

        // Le lot qui périme en premier est vidé avant l'autre.
        $this->assertSame(0, (int) Stock::query()->where('batch_id', $proche->id)->value('quantity'));
        $this->assertSame(40, (int) Stock::query()->where('batch_id', $loin->id)->value('quantity'));

        $served = $dispensation->items()->sole()->batches;
        $this->assertSame([20, 10], $served->pluck('quantity')->all());
        $this->assertSame(1, AuditLog::where('event', 'dispensation_recorded')->count());
    }

    public function test_what_is_missing_becomes_a_visible_shortfall(): void
    {
        $product = $this->makeProduct(['name' => 'Sirop']);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 8);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_id' => 'PAT-000200',
                'lines' => [['product_id' => $product->id, 'quantity' => '20', 'prescribed_quantity' => '20']],
            ])
            ->assertSessionHas('pharmacie_status');

        $dispensation = Dispensation::sole();

        $this->assertSame(8, (int) $dispensation->items()->sole()->quantity);
        $this->assertSame(12, (int) $dispensation->outstanding);
        $this->assertSame('Partielle', $dispensation->statusLabel());

        $this->get(route('pharmacie.dispensing.show', $dispensation))
            ->assertOk()
            ->assertSee('Reste à délivrer')
            ->assertSee('12');

        $this->get('/pharmacie/dispensations?statut=partielles')->assertSee($dispensation->number);
    }

    public function test_serving_another_batch_than_fefo_demands_a_reason(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();

        $loin = $this->stockUp($this->makeBatch($product, 'LOT-LOIN', now()->addYear()->toDateString()), 50);
        $this->stockUp($this->makeBatch($product, 'LOT-PROCHE', now()->addDays(10)->toDateString()), 50);

        $dispenser = $this->userWithRole(Rbac::ROLE_DISPENSER);

        // Sans motif : refus, avec le nom du lot qui aurait dû sortir.
        $this->actingAs($dispenser)->from('/pharmacie/comptoir')
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'lines' => [['product_id' => $product->id, 'quantity' => '5', 'batch_id' => $loin->id]],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(0, Dispensation::count());

        // Avec motif : accepté, et la dérogation reste écrite.
        $this->post(route('pharmacie.dispensing.store'), [
            'location_id' => $location->id,
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => '5',
                'batch_id' => $loin->id,
                'override_reason' => 'Lot proche réservé au service de maternité',
            ]],
        ])->assertSessionHas('pharmacie_status');

        $served = Dispensation::sole()->items()->sole()->batches()->sole();
        $this->assertTrue((bool) $served->overrode_fefo);
        $this->assertSame('Lot proche réservé au service de maternité', $served->override_reason);

        $this->get(route('pharmacie.dispensing.show', Dispensation::sole()))->assertSee('Hors FEFO');
    }

    public function test_an_expired_batch_is_never_served_even_if_asked(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $perime = $this->stockUp($this->makeBatch($product, 'LOT-PERIME', now()->subDay()->toDateString()), 30);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))->from('/pharmacie/comptoir')
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'lines' => [['product_id' => $product->id, 'quantity' => '2', 'batch_id' => $perime->id]],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(0, Dispensation::count());
        $this->assertSame(30, (int) Stock::query()->where('batch_id', $perime->id)->value('quantity'));
    }

    public function test_cancelling_brings_the_stock_back_without_erasing_anything(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 40);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'lines' => [['product_id' => $product->id, 'quantity' => '15']],
            ]);

        $dispensation = Dispensation::sole();
        $this->assertSame(25, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));

        // Le préparateur délivre, il n'annule pas.
        $this->from(route('pharmacie.dispensing.show', $dispensation))
            ->post(route('pharmacie.dispensing.cancel', $dispensation), ['reason' => 'Erreur'])
            ->assertForbidden();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.cancel', $dispensation), ['reason' => 'Patient reparti sans les médicaments'])
            ->assertSessionHas('pharmacie_status');

        $dispensation->refresh();
        $this->assertSame(Dispensation::STATUS_CANCELLED, $dispensation->status);
        $this->assertSame(40, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));

        // Rien n'a été effacé : la sortie et son retour se lisent tous deux.
        $movements = StockMovement::query()->where('batch_id', $batch->id)->orderBy('id')->pluck('kind')->all();
        $this->assertSame(['reception', 'dispensation', 'annulation'], $movements);
    }

    public function test_the_counter_prefills_the_patient_called_from_the_queue(): void
    {
        $product = $this->makeProduct();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        $queue = new FakePharmacyQueue([
            new PharmacyQueue('comptoir', 'Comptoir', 1),
        ]);

        $queue->patients['comptoir'] = [
            new QueuedPatient('C-1', 'PAT-000123', 'Aminata Traoré', 'Ordonnance', 'ORD-2026-000097'),
        ];

        $this->app->instance(PharmacyQueueProvider::class, $queue);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->get('/pharmacie/comptoir?file=comptoir&patient=C-1')
            ->assertOk()
            ->assertSee('PAT-000123')
            ->assertSee('Aminata Traoré')
            ->assertSee('ORD-2026-000097');
    }

    public function test_the_slip_can_be_printed(): void
    {
        $product = $this->makeProduct(['name' => 'Paracétamol']);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 20);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'lines' => [['product_id' => $product->id, 'quantity' => '6']],
            ]);

        $this->get(route('pharmacie.dispensing.print', Dispensation::sole()))
            ->assertOk()
            ->assertSee('BON DE SORTIE')
            ->assertSee('Paracétamol')
            ->assertSee('LOT-A');
    }
}
