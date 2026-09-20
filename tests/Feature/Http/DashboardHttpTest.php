<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Support\Rbac;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Tableau de bord : il ne montre que des chiffres réels, et seulement à qui
 * a le droit de les lire.
 */
class DashboardHttpTest extends HttpTestCase
{
    public function test_the_cashier_sees_the_figures_and_the_way_to_the_desk(): void
    {
        $this->actingAs($this->cashier())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Recettes du jour')
            ->assertSee('Ma caisse')
            ->assertSee('État de la caisse');
    }

    public function test_the_director_reads_the_figures_without_a_cash_desk(): void
    {
        // La direction a `dashboard.view` mais pas `sessions.view` : elle lit
        // les chiffres, elle n'a pas de tiroir.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DIRECTOR))
            ->get('/finance')
            ->assertOk()
            ->assertSee('Recettes du jour')
            ->assertDontSee('État de la caisse');
    }

    public function test_a_user_without_any_right_gets_no_figure_at_all(): void
    {
        $this->actingAs($this->makeUser())
            ->get('/finance')
            ->assertOk()
            ->assertDontSee('Recettes du jour')
            ->assertDontSee('Dernières transactions')
            // Les entrées « bientôt » montrent la cible du module : elles
            // n'ont rien à faire devant quelqu'un qui n'a accès à rien.
            ->assertDontSee('Factures');
    }

    public function test_the_figures_come_from_the_real_entries(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000);

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 7_500, $cashier, ['description' => 'Consultation générale']);

        $this->actingAs($cashier)
            ->get('/finance')
            ->assertOk()
            // Le montant du jour, et l'opération dans la liste.
            ->assertSee('7 500')
            ->assertSee('Consultation générale');
    }

    public function test_an_empty_base_says_so_instead_of_showing_invented_numbers(): void
    {
        $this->actingAs($this->accountant())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Aucun encaissement sur la période')
            ->assertSee('Aucun encaissement enregistré');
    }

    public function test_the_menu_only_offers_what_the_profile_may_open(): void
    {
        $this->actingAs($this->cashier())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Centres analytiques')
            ->assertDontSee('Sessions à valider');

        $this->actingAs($this->admin())
            ->get('/finance')
            ->assertOk()
            ->assertSee('Sessions à valider')
            ->assertSee('Caisses');
    }

    public function test_the_open_session_of_the_cashier_is_summarised(): void
    {
        $cashier = $this->cashier();
        $this->openSession($cashier, 25_000);

        $this->actingAs($cashier)
            ->get('/finance')
            ->assertOk()
            ->assertSee('Solde théorique')
            ->assertSee('25 000 FCFA');
    }

    /**
     * Un caissier ne voit jamais le tiroir d'un autre sur son tableau de bord.
     */
    public function test_the_desk_summary_is_personal(): void
    {
        $mine = $this->cashier();
        $other = $this->cashier();

        $this->openSession($other, 25_000, $this->makeRegister('CAISSE-2'));

        $this->actingAs($mine)
            ->get('/finance')
            ->assertOk()
            ->assertSee('Aucune session ouverte')
            ->assertDontSee('Solde théorique');
    }

    public function test_it_survives_a_user_model_without_roles(): void
    {
        // L'en-tête affiche le profil Finance de l'utilisateur ; le modèle
        // utilisateur appartient à l'hôte et peut ne pas connaître les rôles.
        $user = TestUser::create(['name' => 'Hôte Sans Rôle', 'email' => 'hote@keneya.test', 'password' => 'secret']);

        $this->actingAs($user)->get('/finance')->assertOk()->assertSee('Hôte Sans Rôle');
    }
}
