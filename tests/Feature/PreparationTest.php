<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature;

use Keneya\Pharmacie\Actions\DeliverPreparation;
use Keneya\Pharmacie\Actions\PrepareDispensation;
use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Contracts\SaleStatusProvider;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationReservation;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Sales\SaleStatus;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakeSaleSink;
use Keneya\Pharmacie\Tests\Support\FakeSaleStatus;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Payer avant d'être servi : la préparation, puis la délivrance.
 *
 * Ce que ces tests tiennent, et qui fait la valeur du parcours :
 *
 *   - préparer ne fait sortir aucune unité du stock ;
 *   - la part du patient commande la délivrance, pas l'état global de la
 *     facture : un assureur qui n'a pas encore versé ne retient personne ;
 *   - une prise en charge met les unités de côté, et un abandon les rend.
 */
class PreparationTest extends TestCase
{
    use StockFixtures;

    private FakeSaleSink $caisse;

    private FakeSaleStatus $etats;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();

        $this->caisse = new FakeSaleSink('FAC-2026-000001');
        $this->etats = new FakeSaleStatus;

        $this->app->instance(SaleSink::class, $this->caisse);
        $this->app->instance(SaleStatusProvider::class, $this->etats);
    }

    public function test_preparing_bills_the_cashier_without_moving_any_stock(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => 1_000]);
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 50);

        $preparation = app(PrepareDispensation::class)->handle($location, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], $this->userWithRole(Rbac::ROLE_DISPENSER), [
            'patient_id' => 'PAT-001',
            'patient_name' => 'Aminata Traoré',
        ]);

        $this->assertSame(Dispensation::STATUS_DRAFT, $preparation->status);
        $this->assertSame('En préparation', $preparation->statusLabel());
        $this->assertSame(10_000, (int) $preparation->total);
        $this->assertSame('FAC-2026-000001', $preparation->billing_reference);

        // La caisse a recu ce qu'il y a a payer.
        $this->assertCount(1, $this->caisse->sales);
        $this->assertSame(10_000, $this->caisse->sales[0]->total);

        // Et rien n'a bouge : ni ecriture, ni reservation.
        $this->assertSame(50, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));
        $this->assertSame(0, (int) Stock::query()->where('batch_id', $batch->id)->value('reserved'));
        $this->assertSame(1, StockMovement::query()->count(), 'Seule la reception doit figurer au grand livre.');
    }

    public function test_nothing_is_delivered_while_the_patient_still_owes(): void
    {
        $preparation = $this->prepare(10);

        $this->etats->owing('FAC-2026-000001', 10_000, 4_000);

        $this->expectException(PharmacieRuleViolation::class);
        $this->expectExceptionMessage('Il reste 4 000 FCFA à régler');

        app(DeliverPreparation::class)->handle($preparation, $this->userWithRole(Rbac::ROLE_DISPENSER));
    }

    public function test_delivering_a_settled_preparation_moves_the_stock(): void
    {
        $preparation = $this->prepare(10);
        $batch = $preparation->items()->first()->product->id;

        $this->etats->settle('FAC-2026-000001', 10_000);

        $delivered = app(DeliverPreparation::class)->handle(
            $preparation,
            $this->userWithRole(Rbac::ROLE_PHARMACIST),
        );

        $this->assertSame(Dispensation::STATUS_DISPENSED, $delivered->status);
        $this->assertSame(Dispensation::PAYMENT_SETTLED, $delivered->payment_status);
        $this->assertSame(0, (int) $delivered->outstanding);
        $this->assertSame(10, (int) $delivered->items()->first()->quantity);

        // Le stock sort maintenant, et pas avant.
        $this->assertSame(40, (int) Stock::query()->where('product_id', $batch)->value('quantity'));
        $this->assertSame(1, StockMovement::query()->where('kind', StockMovement::KIND_DISPENSING)->count());
    }

    public function test_the_insurer_share_never_holds_the_patient_back(): void
    {
        $preparation = $this->prepare(10, coverage: ['insurer' => 'AMO', 'rate' => 80]);

        // Le patient a regle ses 20 %, l'assureur doit encore les siens.
        $this->etats->set('FAC-2026-000001', new SaleStatus(
            reference: 'FAC-2026-000001',
            total: 10_000,
            patientDue: 0,
            patientPaid: 2_000,
            coveredShare: 8_000,
            insurerName: 'AMO',
        ));

        $delivered = app(DeliverPreparation::class)->handle(
            $preparation,
            $this->userWithRole(Rbac::ROLE_PHARMACIST),
        );

        $this->assertSame(Dispensation::STATUS_DISPENSED, $delivered->status);
        // La reservation posee par la prise en charge est rendue.
        $this->assertSame(0, DispensationReservation::query()->count());
        $this->assertSame(0, (int) Stock::query()->value('reserved'));
    }

    public function test_a_covered_preparation_sets_the_units_aside(): void
    {
        $preparation = $this->prepare(10, coverage: ['insurer' => 'Aide sociale', 'rate' => 100]);

        $stock = Stock::query()->first();

        $this->assertSame('Aide sociale', $preparation->coverage_insurer);
        $this->assertSame(100, (int) $preparation->coverage_rate);
        // Les unites sont toujours la, mais plus servables a quelqu'un d'autre.
        $this->assertSame(50, (int) $stock->quantity);
        $this->assertSame(10, (int) $stock->reserved);
        $this->assertSame(40, $stock->available());
        $this->assertSame(1, DispensationReservation::query()->count());
    }

    public function test_abandoning_a_preparation_gives_the_units_back(): void
    {
        $preparation = $this->prepare(10, coverage: ['insurer' => 'AMO', 'rate' => 80]);

        $abandoned = app(PrepareDispensation::class)->abandon(
            $preparation,
            'Le patient n\'est pas revenu de la caisse',
            $this->userWithRole(Rbac::ROLE_PHARMACIST),
        );

        $this->assertSame(Dispensation::STATUS_CANCELLED, $abandoned->status);
        $this->assertSame(0, (int) Stock::query()->value('reserved'));
        $this->assertSame(0, DispensationReservation::query()->count());
        // Rien n'a jamais bouge au grand livre : il n'y a rien a contre-passer.
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_an_unknown_or_cancelled_piece_stops_the_delivery(): void
    {
        $preparation = $this->prepare(10);
        $pharmacien = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        // La caisse ne connait pas la piece : on ne suppose pas qu'elle est
        // reglee, on refuse.
        try {
            app(DeliverPreparation::class)->handle($preparation, $pharmacien);
            $this->fail('Une pièce inconnue de la caisse ne doit pas être délivrable.');
        } catch (PharmacieRuleViolation $e) {
            $this->assertStringContainsString('ne connaît pas la pièce', $e->getMessage());
        }

        $this->etats->set('FAC-2026-000001', new SaleStatus(
            reference: 'FAC-2026-000001',
            total: 10_000,
            patientDue: 0,
            cancelled: true,
        ));

        $this->expectException(PharmacieRuleViolation::class);
        $this->expectExceptionMessage('annulée en caisse');

        app(DeliverPreparation::class)->handle($preparation, $pharmacien);
    }

    public function test_what_is_missing_at_the_counter_becomes_a_shortfall(): void
    {
        $product = $this->makeProduct(['sale_price' => 1_000]);
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 10);

        $preparation = app(PrepareDispensation::class)->handle($location, [
            ['product_id' => $product->id, 'quantity' => 10],
        ], $this->userWithRole(Rbac::ROLE_DISPENSER), ['patient_name' => 'Moussa Keïta']);

        // Entre la caisse et le comptoir, une perte vide une partie du lot.
        app(StockLedger::class)->issue(
            $batch,
            $location,
            6,
            StockMovement::KIND_LOSS,
            $this->userWithRole(Rbac::ROLE_STOREKEEPER),
            ['reason' => 'Casse au rangement'],
        );

        $this->etats->settle('FAC-2026-000001', 10_000);

        $delivered = app(DeliverPreparation::class)->handle(
            $preparation,
            $this->userWithRole(Rbac::ROLE_PHARMACIST),
        );

        // On sert ce qui reste, et ce qui manque se voit.
        $this->assertSame(4, (int) $delivered->items()->first()->quantity);
        $this->assertSame(6, (int) $delivered->outstanding);
        $this->assertSame('Partielle', $delivered->statusLabel());
    }

    /**
     * @param  array{insurer?: ?string, rate?: ?int, reference?: ?string}|null  $coverage
     */
    private function prepare(int $quantity, ?array $coverage = null): Dispensation
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => 1_000]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 50);

        return app(PrepareDispensation::class)->handle($location, [
            ['product_id' => $product->id, 'quantity' => $quantity],
        ], $this->userWithRole(Rbac::ROLE_DISPENSER), [
            'patient_id' => 'PAT-001',
            'patient_name' => 'Aminata Traoré',
            'coverage' => $coverage,
        ]);
    }
}
