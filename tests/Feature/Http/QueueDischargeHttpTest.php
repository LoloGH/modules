<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Illuminate\Contracts\Auth\Authenticatable;
use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Contracts\QueueDischarge;
use Keneya\Pharmacie\Contracts\SaleSink;
use Keneya\Pharmacie\Exceptions\PharmacieRuleViolation;
use Keneya\Pharmacie\Models\Dispensation;
use Keneya\Pharmacie\Queue\NoQueueDischarge;
use Keneya\Pharmacie\Queue\PharmacyQueue;
use Keneya\Pharmacie\Queue\QueueDestination;
use Keneya\Pharmacie\Queue\QueuedPatient;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakePharmacyQueue;
use Keneya\Pharmacie\Tests\Support\FakeSaleSink;
use Keneya\Pharmacie\Tests\Support\StockFixtures;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * La boucle du comptoir, et comment on en sort.
 *
 * Un patient revenu de la caisse réapparaît dans la file comme un arrivant.
 * Si l'écran lui proposait « Préparer », le comptoir écrirait une seconde
 * préparation, enverrait une seconde facture, et le patient repartirait
 * payer : personne n'en sortirait. Ce qui l'attend est une délivrance, puis
 * une sortie de file.
 */
class QueueDischargeHttpTest extends TestCase
{
    use StockFixtures;

    private FakePharmacyQueue $queue;

    private FakeQueueDischarge $discharge;

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();

        $this->queue = new FakePharmacyQueue([new PharmacyQueue('comptoir', 'Comptoir', 1)]);
        $this->queue->patients['comptoir'] = [
            new QueuedPatient('V-7', 'PAT-00003', 'Sylla Baba', 'Ticket n° 1', null, now()->subMinutes(5), 'la pharmacie'),
        ];

        $this->discharge = new FakeQueueDischarge;

        $this->app->instance(PharmacyQueueProvider::class, $this->queue);
        $this->app->instance(QueueDischarge::class, $this->discharge);
        $this->app->instance(SaleSink::class, new FakeSaleSink('FAC-2026-000001'));

