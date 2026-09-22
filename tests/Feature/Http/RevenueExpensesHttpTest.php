<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * Recettes (encaissements valides, par service) et Dépenses (décaissements,
 * par catégorie), avec filtres et périmètre du caissier.
 */
class RevenueExpensesHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    // ------------------------------------------------------------ Recettes

    public function test_revenue_lists_valid_payments_by_source_service_and_activity(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $imagerie = $this->makeCenter('IMAGERIE');
        $labo = $this->makeCenter('LABORATOIRE');
        $echo = $this->makeAct('ECHO', $imagerie);
        $nfs = $this->makeAct('NFS', $labo);

        $record = app(RecordPayment::class);
        $record->handle($session, $this->cashMethod(), 7_500, $cashier, ['act_id' => $echo->id, 'reference' => 'REF-ECHO', 'patient_name' => 'Awa Keita']);
        $record->handle($session, $this->momoMethod(), 3_000, $cashier, ['act_id' => $nfs->id, 'reference' => 'OM-NFS']);
        $record->handle($session, $this->cashMethod(), 1_000, $cashier, ['description' => 'Avance']);
        $annule = $record->handle($session, $this->cashMethod(), 9_999, $cashier, ['act_id' => $echo->id]);
        app(CancelCashMovement::class)->payment($annule, 'Erreur', $cashier);

        $this->actingAs($cashier)->get('/finance/recettes')
            ->assertOk()
            ->assertSee('Recettes de la période')
            ->assertSee('11 500 FCFA')     // valides seulement
            ->assertDontSee('9 999 FCFA')
            ->assertSee('Acte ECHO')
            ->assertSee('Imagerie')
            ->assertSee('Laboratoire')
            ->assertSee('Hors catalogue')
            ->assertSee('REF-ECHO')
            ->assertSee('Awa Keita')
            ->assertSee('Par service');

        // Les références ne figurent que dans les lignes : elles disent ce que le filtre garde.
        $this->get('/finance/recettes?centre='.$labo->id)->assertSee('OM-NFS')->assertDontSee('REF-ECHO');
        $this->get('/finance/recettes?acte='.$echo->id)->assertSee('REF-ECHO')->assertDontSee('OM-NFS');
        $this->get('/finance/recettes?moyen='.$this->momoMethod()->id)->assertSee('OM-NFS')->assertDontSee('REF-ECHO');
    }

    // ------------------------------------------------------------ Dépenses

    public function test_the_disbursement_form_offers_categories_and_stores_one(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 50_000);

        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $session))
            ->assertSee('Catégorie')
            ->assertSee('Carburant et transport');

        $this->post(route('finance.cash.disbursements.store', $session), [
            'payment_method_id' => $this->cashMethod()->id, 'amount' => '5000',
            'reason' => 'Plein du véhicule', 'category' => 'carburant',
        ])->assertRedirect(route('finance.cash.sessions.show', $session));

        $this->assertSame('carburant', Disbursement::query()->sole()->category);

        // Une catégorie inconnue est refusée.
        $this->post(route('finance.cash.disbursements.store', $session), [
            'payment_method_id' => $this->cashMethod()->id, 'amount' => '100', 'reason' => 'X', 'category' => 'inventee',
        ])->assertSessionHasErrors('category');

        // Le bon imprimé porte la catégorie.
        $this->get(route('finance.cash.disbursements.receipt', Disbursement::query()->sole()))->assertSee('Carburant et transport');
    }

    public function test_expenses_list_every_column_with_categories_and_filters(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 100_000);
        $record = app(RecordDisbursement::class);

        $record->handle($session, $this->cashMethod(), 5_000, 'Plein du véhicule', $cashier, ['category' => 'carburant', 'beneficiary' => 'Station Total', 'reference' => 'BON-12']);
        $record->handle($session, $this->cashMethod(), 2_000, 'Rames de papier', $cashier, ['category' => 'fournitures']);
        $record->handle($session, $this->cashMethod(), 1_500, 'Sans catégorie', $cashier);
        $annule = $record->handle($session, $this->cashMethod(), 800, 'Annulée', $cashier, ['category' => 'divers']);
        app(CancelCashMovement::class)->disbursement($annule, 'Erreur', $cashier);

        $this->actingAs($cashier)->get('/finance/depenses')
            ->assertOk()
            ->assertSee('Dépenses de la période')
            ->assertSee('8 500 FCFA')          // valides seulement
            ->assertSee('Carburant et transport')
            ->assertSee('Station Total')
            ->assertSee('BON-12')
            ->assertSee('Non classée')
            ->assertSee('Annulé')
            ->assertSee('Par catégorie');

        $this->get('/finance/depenses?categorie=carburant')->assertSee('Plein du véhicule')->assertDontSee('Rames de papier');
        $this->get('/finance/depenses?categorie=aucune')->assertSee('Sans catégorie')->assertDontSee('Plein du véhicule');
        $this->get('/finance/depenses?statut=cancelled')->assertSee('Annulée')->assertDontSee('Rames de papier');
        $this->get('/finance/depenses?q=Station')->assertSee('Plein du véhicule')->assertDontSee('Rames de papier');
    }

    // ------------------------------------------------------------ Droits

    public function test_a_cashier_sees_only_his_own_control_sees_all(): void
    {
        $alice = $this->cashier();
        $bob = $this->cashier();
        $sa = $this->openSession($alice, 50_000, $this->makeRegister('A'));
        $sb = $this->openSession($bob, 50_000, $this->makeRegister('B'));

        app(RecordDisbursement::class)->handle($sa, $this->cashMethod(), 1_000, 'Dépense Alice', $alice);
        app(RecordDisbursement::class)->handle($sb, $this->cashMethod(), 1_000, 'Dépense Bob', $bob);
        app(RecordPayment::class)->handle($sa, $this->cashMethod(), 1_000, $alice, ['description' => 'Recette Alice']);
        app(RecordPayment::class)->handle($sb, $this->cashMethod(), 1_000, $bob, ['description' => 'Recette Bob']);

        $this->actingAs($alice)->get('/finance/depenses')->assertSee('Dépense Alice')->assertDontSee('Dépense Bob');
        $this->actingAs($alice)->get('/finance/recettes')->assertSee('Recette Alice')->assertDontSee('Recette Bob');

        $this->actingAs($this->accountant())->get('/finance/depenses')->assertSee('Dépense Alice')->assertSee('Dépense Bob');
        $this->actingAs($this->accountant())->get('/finance/recettes')->assertSee('Recette Alice')->assertSee('Recette Bob');

        $this->actingAs($this->makeUser())->get('/finance/recettes')->assertForbidden();
        $this->actingAs($this->makeUser())->get('/finance/depenses')->assertForbidden();
    }
}
