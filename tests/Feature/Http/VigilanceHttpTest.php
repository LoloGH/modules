<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Models\AdverseEvent;
use Keneya\Pharmacie\Models\AuditLog;
use Keneya\Pharmacie\Models\Batch;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Models\Recall;
use Keneya\Pharmacie\Models\RecallPatient;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Surveillance : un stupéfiant ne se délivre pas comme le reste, un lot
 * suspect remonte jusqu'aux patients, et un effet indésirable se suit
 * jusqu'à une conclusion.
 */
class VigilanceHttpTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_a_controlled_product_is_not_dispensed_without_prescription_or_patient(): void
    {
        $product = $this->makeProduct(['name' => 'Morphine', 'is_controlled' => true]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 20);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $line = ['product_id' => $product->id, 'quantity' => '2', 'prescribed_quantity' => '2'];

        // Sans ordonnance : refusé.
        $this->actingAs($pharmacist)->from(route('pharmacie.dispensing.create'))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'lines' => [$line],
            ])
            ->assertSessionHas('pharmacie_error');

        // Sans patient nommé : refusé aussi.
        $this->from(route('pharmacie.dispensing.create'))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'prescription_ref' => 'ORD-2026-0001',
                'lines' => [$line],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(0, Dispensation::count());

        // Avec les deux, le pharmacien sert : la trace est nominative.
        $this->post(route('pharmacie.dispensing.store'), [
            'location_id' => $location->id,
            'patient_name' => 'Aminata Traoré',
            'prescription_ref' => 'ORD-2026-0001',
            'lines' => [$line],
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(1, Dispensation::count());
        $this->assertSame(1, AuditLog::where('event', 'controlled_dispensed')->count());
    }

    public function test_a_controlled_product_needs_a_habilitated_dispenser(): void
    {
        $product = $this->makeProduct(['name' => 'Morphine', 'is_controlled' => true]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 20);

        // Le préparateur délivre tous les jours, mais pas un stupéfiant.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->from(route('pharmacie.dispensing.create'))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'prescription_ref' => 'ORD-2026-0002',
                'lines' => [['product_id' => $product->id, 'quantity' => '1', 'prescribed_quantity' => '1']],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(0, Dispensation::count());
    }

    public function test_the_register_shows_every_movement_with_its_balance(): void
    {
        $product = $this->makeProduct(['name' => 'Morphine', 'is_controlled' => true]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 20);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Aminata Traoré',
                'prescription_ref' => 'ORD-2026-0003',
                'lines' => [['product_id' => $product->id, 'quantity' => '3', 'prescribed_quantity' => '3']],
            ]);

        $this->get(route('pharmacie.vigilance.register', ['product_id' => $product->id]))
            ->assertOk()
            ->assertSee('Morphine')
            // Entrée de 20, sortie de 3 : le solde du registre suit.
            ->assertSeeInOrder(['17', '20']);
    }

    public function test_a_recall_blocks_the_batch_and_lists_the_patients_served(): void
    {
        $product = $this->makeProduct(['name' => 'Paracétamol']);
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-SUSPECT', now()->addYear()->toDateString()), 100);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->post(route('pharmacie.dispensing.store'), [
            'location_id' => $location->id,
            'patient_id' => 'PAT-001',
            'patient_name' => 'Aminata Traoré',
            'lines' => [['product_id' => $product->id, 'quantity' => '10', 'prescribed_quantity' => '10']],
        ])->assertSessionHas('pharmacie_status');

        $this->post(route('pharmacie.vigilance.recalls.store'), [
            'batch_id' => $batch->id,
            'origin' => Recall::ORIGIN_MANUFACTURER,
            'level' => Recall::LEVEL_PATIENTS,
            'reference' => 'AVIS-2026-17',
            'reason' => 'Défaut de fabrication signalé par le laboratoire',
        ])->assertSessionHas('pharmacie_status');

        $recall = Recall::sole();
        $this->assertStringStartsWith('RAP-', $recall->number);
        $this->assertSame(90, (int) $recall->quantity_blocked);
        $this->assertSame(Batch::STATUS_BLOCKED, $batch->refresh()->status);

        // Le patient servi de ce lot est retrouvé, avec sa quantité.
        $line = RecallPatient::sole();
        $this->assertSame('Aminata Traoré', $line->patient_name);
        $this->assertSame(10, (int) $line->quantity);

        // Le lot bloqué ne sort plus.
        $this->from(route('pharmacie.dispensing.create'))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Moussa Keïta',
                'lines' => [[
                    'product_id' => $product->id,
                    'quantity' => '5',
                    'prescribed_quantity' => '5',
                    'batch_id' => $batch->id,
                ]],
            ])
            ->assertSessionHas('pharmacie_error');
    }

    public function test_a_patient_level_recall_does_not_close_before_the_patients_are_reached(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-SUSPECT', now()->addYear()->toDateString()), 50);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->post(route('pharmacie.dispensing.store'), [
            'location_id' => $location->id,
            'patient_name' => 'Aminata Traoré',
            'lines' => [['product_id' => $product->id, 'quantity' => '4', 'prescribed_quantity' => '4']],
        ]);

        $this->post(route('pharmacie.vigilance.recalls.store'), [
            'batch_id' => $batch->id,
            'origin' => Recall::ORIGIN_AUTHORITY,
            'level' => Recall::LEVEL_PATIENTS,
            'reason' => 'Retrait ordonné par l\'autorité sanitaire',
        ]);

        $recall = Recall::sole();
        $line = RecallPatient::sole();

        $this->from(route('pharmacie.vigilance.recalls.show', $recall))
            ->post(route('pharmacie.vigilance.recalls.close', $recall), ['note' => 'Lot détruit'])
            ->assertSessionHas('pharmacie_error');

        $this->assertTrue($recall->refresh()->isOpen());

        // Un appel se note avec ce qu'il a donné.
        $this->post(route('pharmacie.vigilance.recalls.contact', $line), [
            'note' => 'Patiente jointe, il lui restait deux comprimés, rapportés',
        ])->assertSessionHas('pharmacie_status');

        $this->assertTrue((bool) $line->refresh()->contacted);

        $this->post(route('pharmacie.vigilance.recalls.close', $recall), ['note' => 'Lot détruit devant témoin'])
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(Recall::STATUS_CLOSED, $recall->refresh()->status);
    }

    public function test_a_cancelled_dispensation_puts_nobody_on_the_recall_list(): void
    {
        $product = $this->makeProduct();
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-SUSPECT', now()->addYear()->toDateString()), 50);

        $pharmacist = $this->userWithRole(Rbac::ROLE_PHARMACIST);

        $this->actingAs($pharmacist)->post(route('pharmacie.dispensing.store'), [
            'location_id' => $location->id,
            'patient_name' => 'Aminata Traoré',
            'lines' => [['product_id' => $product->id, 'quantity' => '4', 'prescribed_quantity' => '4']],
        ]);

        // Le produit est revenu : il n'est parti dans aucune main.
        $this->post(route('pharmacie.dispensing.cancel', Dispensation::sole()), ['reason' => 'Erreur de comptoir']);

        $this->post(route('pharmacie.vigilance.recalls.store'), [
            'batch_id' => $batch->id,
            'origin' => Recall::ORIGIN_INTERNAL,
            'level' => Recall::LEVEL_PATIENTS,
            'reason' => 'Aspect anormal des comprimés',
        ]);

        $this->assertSame(0, RecallPatient::count());
    }

    public function test_an_adverse_event_links_the_patient_the_drug_and_the_batch(): void
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline']);
        $location = $this->makeLocation();
        $batch = $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 30);

        $dispenser = $this->userWithRole(Rbac::ROLE_DISPENSER);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_id' => 'PAT-777',
                'patient_name' => 'Moussa Keïta',
                'lines' => [['product_id' => $product->id, 'quantity' => '6', 'prescribed_quantity' => '6']],
            ]);

        $dispensation = Dispensation::sole();

        // Le préparateur signale ce que le patient lui a rapporté.
        $this->actingAs($dispenser)->post(route('pharmacie.vigilance.events.store'), [
            'dispensation_id' => $dispensation->id,
            'product_id' => $product->id,
            'batch_id' => $batch->id,
            'description' => 'Éruption cutanée le lendemain de la première prise',
            'severity' => AdverseEvent::SEVERITY_SEVERE,
            'outcome' => AdverseEvent::OUTCOME_RECOVERING,
        ])->assertSessionHas('pharmacie_status');

        $event = AdverseEvent::sole();
        $this->assertStringStartsWith('EIV-', $event->number);
        // Le patient est déduit de la dispensation : on ne le ressaisit pas.
        $this->assertSame('Moussa Keïta', $event->patient_name);
        $this->assertSame($batch->id, (int) $event->batch_id);
        $this->assertTrue($event->isSerious());

        // Le préparateur signale, il ne clôt pas.
        $this->from(route('pharmacie.vigilance.events.show', $event))
            ->post(route('pharmacie.vigilance.events.close', $event), ['conclusion' => 'Sans suite'])
            ->assertForbidden();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.vigilance.events.transmit', $event), [
                'transmitted_to' => 'Centre national de pharmacovigilance',
            ])->assertSessionHas('pharmacie_status');

        $this->assertSame(AdverseEvent::STATUS_TRANSMITTED, $event->refresh()->status);

        $this->post(route('pharmacie.vigilance.events.close', $event), [
            'conclusion' => 'Imputabilité probable, produit contre-indiqué pour ce patient',
        ])->assertSessionHas('pharmacie_status');

        $this->assertSame(AdverseEvent::STATUS_CLOSED, $event->refresh()->status);
    }

    public function test_an_event_without_a_drug_goes_nowhere(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->from(route('pharmacie.vigilance.events.index'))
            ->post(route('pharmacie.vigilance.events.store'), [
                'description' => 'Malaise après la prise',
                'severity' => AdverseEvent::SEVERITY_MODERATE,
                'outcome' => AdverseEvent::OUTCOME_UNKNOWN,
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(0, AdverseEvent::count());
    }
}