        config(['pharmacie.dispensing.payment_before_delivery' => true]);
    }

    public function test_a_patient_with_a_preparation_is_offered_delivery_not_a_second_one(): void
    {
        $preparation = $this->prepare();

        $page = $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get(route('pharmacie.queue.index', ['file' => 'comptoir']))
            ->assertOk()
            ->assertSee('À délivrer')
            ->assertSee('Délivrer');

        // Le chemin propose est celui de la preparation, pas celui du comptoir.
        $this->assertStringContainsString(route('pharmacie.preparations.show', $preparation), $page->getContent());
        $this->assertStringNotContainsString('patient=V-7', $page->getContent());
    }

    public function test_the_counter_sends_back_to_the_preparation_that_is_waiting(): void
    {
        $preparation = $this->prepare();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get(route('pharmacie.dispensing.create', ['file' => 'comptoir', 'patient' => 'V-7']))
            ->assertRedirect(route('pharmacie.preparations.show', $preparation))
            ->assertSessionHas('pharmacie_status');
    }

    public function test_a_second_preparation_is_refused_even_by_the_direct_road(): void
    {
        $this->prepare();

        $product = $this->makeProduct(['name' => 'Paracétamol', 'sale_price' => 500]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-B', now()->addYear()->toDateString()), 20);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_name' => 'Sylla Baba',
                'queue_ref' => 'V-7',
                'lines' => [['product_id' => $product->id, 'quantity' => '5']],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(1, Dispensation::query()->count());
    }

    public function test_a_served_patient_is_closed_or_sent_elsewhere(): void
    {
        $this->serve();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get(route('pharmacie.queue.index', ['file' => 'comptoir']))
            ->assertOk()
            ->assertSee('Servi')
            ->assertSee('Terminer')
            ->assertSee('Clôturer le passage')
            ->assertSee('Médecine générale');

        $this->post(route('pharmacie.queue.close'), ['file' => 'comptoir', 'patient' => 'V-7'])
            ->assertSessionHas('pharmacie_status');

        $this->assertSame([['queue' => 'comptoir', 'patient' => 'V-7', 'reason' => null]], $this->discharge->closed);
    }

    public function test_sending_elsewhere_carries_the_service_and_the_reason(): void
    {
        $this->serve();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.queue.refer'), [
                'file' => 'comptoir',
                'patient' => 'V-7',
                'destination' => '4',
                'reason' => 'Contrôle de la tension',
            ])
            ->assertSessionHas('pharmacie_status');

        $this->assertSame(
            [['queue' => 'comptoir', 'patient' => 'V-7', 'destination' => '4', 'reason' => 'Contrôle de la tension']],
            $this->discharge->referred,
        );
    }

    public function test_a_refusal_of_the_host_is_shown_not_swallowed(): void
    {
        $this->serve();

        $this->discharge->refuse = 'Le patient doit avoir ete appele avant que son dossier puisse etre cloture.';

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.queue.close'), ['file' => 'comptoir', 'patient' => 'V-7'])
            ->assertSessionHas('pharmacie_error', 'Le patient doit avoir ete appele avant que son dossier puisse etre cloture.');
    }

    public function test_without_a_host_the_screen_says_it_cannot_close(): void
    {
        $this->app->instance(QueueDischarge::class, new NoQueueDischarge);
        $this->serve();

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get(route('pharmacie.queue.index', ['file' => 'comptoir']))
            ->assertOk()
            ->assertSee('ne propose aucun service de destination');

        $this->post(route('pharmacie.queue.close'), ['file' => 'comptoir', 'patient' => 'V-7'])
            ->assertSessionHas('pharmacie_error');
    }

    /**
     * Une préparation écrite pour le patient de la file, rien de plus : le
     * stock n'a pas bougé, la facture est partie.
     */
    private function prepare(): Dispensation
    {
        $product = $this->makeProduct(['name' => 'Amoxicilline', 'sale_price' => 1_000]);
        $location = $this->makeLocation();
        $this->stockUp($this->makeBatch($product, 'LOT-A', now()->addYear()->toDateString()), 40);

        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->post(route('pharmacie.dispensing.store'), [
                'location_id' => $location->id,
                'patient_id' => 'PAT-00003',
                'patient_name' => 'Sylla Baba',
                'queue_ref' => 'V-7',
                'lines' => [['product_id' => $product->id, 'quantity' => '10']],
            ]);

        return Dispensation::query()->latest('id')->firstOrFail();
    }

    /** Le patient a payé, et le comptoir l'a servi. */
    private function serve(): Dispensation
    {
        $preparation = $this->prepare();

        $preparation->update([
            'status' => Dispensation::STATUS_DISPENSED,
            'payment_status' => Dispensation::PAYMENT_SETTLED,
            'dispensed_at' => now(),
        ]);

        return $preparation->refresh();
    }
}

/**
 * La sortie de file d'un hôte, en mémoire : elle retient ce qu'on lui a
 * demandé, et sait refuser comme le ferait WorkFlow.
 */
final class FakeQueueDischarge implements QueueDischarge
{
    /** @var list<array{queue: string, patient: string, reason: ?string}> */
    public array $closed = [];

    /** @var list<array{queue: string, patient: string, destination: string, reason: ?string}> */
    public array $referred = [];

    public ?string $refuse = null;

    public function destinations(): array
    {
        return [
            new QueueDestination('4', 'Médecine générale', 'Clinique'),
            new QueueDestination('6', 'Laboratoire', 'Plateau technique'),
        ];
    }

    public function close(string $queueRef, string $patientRef, ?string $reason, Authenticatable $actor): void
    {
        $this->assertAllowed();

        $this->closed[] = ['queue' => $queueRef, 'patient' => $patientRef, 'reason' => $reason];
    }

    public function refer(string $queueRef, string $patientRef, string $destinationRef, ?string $reason, Authenticatable $actor): void
    {
        $this->assertAllowed();

        $this->referred[] = [
            'queue' => $queueRef,
            'patient' => $patientRef,
            'destination' => $destinationRef,
            'reason' => $reason,
        ];
    }

    private function assertAllowed(): void
    {
        if ($this->refuse !== null) {
            throw new PharmacieRuleViolation($this->refuse);
        }
    }
}
