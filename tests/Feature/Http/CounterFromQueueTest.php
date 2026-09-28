<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Contracts\PrescriptionProvider;
use Keneya\Pharmacie\Models\DispensationItem;
use Keneya\Pharmacie\Models\Stock;
use Keneya\Pharmacie\Models\StockMovement;
use Keneya\Pharmacie\Prescriptions\Prescription;
use Keneya\Pharmacie\Prescriptions\PrescriptionLine;
use Keneya\Pharmacie\Queue\PharmacyQueue;
use Keneya\Pharmacie\Queue\QueuedPatient;
use Keneya\Pharmacie\Services\PrescriptionMatcher;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakePharmacyQueue;
use Keneya\Pharmacie\Tests\Support\FakePrescriptions;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * De la file au comptoir : un patient appelé se prépare, et le comptoir
 * arrive déjà rempli de son ordonnance.
 *
 * Ce qui est éprouvé ici, c'est qu'on ne ressaisit rien et qu'on ne cache
 * rien : les lignes du prescripteur descendent telles quelles, et celles
 * qu'on ne peut pas servir se signalent au lieu d'arriver à la caisse.
 */
class CounterFromQueueTest extends TestCase
{
    use StockFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_only_a_called_patient_can_be_prepared(): void
    {
        $this->hostQueue();

        $page = $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/file?file=comptoir')
            ->assertOk()
            ->assertSee('Préparer');

        // Aminata est appelée, Ibrahim attend encore : un seul bouton.
        $this->assertSame(1, substr_count($page->getContent(), 'Préparer'));
        $this->assertStringContainsString('patient=C-1', $page->getContent());
        $this->assertStringNotContainsString('patient=C-2', $page->getContent());
    }

    public function test_the_counter_arrives_filled_with_the_prescription(): void
    {
        $this->hostQueue();
        $this->hostPrescription();

        $amoxicilline = $this->makeProduct(['name' => 'Amoxicilline 500 mg', 'dci' => 'Amoxicilline']);
        $this->stockUp($this->makeBatch($amoxicilline, 'LOT-A', now()->addYear()->toDateString()), 40);
        $this->makeLocation();

        $page = $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/comptoir?file=comptoir&patient=C-1')
            ->assertOk()
            // L'identité et l'ordonnance sont reprises de la file.
            ->assertSee('PAT-000123')
            ->assertSee('ORD-2026-000097')
            ->assertSee('Aminata Traoré')
            // Les trois lignes prescrites descendent, même celles qu'on ne
            // sait pas servir.
            ->assertSee('Amoxicilline 500 mg')
            ->assertSee('Paracétamol 1 g')
            ->assertSee('Sirop introuvable')
            ->assertSee('3 ligne(s) prescrite(s)');

        // La ligne servie est pré-remplie sur le produit rapproché par sa DCI.
        $this->assertStringContainsString(
            sprintf('<option value="%d" selected>', $amoxicilline->id),
            $page->getContent(),
        );
    }

    public function test_a_line_without_stock_is_flagged_in_red(): void
    {
        $this->hostQueue();
        $this->hostPrescription();

        // Le paracétamol est au catalogue, mais il n'en reste rien.
        $this->makeProduct(['name' => 'Paracétamol 1 g', 'dci' => 'Paracétamol']);
        $this->makeLocation();

        $content = $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/comptoir?file=comptoir&patient=C-1')
            ->assertOk()
            ->assertSee('Rien en stock, ici comme ailleurs')
            // Le sirop n'existe nulle part au catalogue : ce n'est pas la
            // même panne, et l'écran ne les confond pas.
            ->assertSee('Aucun produit du catalogue ne correspond')
            ->getContent();

        $this->assertStringContainsString('class="line out"', $content);
    }

    public function test_a_partial_stock_says_what_will_be_missing(): void
    {
        $this->hostQueue();
        $this->hostPrescription();

        $paracetamol = $this->makeProduct(['name' => 'Paracétamol 1 g', 'dci' => 'Paracétamol']);
        $this->stockUp($this->makeBatch($paracetamol, 'LOT-P', now()->addYear()->toDateString()), 4);
        $this->makeLocation();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/comptoir?file=comptoir&patient=C-1')
            ->assertOk()
            // 4 en stock pour 12 prescrits.
            ->assertSee('il manquera 8 unité(s)');
    }

    public function test_the_counter_without_a_queued_patient_stays_blank(): void
    {
        $this->makeLocation();
        $this->makeProduct();

        $content = $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/comptoir')
            ->assertOk()
            ->assertSee('Trois lignes à la fois')
            ->getContent();

        $this->assertStringNotContainsString('class="line out"', $content);
    }

