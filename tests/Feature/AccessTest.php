<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Standalone\StandaloneMode;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * La porte d'entrée : c'est l'hôte qui décide, et sans décision le module
 * reste fermé.
 */
class AccessTest extends TestCase
{
    public function test_a_visitor_is_sent_to_the_host_login(): void
    {
        $this->get('/finance')->assertRedirect(route('login'));
    }

    public function test_a_user_with_the_host_grant_reaches_the_module(): void
    {
        $this->actingAs($this->makeUser())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Keneya Finance');
    }

    public function test_a_user_without_the_host_grant_is_forbidden(): void
    {
        $this->grantHostAccess(false);

        $this->actingAs($this->makeUser())
            ->get('/finance')
            ->assertForbidden();
    }

    public function test_the_boolean_attribute_is_accepted_as_a_grant(): void
    {
        $this->grantHostAccess(false);

        $user = $this->makeUser();
        $user->can_access_finance = true;

        $this->actingAs($user)->get('/finance')->assertOk();
    }

    public function test_the_host_resolver_has_the_final_say(): void
    {
        // Capacité accordée, mais le résolveur de l'hôte refuse : il l'emporte.
        Finance::authorizeAccessUsing(static fn () => false);

        $this->actingAs($this->makeUser())->get('/finance')->assertForbidden();

        // Capacité refusée, mais le résolveur accorde : il l'emporte aussi.
        $this->grantHostAccess(false);
        Finance::authorizeAccessUsing(static fn () => true);

        $this->actingAs($this->makeUser())->get('/finance')->assertOk();
    }

    public function test_standalone_mode_opens_the_module_without_a_session(): void
    {
        $this->grantHostAccess(false);
        config()->set('finance.standalone.enabled', true);

        $this->get('/finance')->assertOk();
    }

    public function test_standalone_mode_is_refused_in_production(): void
    {
        $this->app->detectEnvironment(static fn () => 'production');
        config()->set('finance.standalone.enabled', true);

        $mode = $this->app->make(StandaloneMode::class);

        $this->assertTrue($mode->requested());
        $this->assertTrue($mode->refusedInProduction());
        $this->assertFalse($mode->enabled());

        // Et l'accès reste fermé au visiteur.
        $this->get('/finance')->assertRedirect(route('login'));
    }
}
