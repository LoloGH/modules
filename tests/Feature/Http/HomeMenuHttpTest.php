<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

/**
 * Le menu ne propose que ce que l'utilisateur a le droit de faire.
 */
class HomeMenuHttpTest extends HttpTestCase
{
    public function test_the_cashier_sees_only_the_desk(): void
    {
        $this->actingAs($this->cashier())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Ma caisse')
            ->assertDontSee('Sessions à valider')
            ->assertDontSee('créer, activer ou désactiver');
    }

    public function test_the_accountant_sees_the_review_list(): void
    {
        $this->actingAs($this->accountant())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Sessions à valider');
    }

    public function test_the_administrator_sees_everything(): void
    {
        $this->actingAs($this->admin())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Ma caisse')
            ->assertSee('Sessions à valider')
            ->assertSee('Caisses');
    }
}
