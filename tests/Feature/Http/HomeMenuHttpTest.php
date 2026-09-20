<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

/**
 * Le menu ne propose que ce que l'utilisateur a le droit de faire.
 */
class HomeMenuHttpTest extends HttpTestCase
{
    public function test_the_cashier_sees_only_the_desk_and_the_catalog(): void
    {
        $this->actingAs($this->cashier())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Ma caisse')
            // Il consulte le catalogue (il facture avec), il ne le gère pas.
            ->assertSee('Actes et prestations')
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
            ->assertSee('Caisses')
            ->assertSee('Actes et prestations')
            ->assertSee('Centres analytiques');
    }

    public function test_a_user_without_any_finance_right_sees_no_menu(): void
    {
        $this->actingAs($this->makeUser())
            ->get('/finance')
            ->assertOk()
            ->assertDontSee('Actes et prestations')
            ->assertDontSee('Ma caisse');
    }
}
