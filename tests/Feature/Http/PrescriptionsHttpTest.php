<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Contracts\PrescriptionProvider;
use Keneya\Pharmacie\Contracts\PrescriptionSink;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Product;
use Keneya\Pharmacie\Prescriptions\Prescription;
use Keneya\Pharmacie\Prescriptions\PrescriptionLine;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakePrescriptions;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Les ordonnances du dossier médical : on les lit, on les sert sans
 * ressaisie, et on lui rend ce qui a été délivré.
 */
class PrescriptionsHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    /**
     * @param  list<PrescriptionLine>  $lines
     */
    private function hostPrescriptions(array $lines, array $warnings = []): FakePrescriptions
    {
        $fake = new FakePrescriptions([
            new Prescription(
                reference: 'ORD-2026-000097',
                patientId: 'PAT-000123',
                patientName: 'Aminata Traoré',
                lines: $lines,
                prescriber: 'Dr Diallo',
                issuedOn: now()->subDay(),
                validUntil: now()->addMonth(),
                instructions: 'À prendre après le repas',
                allergyWarnings: $warnings,
            ),
        ]);

        $this->app->instance(PrescriptionProvider::class, $fake);
        $this->app->instance(PrescriptionSink::class, $fake);

        return $fake;
    }

    public function test_without_a_medical_record_the_screen_says_so(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/ordonnances')
            ->assertOk()
            ->assertSee('Dossier médical non branché')
            ->assertSee('Aucune ordonnance en attente');
    }

    public function test_a_prescription_arrives_with_its_posology_and_its_allergy_warning(): void
    {
        $product = $this->makeProduct(['code' => 'AMOX500', 'name' => 'Amoxicilline']);
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 40);

        $this->hostPrescriptions([
            new PrescriptionLine(
                label: 'Amoxicilline',
                quantity: 20,
                productCode: 'AMOX500',
                dosage: '500 mg',
                frequency: '2 fois par jour',
                duration: '7 jours',
            ),
        ], ['Allergie connue à la pénicilline']);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/ordonnances')
            ->assertOk()
            ->assertSee('ORD-2026-000097')
            ->assertSee('Aminata Traoré')
            ->assertSee('Dr Diallo')
            ->assertSee('Allergie signalée');

        $this->get('/pharmacie/ordonnances/ORD-2026-000097')
            ->assertOk()
            ->assertSee('Allergie connue à la pénicilline')
            // La posologie et le produit rapproché : aucune ressaisie.
            ->assertSee('500 mg, 2 fois par jour, 7 jours')
            ->assertSee('lot proposé : LOT-A')
            ->assertSee('Amoxicilline');
    }

    public function test_serving_a_prescription_tells_the_medical_record_what_was_dispensed(): void
    {
        $product = $this->makeProduct(['code' => 'AMOX500', 'name' => 'Amoxicilline']);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 40);

        $fake = $this->hostPrescriptions([
            new PrescriptionLine(label: 'Amoxicilline', quantity: 20, productCode: 'AMOX500'),
        ]);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_id' => 'PAT-000123',
                'patient_name' => 'Aminata Traoré',
                'prescription_ref' => 'ORD-2026-000097',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '20',
                    'prescribed_quantity' => '20',
                ]],
            ])
            ->assertSessionHas('pharmacie_status');

        $dispensation = Dispensation::sole();
        $this->assertSame(Dispensation::SOURCE_PRESCRIPTION, $dispensation->source);

        // Le dossier medical apprend ce qui a ete servi, et que tout l'a ete.
        $this->assertSame(
            [['reference' => 'ORD-2026-000097', 'dispensation' => $dispensation->number, 'complete' => true]],
            $fake->reported,
        );
    }

    public function test_a_partly_served_prescription_is_reported_as_incomplete_and_shows_what_is_left(): void
    {
        $product = $this->makeProduct(['code' => 'AMOX500', 'name' => 'Amoxicilline']);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 12);

        $fake = $this->hostPrescriptions([
            new PrescriptionLine(label: 'Amoxicilline', quantity: 20, productCode: 'AMOX500'),
        ]);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_id' => 'PAT-000123',
                'prescription_ref' => 'ORD-2026-000097',
                'lines' => [['product_id' => $product->id, 'quantity' => '20', 'prescribed_quantity' => '20']],
            ]);

        $this->assertFalse($fake->reported[0]['complete']);
        $this->assertSame(8, (int) Dispensation::sole()->outstanding);

        // L'ordonnance rouverte montre ce qui a deja ete servi.
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/ordonnances/ORD-2026-000097')
            ->assertSee('déjà servi : 12');

        $this->get('/pharmacie/ordonnances')->assertSee('Déjà servie en partie');
    }

    public function test_a_line_left_without_a_product_is_simply_not_dispensed(): void
    {
        $product = $this->makeProduct(['code' => 'AMOX500', 'name' => 'Amoxicilline']);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 40);

        $autre = Product::query()->where('code', 'AMOX500')->sole();

        $this->hostPrescriptions([
            new PrescriptionLine(label: 'Amoxicilline', quantity: 10, productCode: 'AMOX500'),
            new PrescriptionLine(label: 'Produit indisponible', quantity: 5),
        ]);

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'prescription_ref' => 'ORD-2026-000097',
                'lines' => [
                    ['product_id' => $autre->id, 'quantity' => '10', 'prescribed_quantity' => '10'],
                    ['product_id' => '', 'quantity' => '', 'prescribed_quantity' => '5'],
                ],
            ])
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(1, Dispensation::sole()->items()->count());

        // Rien du tout a delivrer : on le dit, on ne cree pas une piece vide.
        $this->from('/pharmacie/comptoir')
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'lines' => [['product_id' => '', 'quantity' => '']],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(1, Dispensation::count());
    }
}
