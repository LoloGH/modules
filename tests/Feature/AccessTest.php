<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature;

use Keneya\Pharmacie\Pharmacie;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * La porte d'entrée : c'est l'hôte qui décide qui entre dans le module.
 * Sans accord explicite, la pharmacie reste fermée.
 */
class AccessTest extends TestCase
{
    public function test_a_visitor_without_a_session_is_sent_to_the_host_login(): void
    {
        $this->get('/pharmacie')->assertRedirect('/connexion-hote');
    }

    public function test_without_the_host_grant_the_module_stays_closed(): void
    {
        $this->grantHostAccess(false);

        $this->actingAs($this->makeUser())->get('/pharmacie')->assertForbidden();
    }

    public function test_the_host_resolver_decides_before_anything_else(): void
    {
        $this->grantHostAccess(false);
        Pharmacie::authorizeAccessUsing(static fn (): bool => true);

        $this->actingAs($this->makeUser())->get('/pharmacie')->assertOk();

        Pharmacie::authorizeAccessUsing(static fn (): bool => false);

        $this->actingAs($this->makeUser())->get('/pharmacie')->assertForbidden();
    }

    public function test_the_dashboard_says_when_no_queue_is_provided(): void
    {
        $this->actingAs($this->makeUser())
            ->get('/pharmacie')
            ->assertOk()
            ->assertSee('Keneya')
            ->assertSee("L'application hôte ne fournit pas encore de file d'attente", false);
    }
}
