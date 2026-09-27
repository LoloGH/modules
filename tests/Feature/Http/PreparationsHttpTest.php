<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Contracts\SaleStatusProvider;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\DispensationReservation;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakeSaleSink;
use Keneya\Pharmacie\Tests\Support\FakeSaleStatus;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Les écrans du parcours « payer avant d'être servi » : le comptoir prépare,
 * la caisse encaisse, le comptoir délivre.
 */
class PreparationsHttpTest extends TestCase
{
    use StockFixtures;

    private FakeSaleStatus $etats;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();

        $this->etats = new FakeSaleStatus;
        $this->app->instance(SaleSink::class, new FakeSaleSink('FAC-2026-000001'));
        $this->app->instance(SaleStatusProvider::class, $this->etats);

        config(['pharmacie.dispensing.payment_before_delivery' => true]);
    }

    public function test_the_counter_prepares_instead_of_delivering(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => 1_000]);
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 40);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'lines' => [['product_id' => $product->id, 'quantity' => '10']],
            ])
            ->assertSessionHas('pharmacie_status');

        $preparation = Dispensation::sole();

        $this->assertSame(Dispensation::STATUS_DRAFT, $preparation->status);
        $this->assertSame('FAC-2026-000001', $preparation->billing_reference);
        // Rien n'a bouge : seule la reception figure au grand livre.
        $this->assertSame(40, (int) Stock::query()->where('batch_id', $batch->id)->value('quantity'));
        $this->assertSame(1, StockMovement::query()->count());
    }

    public function test_the_screen_says_what_is_still_owed_and_refuses_to_deliver(): void
    {
        $preparation = $this->prepare();

        $this->etats->owing('FAC-2026-000001', 10_000, 3_000);

        $this->get(route('pharmacie.preparations.index'))
            ->assertOk()
            ->assertSee('Reste 3 000 FCFA');

        $this->from(route('pharmacie.preparations.show', $preparation))
            ->post(route('pharmacie.preparations.deliver', $preparation))
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(Dispensation::STATUS_DRAFT, $preparation->refresh()->status);
    }

    public function test_a_settled_preparation_is_delivered_from_the_counter(): void
    {
        $preparation = $this->prepare();

        $this->etats->settle('FAC-2026-000001', 10_000);

        $this->get(route('pharmacie.preparations.show', $preparation))
            ->assertOk()
            ->assertSee('Part patient réglée')
            ->assertSee('Délivrer au patient');

        $this->post(route('pharmacie.preparations.deliver', $preparation))
            ->assertSessionHas('pharmacie_status');

        $delivered = $preparation->refresh();

        $this->assertSame(Dispensation::STATUS_DISPENSED, $delivered->status);
        $this->assertSame(30, (int) Stock::query()->value('quantity'));
        $this->assertSame(1, StockMovement::query()->where('kind', StockMovement::KIND_DISPENSING)->count());
    }

    public function test_abandoning_gives_the_reserved_units_back(): void
    {
        $preparation = $this->prepare(['insurer' => 'AMO', 'rate' => 80]);

        $this->assertSame(1, DispensationReservation::query()->count());
        $this->assertSame(10, (int) Stock::query()->value('reserved'));

        $this->post(route('pharmacie.preparations.abandon', $preparation), [
            'reason' => 'Le patient n\'est pas revenu de la caisse',
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(Dispensation::STATUS_CANCELLED, $preparation->refresh()->status);
        $this->assertSame(0, (int) Stock::query()->value('reserved'));
        $this->assertSame(0, DispensationReservation::query()->count());
    }

    public function test_abandoning_is_the_right_to_cancel_not_to_deliver(): void
    {
        $preparation = $this->prepare();

        // Le preparateur sert au comptoir ; il n'annule pas.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.preparations.abandon', $preparation), ['reason' => 'Essai'])
            ->assertForbidden();

        $this->assertSame(Dispensation::STATUS_DRAFT, $preparation->refresh()->status);
    }

    public function test_an_invoice_that_never_left_can_be_resent(): void
    {
        $this->app->instance(SaleSink::class, new FakeSaleSink(null));

        $preparation = $this->prepare();

        $this->assertNull($preparation->billing_reference);

        $this->get(route('pharmacie.preparations.show', $preparation))
            ->assertOk()
            // Le titre porte une apostrophe, que Blade encode : on vérifie le
            // bouton, dont le libellé traverse le rendu tel quel.
            ->assertSee('Renvoyer à la caisse');

        // La caisse repond de nouveau.
        $this->app->instance(SaleSink::class, new FakeSaleSink('FAC-2026-000009'));

        $this->post(route('pharmacie.preparations.resend', $preparation))
            ->assertSessionHas('pharmacie_status');

        $this->assertSame('FAC-2026-000009', $preparation->refresh()->billing_reference);
    }

    public function test_a_line_taken_elsewhere_is_reserved_and_served_there(): void
    {
        $comptoir = $this->makeLocation('COMPR', 'Comptoir');
        $reserve = $this->makeLocation('RESERVE', 'Reserve');

        // Le comptoir n'en a pas une seule : tout est a la reserve.
        $product = $this->makeProduct(['name' => 'Ceftriaxone 1 g', 'sale_price' => 2_000]);
        $this->stockUp($this->makeBatch($product, 'LOT-R', now()->addYear()->toDateString()), 30, $reserve);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $comptoir->id,
                'patient_name' => 'Aminata Traoré',
                'coverage_insurer' => 'AMO',
                'coverage_rate' => '80',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '3',
                    'location_id' => $reserve->id,
                ]],
            ])
            ->assertSessionHas('pharmacie_status');

        $preparation = Dispensation::query()->latest('id')->firstOrFail();

        // Ce qui est mis de cote l'est la ou il se trouve : reserver au
        // comptoir ce qui dort a la reserve ne reserverait rien.
        $this->assertSame($reserve->id, (int) DispensationReservation::query()->value('location_id'));
        $this->assertSame(3, (int) Stock::query()->where('location_id', $reserve->id)->value('reserved'));

        $this->etats->settle('FAC-2026-000001', 1_200);

        $this->post(route('pharmacie.preparations.deliver', $preparation))
            ->assertSessionHas('pharmacie_status');

        // Et c'est de la que les unites sortent.
        $this->assertSame(27, (int) Stock::query()->where('location_id', $reserve->id)->value('quantity'));
        $this->assertSame(0, (int) Stock::query()->where('location_id', $reserve->id)->value('reserved'));
        $this->assertSame(0, DispensationReservation::query()->count());

        $sortie = StockMovement::query()->where('kind', StockMovement::KIND_DISPENSING)->sole();
        $this->assertSame($reserve->id, (int) $sortie->location_id);
    }

    /**
     * @param  array{insurer?: ?string, rate?: ?int}|null  $coverage
     */
    private function prepare(?array $coverage = null): Dispensation
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => 1_000]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 40);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'coverage_insurer' => $coverage['insurer'] ?? null,
                'coverage_rate' => $coverage['rate'] ?? null,
                'lines' => [['product_id' => $product->id, 'quantity' => '10']],
            ]);

        return Dispensation::query()->latest('id')->firstOrFail();
    }
}
