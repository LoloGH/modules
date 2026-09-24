<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Models\Transfer;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Les transferts : le stock sort d'un côté, entre de l'autre, et entre les
 * deux il est en transit — visible, et à personne.
 */
class TransfersHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_the_cycle_moves_the_stock_only_twice(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline']);
        $centrale = $this->makeLocation('CENTRALE', 'Pharmacie centrale');
        $urgences = $this->makeLocation('URGENCES', 'Urgences');
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 100, $centrale);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        // Demander.
        $this->actingAs($pharmacist)->post(route('pharmacie.transfers.store'), [
            'from_location_id' => $centrale->id,
            'to_location_id' => $urgences->id,
            'reason' => 'Réassort des urgences',
            'lines' => [['product_id' => $product->id, 'quantity' => '30']],
        ])->assertSessionHas('pharmacie_status');

        $transfer = Transfer::sole();
        $this->assertStringStartsWith('TRF-', $transfer->number);
        $this->assertSame(Transfer::STATUS_REQUESTED, $transfer->status);

        // Tant qu'il n'est pas parti, rien n'a bougé.
        $this->assertSame(100, (int) Stock::query()->where('batch_id', $batch->id)->where('location_id', $centrale->id)->value('quantity'));

        $this->post(route('pharmacie.transfers.decide', $transfer), ['decision' => 'approve']);
        $this->post(route('pharmacie.transfers.send', $transfer->refresh()));

        $transfer->refresh();
        $this->assertTrue($transfer->isInTransit());

        // Sorti de la centrale, pas encore arrivé aux urgences.
        $this->assertSame(70, (int) Stock::query()->where('batch_id', $batch->id)->where('location_id', $centrale->id)->value('quantity'));
        $this->assertNull(Stock::query()->where('batch_id', $batch->id)->where('location_id', $urgences->id)->value('quantity'));

        $line = $transfer->lines()->sole();
        $this->post(route('pharmacie.transfers.receive', $transfer), [
            'lines' => [$line->id => ['quantity' => '30']],
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(Transfer::STATUS_RECEIVED, $transfer->refresh()->status);
        $this->assertSame(30, (int) Stock::query()->where('batch_id', $batch->id)->where('location_id', $urgences->id)->value('quantity'));

        // Deux mouvements, et deux seulement : une sortie, une entrée.
        $kinds = StockMovement::query()->where('document_number', $transfer->number)->pluck('kind')->all();
        $this->assertSame(['transfert_sortie', 'transfert_entree'], $kinds);
    }

    public function test_a_gap_on_arrival_must_be_justified(): void
    {
        $product = $this->makeProduct();
        $centrale = $this->makeLocation('CENTRALE', 'Pharmacie centrale');
        $urgences = $this->makeLocation('URGENCES', 'Urgences');
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 50, $centrale);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->post(route('pharmacie.transfers.store'), [
            'from_location_id' => $centrale->id,
            'to_location_id' => $urgences->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '20']],
        ]);

        $transfer = Transfer::sole();
        $this->post(route('pharmacie.transfers.decide', $transfer), ['decision' => 'approve']);
        $this->post(route('pharmacie.transfers.send', $transfer->refresh()));

        $line = $transfer->lines()->sole();

        // Moins que parti, sans motif : refus.
        $this->from(route('pharmacie.transfers.show', $transfer))
            ->post(route('pharmacie.transfers.receive', $transfer), [
                'lines' => [$line->id => ['quantity' => '18']],
            ])
            ->assertSessionHas('pharmacie_error');

        // Plus que parti : impossible.
        $this->from(route('pharmacie.transfers.show', $transfer))
            ->post(route('pharmacie.transfers.receive', $transfer), [
                'lines' => [$line->id => ['quantity' => '25']],
            ])
            ->assertSessionHas('pharmacie_error');

        // Avec un motif, l'écart est accepté et reste lisible.
        $this->post(route('pharmacie.transfers.receive', $transfer), [
            'lines' => [$line->id => ['quantity' => '18', 'gap_reason' => 'Deux flacons cassés au transport']],
        ])->assertSessionHas('pharmacie_status');

        $line->refresh();
        $this->assertSame(18, (int) $line->received_quantity);
        $this->assertSame(2, $line->gap());
        $this->assertSame('Deux flacons cassés au transport', $line->gap_reason);

        // Les deux unités perdues ne sont nulle part : elles ne sont pas
        // entrées, et c'est écrit.
        $this->assertSame(18, (int) Stock::query()->where('location_id', $urgences->id)->sum('quantity'));
    }

    public function test_a_transfer_refuses_what_the_origin_does_not_have(): void
    {
        $product = $this->makeProduct();
        $centrale = $this->makeLocation('CENTRALE', 'Pharmacie centrale');
        $urgences = $this->makeLocation('URGENCES', 'Urgences');
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 5, $centrale);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->post(route('pharmacie.transfers.store'), [
            'from_location_id' => $centrale->id,
            'to_location_id' => $urgences->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '20']],
        ]);

        $transfer = Transfer::sole();
        $this->post(route('pharmacie.transfers.decide', $transfer), ['decision' => 'approve']);

        $this->from(route('pharmacie.transfers.show', $transfer))
            ->post(route('pharmacie.transfers.send', $transfer->refresh()))
            ->assertSessionHas('pharmacie_error');

        // Rien n'est parti : le transfert est tout ou rien.
        $this->assertSame(5, (int) Stock::query()->where('location_id', $centrale->id)->sum('quantity'));
        $this->assertSame(Transfer::STATUS_APPROVED, $transfer->refresh()->status);
    }

    public function test_a_transfer_goes_from_one_place_to_another(): void
    {
        $product = $this->makeProduct();
        $centrale = $this->makeLocation('CENTRALE', 'Pharmacie centrale');

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->from('/pharmacie/transferts')
            ->post(route('pharmacie.transfers.store'), [
                'from_location_id' => $centrale->id,
                'to_location_id' => $centrale->id,
                'lines' => [['product_id' => $product->id, 'quantity' => '5']],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(0, Transfer::count());
    }

    public function test_a_refused_transfer_never_moves_anything(): void
    {
        $product = $this->makeProduct();
        $centrale = $this->makeLocation('CENTRALE', 'Pharmacie centrale');
        $urgences = $this->makeLocation('URGENCES', 'Urgences');
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 40, $centrale);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->post(route('pharmacie.transfers.store'), [
            'from_location_id' => $centrale->id,
            'to_location_id' => $urgences->id,
            'lines' => [['product_id' => $product->id, 'quantity' => '10']],
        ]);

        $transfer = Transfer::sole();

        // Un refus sans motif n'est pas un refus.
        $this->from(route('pharmacie.transfers.show', $transfer))
            ->post(route('pharmacie.transfers.decide', $transfer), ['decision' => 'refuse'])
            ->assertSessionHasErrors('reason');

        $this->post(route('pharmacie.transfers.decide', $transfer), [
            'decision' => 'refuse',
            'reason' => 'La centrale en a besoin cette semaine',
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(Transfer::STATUS_REFUSED, $transfer->refresh()->status);
        $this->assertSame(40, (int) Stock::query()->where('location_id', $centrale->id)->sum('quantity'));

        // Un transfert refusé ne part pas.
        $this->from(route('pharmacie.transfers.show', $transfer))
            ->post(route('pharmacie.transfers.send', $transfer))
            ->assertSessionHas('pharmacie_error');
    }
}
