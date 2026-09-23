<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Actions\SendToCashier;
use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Models\AuditLog;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakeSaleSink;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Ce qui doit être payé part à la caisse : la pharmacie dit ce qui est dû,
 * elle ne tient pas de tiroir.
 */
class BillingHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    private function dispense(int $quantity = 5, int $price = 1_000): Dispensation
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => $price]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 100);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_id' => 'PAT-000123',
                'patient_name' => 'Aminata Traoré',
                'lines' => [['product_id' => $product->id, 'quantity' => (string) $quantity]],
            ])
            ->assertSessionHas('pharmacie_status');

        return Dispensation::sole();
    }

    public function test_what_is_due_goes_to_the_cashier_with_its_lines(): void
    {
        $sink = new FakeSaleSink;
        $this->app->instance(SaleSink::class, $sink);

        $dispensation = $this->dispense(5, 1_000);

        $this->assertSame(Dispensation::PAYMENT_DUE, $dispensation->payment_status);
        $this->assertTrue($dispensation->awaitsBilling());

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.bill', $dispensation), ['kind' => SendToCashier::KIND_DIRECT])
            ->assertSessionHas('pharmacie_status');

        $dispensation->refresh();
        $this->assertSame(Dispensation::PAYMENT_SENT, $dispensation->payment_status);
        $this->assertSame('FAC-2026-000001', $dispensation->billing_reference);

        // La caisse a recu ce qu'il faut pour encaisser, et rien de plus.
        $sale = $sink->sales[0];
        $this->assertSame($dispensation->number, $sale->reference);
        $this->assertSame('PAT-000123', $sale->patientId);
        $this->assertSame(5_000, $sale->total);
        $this->assertSame([['label' => 'Amoxicilline', 'quantity' => 5, 'unit_price' => 1_000, 'amount' => 5_000]], $sale->lines);

        $this->assertSame(1, AuditLog::where('event', 'dispensation_billed')->count());
    }

    public function test_nothing_is_sent_twice(): void
    {
        $sink = new FakeSaleSink;
        $this->app->instance(SaleSink::class, $sink);

        $dispensation = $this->dispense();

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.bill', $dispensation), ['kind' => SendToCashier::KIND_DIRECT]);

        $this->from(route('pharmacie.dispensing.show', $dispensation))
            ->post(route('pharmacie.dispensing.bill', $dispensation), ['kind' => SendToCashier::KIND_DIRECT])
            ->assertSessionHas('pharmacie_error');

        $this->assertCount(1, $sink->sales);
    }

    public function test_a_free_dispensing_is_justified_and_never_reaches_the_cashier(): void
    {
        $sink = new FakeSaleSink;
        $this->app->instance(SaleSink::class, $sink);

        $dispensation = $this->dispense();

        // Sans motif, la gratuite est refusee.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->from(route('pharmacie.dispensing.show', $dispensation))
            ->post(route('pharmacie.dispensing.bill', $dispensation), ['kind' => SendToCashier::KIND_FREE])
            ->assertSessionHas('pharmacie_error');

        $this->post(route('pharmacie.dispensing.bill', $dispensation), [
            'kind' => SendToCashier::KIND_FREE,
            'note' => 'Patient indigent, décision du chef de service',
        ])->assertSessionHas('pharmacie_status');

        $dispensation->refresh();
        $this->assertSame(Dispensation::PAYMENT_FREE, $dispensation->payment_status);
        $this->assertSame('Patient indigent, décision du chef de service', $dispensation->billing_note);
        $this->assertSame([], $sink->sales);
    }

    public function test_a_cancelled_dispensing_is_never_billed(): void
    {
        $sink = new FakeSaleSink;
        $this->app->instance(SaleSink::class, $sink);

        $dispensation = $this->dispense();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.cancel', $dispensation), ['reason' => 'Erreur de saisie']);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->from(route('pharmacie.dispensing.show', $dispensation))
            ->post(route('pharmacie.dispensing.bill', $dispensation->refresh()), ['kind' => SendToCashier::KIND_DIRECT])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame([], $sink->sales);
    }

    public function test_without_a_cashier_branched_the_pharmacy_still_works(): void
    {
        // Aucun SaleSink lie : NoSaleSink ne fait rien, et la dispensation
        // reste ecrite. La pharmacie tourne sans caisse branchee.
        $dispensation = $this->dispense();

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.bill', $dispensation), ['kind' => SendToCashier::KIND_HOSPITALIZATION])
            ->assertSessionHas('pharmacie_status');

        $dispensation->refresh();
        $this->assertSame(Dispensation::PAYMENT_SENT, $dispensation->payment_status);
        $this->assertNull($dispensation->billing_reference);
        $this->assertSame('hospitalisation', $dispensation->billing_kind);
    }
}
