<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Keneya\Pharmacie\Contracts\PharmacyQueueProvider;
use Keneya\Pharmacie\Queue\NoPharmacyQueue;
use Keneya\Pharmacie\Queue\PharmacyQueue;
use Keneya\Pharmacie\Queue\QueuedPatient;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakePharmacyQueue;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * La file d'attente de la pharmacie : le module l'affiche et appelle, l'hôte
 * la range. Sans hôte, elle est vide et l'écran le dit.
 */
class QueueHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    private function hostQueue(): FakePharmacyQueue
    {
        $queue = new FakePharmacyQueue([
            new PharmacyQueue('comptoir', 'Comptoir', 2),
            new PharmacyQueue('hospitalisation', 'Hospitalisation', 0),
        ]);

        $queue->patients['comptoir'] = [
            new QueuedPatient('C-1', 'PAT-000123', 'Aminata Traoré', 'Ordonnance de consultation', 'ORD-2026-000097', now()->subMinutes(20)),
            new QueuedPatient('C-2', 'PAT-000201', 'Ibrahim Keita', 'Achat libre', null, now()->subMinutes(5)),
        ];

        $this->app->instance(PharmacyQueueProvider::class, $queue);

        return $queue;
    }

    public function test_without_a_host_the_queue_is_empty_and_says_so(): void
    {
        $this->assertInstanceOf(NoPharmacyQueue::class, $this->app->make(PharmacyQueueProvider::class));

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->get('/pharmacie/file')
            ->assertOk()
            ->assertSee('Aucune file fournie');
    }

    public function test_the_counter_reads_the_queue_of_the_host(): void
    {
        $this->hostQueue();

        $this->actingAs($this->userWithRole(Rbac::ROLE_DISPENSER))
            ->get('/pharmacie/file')
            ->assertOk()
            ->assertSee('Comptoir')
            ->assertSee('Aminata Traoré')
            ->assertSee('ORD-2026-000097')
            ->assertSee('20 min')
            ->assertSee('En attente');
    }

    public function test_calling_the_next_patient_goes_through_the_host(): void
    {
        $queue = $this->hostQueue();
        $preparer = $this->userWithRole(Rbac::ROLE_DISPENSER);

        $this->actingAs($preparer)
            ->post(route('pharmacie.queue.call'), ['file' => 'comptoir'])
            ->assertRedirect(route('pharmacie.queue.index', ['file' => 'comptoir']))
            ->assertSessionHas('pharmacie_status');

        $this->assertSame([['queue' => 'comptoir', 'patient' => 'C-1', 'by' => $preparer->name]], $queue->calls);

        // Une file vide ne fait appeler personne.
        $this->post(route('pharmacie.queue.call'), ['file' => 'hospitalisation'])
            ->assertSessionHas('pharmacie_error');
    }

    public function test_the_queue_follows_the_rights(): void
    {
        $this->hostQueue();

        // Le magasinier tient le stock, il ne sert pas au comptoir.
        $this->actingAs($this->userWithRole(Rbac::ROLE_STOREKEEPER))
            ->get('/pharmacie/file')
            ->assertForbidden();

        $this->actingAs($this->userWithRole(Rbac::ROLE_STOREKEEPER))
            ->post(route('pharmacie.queue.call'), ['file' => 'comptoir'])
            ->assertForbidden();
    }
}