    public function test_the_counter_serves_from_the_location_one_chooses(): void
    {
        $this->hostQueue();
        $this->hostPrescription();

        // Le comptoir est déclaré d'abord ; la chaîne du froid vient avant
        // lui dans l'alphabet. C'est l'ancienneté qui fait le défaut, sinon
        // le comptoir servirait depuis un réfrigérateur.
        $comptoir = $this->makeLocation('COMPR', 'Comptoir');
        $froid = $this->makeLocation('FROID', 'Chaine du froid');

        $amoxicilline = $this->makeProduct(['name' => 'Amoxicilline 500 mg', 'dci' => 'Amoxicilline']);
        $this->stockUp($this->makeBatch($amoxicilline, 'LOT-A', now()->addYear()->toDateString()), 40, $comptoir);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get('/pharmacie/comptoir?file=comptoir&patient=C-1')
            ->assertOk()
            ->assertSee('40 disponible(s)');

        // Vu depuis la chaîne du froid, le produit n'y est pas — mais il est
        // au comptoir, et la ligne propose d'aller l'y chercher plutôt que
        // d'annoncer une rupture.
        $this->get('/pharmacie/comptoir?file=comptoir&patient=C-1&emplacement='.$froid->id)
            ->assertOk()
            ->assertSee('Rien au comptoir, mais 40 à Comptoir')
            ->assertSee('Prendre depuis')
            ->assertDontSee('Rien en stock, ici comme ailleurs');
    }

    public function test_a_line_taken_elsewhere_leaves_the_stock_of_that_place(): void
    {
        $this->hostQueue();
        $this->hostPrescription();

        $comptoir = $this->makeLocation('COMPR', 'Comptoir');
        $centrale = $this->makeLocation('CENTRALE', 'Pharmacie centrale');

        // Le comptoir n'en a pas ; la centrale, si.
        $amoxicilline = $this->makeProduct(['name' => 'Amoxicilline 500 mg', 'dci' => 'Amoxicilline', 'sale_price' => 100]);
        $this->stockUp($this->makeBatch($amoxicilline, 'LOT-C', now()->addYear()->toDateString()), 50, $centrale);

        config(['pharmacie.dispensing.payment_before_delivery' => false]);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $comptoir->id,
                'patient_name' => 'Aminata Traoré',
                'lines' => [[
                    'product_id' => $amoxicilline->id,
                    'quantity' => '12',
                    'location_id' => $centrale->id,
                ]],
            ])
            ->assertSessionHas('pharmacie_status');

        $item = DispensationItem::query()->sole();

        // La ligne dit d'où elle vient, et le grand livre aussi : les unités
        // ont quitté la centrale, pas le comptoir.
        $this->assertSame($centrale->id, $item->location_id);
        $this->assertSame(12, (int) $item->quantity);

        $sortie = StockMovement::query()->where('kind', StockMovement::KIND_DISPENSING)->sole();

        $this->assertSame($centrale->id, (int) $sortie->location_id);
        $this->assertSame(38, (int) Stock::query()->where('location_id', $centrale->id)->value('quantity'));
        $this->assertSame(0, (int) Stock::query()->where('location_id', $comptoir->id)->sum('quantity'));
    }

    public function test_a_dosed_name_finds_its_product_and_a_word_does_not(): void
    {
        $this->makeLocation();

        $amoxicilline = $this->makeProduct(['name' => 'Amoxicilline', 'dci' => 'Amoxicilline']);
        // Même DCI, autre forme : le nom le plus précis doit l'emporter sans
        // que celle-ci brouille le rapprochement.
        $this->makeProduct(['name' => 'Amoxicilline suspension', 'dci' => 'Amoxicilline']);
        $eau = $this->makeProduct(['name' => 'Eau', 'dci' => null]);

        $matcher = app(PrescriptionMatcher::class);

        $this->assertSame(
            $amoxicilline->id,
            $matcher->product(new PrescriptionLine('Amoxicilline 500 mg', 20))?->id,
        );

        // « Eau oxygénée » n'est pas de l'eau dosée : ce qui suit le nom doit
        // être un chiffre, sinon on ne rapproche rien.
        $this->assertNull($matcher->product(new PrescriptionLine('Eau oxygénée', 1)));
        $this->assertSame($eau->id, $matcher->product(new PrescriptionLine('Eau', 1))?->id);
    }

    private function hostQueue(): FakePharmacyQueue
    {
        $queue = new FakePharmacyQueue([new PharmacyQueue('comptoir', 'Comptoir', 2)]);

        $queue->patients['comptoir'] = [
            new QueuedPatient(
                ref: 'C-1',
                patientId: 'PAT-000123',
                patientName: 'Aminata Traoré',
                reason: 'Ordonnance de consultation',
                prescriptionRef: 'ORD-2026-000097',
                waitingSince: now()->subMinutes(20),
                calledBy: 'Awa Fane',
            ),
            new QueuedPatient('C-2', 'PAT-000201', 'Ibrahim Keita', 'Achat libre', null, now()->subMinutes(5)),
        ];

        $this->app->instance(PharmacyQueueProvider::class, $queue);

        return $queue;
    }

    private function hostPrescription(): FakePrescriptions
    {
        $prescriptions = new FakePrescriptions([
            new Prescription(
                reference: 'ORD-2026-000097',
                patientId: 'PAT-000123',
                patientName: 'Aminata Traoré',
                lines: [
                    new PrescriptionLine('Amoxicilline 500 mg', 21, dosage: '1 gélule', frequency: '3 fois par jour', duration: '7 jours'),
                    new PrescriptionLine('Paracétamol 1 g', 12, dosage: '1 comprimé', frequency: '3 fois par jour'),
                    new PrescriptionLine('Sirop introuvable', 1),
                ],
                prescriber: 'Dr Diallo',
            ),
        ]);

        $this->app->instance(PrescriptionProvider::class, $prescriptions);

        return $prescriptions;
    }
}
