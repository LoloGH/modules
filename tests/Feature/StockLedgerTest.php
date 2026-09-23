<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature;

use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Services\StockPicker;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;
use LogicException;

/**
 * Le grand livre du stock : le mouvement fait foi, la quantité s'en déduit,
 * et rien ne s'efface.
 */
class StockLedgerTest extends TestCase
{
    use StockFixtures;

    private function ledger(): StockLedger
    {
        return app(StockLedger::class);
    }

    public function test_a_quantity_is_always_the_sum_of_its_movements(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString());

        $this->ledger()->receive($batch, $location, 100, StockMovement::KIND_RECEPTION, $this->makeUser());
        $this->ledger()->issue($batch, $location, 30, StockMovement::KIND_DISPENSING, $this->makeUser());

        $stock = Stock::query()->where('batch_id', $batch->id)->sole();
        $this->assertSame(70, (int) $stock->quantity);
        $this->assertSame(70, (int) StockMovement::query()->where('batch_id', $batch->id)->sum('quantity'));

        // Le filet : si la commodité de lecture dérive, le grand livre a raison.
        $stock->update(['quantity' => 5]);
        $this->assertSame(70, $this->ledger()->recompute($batch, $location));
    }

    public function test_nothing_leaves_beyond_what_is_available(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        $this->expectException(PharmacieRuleViolation::class);
        $this->expectExceptionMessageMatches('/ne contient que 10/');

        $this->ledger()->issue($batch, $location, 11, StockMovement::KIND_DISPENSING, $this->makeUser());
    }

    public function test_an_expired_or_blocked_batch_is_never_dispensed_but_can_be_destroyed(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $expired = $this->stockUp($this->makeBatch($product, 'LOT-PERIME', now()->subDay()->toDateString()), 20);

        try {
            $this->ledger()->issue($expired, $location, 1, StockMovement::KIND_DISPENSING, $this->makeUser());
            $this->fail('Un lot périmé ne doit pas pouvoir être délivré.');
        } catch (PharmacieRuleViolation $e) {
            $this->assertStringContainsString('périmé', $e->getMessage());
        }

        // Mais on doit pouvoir le sortir pour le détruire : c'est justement
        // ce que le pharmacien doit faire.
        $this->ledger()->issue($expired, $location, 20, StockMovement::KIND_DESTRUCTION, $this->makeUser(), ['reason' => 'Lot périmé']);
        $this->assertSame(0, (int) Stock::query()->where('batch_id', $expired->id)->value('quantity'));

        // Un lot bloqué se refuse aussi.
        $blocked = $this->stockUp($this->makeBatch($product, 'LOT-BLOQUE', now()->addYear()->toDateString()), 10);
        $blocked->update(['status' => Batch::STATUS_BLOCKED, 'block_reason' => 'Rappel fournisseur']);

        $this->expectException(PharmacieRuleViolation::class);
        $this->ledger()->issue($blocked->refresh(), $location, 1, StockMovement::KIND_DISPENSING, $this->makeUser());
    }

    public function test_a_correction_without_a_reason_is_refused(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        $this->expectException(PharmacieRuleViolation::class);
        $this->expectExceptionMessageMatches('/motif/');

        $this->ledger()->issue($batch, $location, 2, StockMovement::KIND_ADJUSTMENT, $this->makeUser());
    }

    public function test_a_movement_is_never_rewritten_nor_erased(): void
    {
        $product = $this->makeProduct();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 5);
        $movement = StockMovement::query()->where('batch_id', $batch->id)->sole();

        try {
            $movement->update(['quantity' => 999]);
            $this->fail('Un mouvement ne doit pas pouvoir être modifié.');
        } catch (LogicException) {
            $this->assertSame(5, (int) $movement->refresh()->quantity);
        }

        $this->expectException(LogicException::class);
        $movement->delete();
    }

    public function test_reserved_units_are_there_but_not_available(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        $this->ledger()->reserve($batch, $location, 4);

        $this->assertSame(6, $this->ledger()->available($batch, $location));
        $this->assertSame(10, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));

        // On ne sort pas ce qui est promis à quelqu'un d'autre.
        try {
            $this->ledger()->issue($batch, $location, 7, StockMovement::KIND_DISPENSING, $this->makeUser());
            $this->fail('Le réservé ne doit pas être servable.');
        } catch (PharmacieRuleViolation $e) {
            $this->assertStringContainsString('6 unité(s) disponible(s)', $e->getMessage());
        }

        $this->ledger()->release($batch, $location, 4);
        $this->assertSame(10, $this->ledger()->available($batch, $location));
    }

    public function test_fefo_proposes_what_expires_first(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();

        $loin = $this->stockUp($this->makeBatch($product, 'LOT-LOIN', now()->addYear()->toDateString()), 50);
        $proche = $this->stockUp($this->makeBatch($product, 'LOT-PROCHE', now()->addDays(20)->toDateString()), 30);
        $perime = $this->stockUp($this->makeBatch($product, 'LOT-PERIME', now()->subDay()->toDateString()), 100);

        $picker = app(StockPicker::class);

        // Le plus proche de la péremption d'abord ; le périmé jamais.
        $this->assertSame($proche->id, $picker->suggest($product, $location)?->id);

        $plan = $picker->plan($product, $location, 40);

        $this->assertCount(2, $plan['lines']);
        $this->assertSame([$proche->id, 30], [$plan['lines'][0]['batch']->id, $plan['lines'][0]['quantity']]);
        $this->assertSame([$loin->id, 10], [$plan['lines'][1]['batch']->id, $plan['lines'][1]['quantity']]);
        $this->assertSame(0, $plan['missing']);

        // Ce qui manque est dit, pas caché : c'est le reliquat.
        $manque = $picker->plan($product, $location, 200);
        $this->assertSame(120, $manque['missing']);

        $this->assertNotContains(
            $perime->id,
            array_map(static fn (array $line): int => $line['batch']->id, $manque['lines']),
        );
    }
}
