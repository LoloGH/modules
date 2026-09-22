<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * L'écran d'encaissement propose le catalogue des actes comme motif.
 */
class PaymentActHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    public function test_the_payment_form_offers_the_active_acts_grouped_by_centre(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $centre = $this->makeCenter('LABORATOIRE');
        $act = $this->makeAct('LAB-GE', $centre);
        $this->setTariff($act, 1_500);

        $retired = $this->makeAct('ANCIEN', $centre);
        $retired->update(['is_active' => false]);

        $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee('Acte encaissé')
            ->assertSee('<optgroup label="Laboratoire">', false)
            ->assertSee('<option value="'.$act->id.'"', false)
            // Le tarif du jour aide le caissier à saisir le bon montant.
            ->assertSee('1 500 FCFA')
            // Un acte désactivé ne se facture plus.
            ->assertDontSee('<option value="'.$retired->id.'"', false);
    }

    public function test_the_cashier_records_a_payment_against_an_act(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $act = $this->makeAct('CONS-GEN', $this->makeCenter('CONSULTATION'));

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'act_id' => $act->id,
            'amount' => '2 000',
            'patient_name' => 'Fatoumata Traoré',
        ])->assertRedirect(route('finance.cash.sessions.show', $session));

        $payment = Payment::query()->sole();

        $this->assertSame($act->id, $payment->act_id);
        $this->assertSame(2_000, $payment->amount);
        $this->assertSame($act->name, $payment->description);
    }

    public function test_the_act_and_its_centre_show_in_the_operations_list(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $act = $this->makeAct('IMG-ECHO', $this->makeCenter('IMAGERIE'));

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'act_id' => $act->id,
            'amount' => '7500',
        ]);

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee($act->name)
            ->assertSee('Imagerie');
    }

    public function test_a_payment_without_an_act_still_goes_through(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'act_id' => '',
            'amount' => '5000',
            'description' => 'Avance sur hospitalisation',
        ])->assertRedirect(route('finance.cash.sessions.show', $session));

        $this->assertNull(Payment::query()->sole()->act_id);
    }

    public function test_an_unknown_act_is_a_field_error(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->from(route('finance.cash.sessions.show', $session))
            ->actingAs($cashier)
            ->post(route('finance.cash.payments.store', $session), [
                'payment_method_id' => $this->cashMethod()->id,
                'act_id' => 4_242,
                'amount' => '2000',
            ])
            ->assertSessionHasErrors('act_id');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_deactivated_act_comes_back_with_a_readable_message(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $act = $this->makeAct('CONS-GEN');
        $act->update(['is_active' => false]);

        $this->from(route('finance.cash.sessions.show', $session))
            ->actingAs($cashier)
            ->post(route('finance.cash.payments.store', $session), [
                'payment_method_id' => $this->cashMethod()->id,
                'act_id' => $act->id,
                'amount' => '2000',
            ])
            ->assertRedirect(route('finance.cash.sessions.show', $session))
            ->assertSessionHas('finance_error');

        $this->assertSame(0, Payment::count());
    }

    /**
     * Le report du tarif dans le champ « Montant » se fait dans le
     * navigateur ; ce qu'on peut vérifier ici, c'est le contrat que la page
     * lui fournit : le prix sur l'option, et la cible à remplir.
     */
    public function test_the_page_carries_what_the_amount_filler_needs(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $priced = $this->makeAct('CONS-GEN', $this->makeCenter('CONSULTATION'));
        $this->setTariff($priced, 2_000);

        $this->makeAct('SANS-TARIF', $this->makeCenter('URGENCES'));

        $response = $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            // Le montant brut, sans espace ni devise : c'est ce que lit le script.
            ->assertSee('data-amount="2000"', false)
            ->assertSee('data-fills="montant-encaissement"', false)
            ->assertSee('id="montant-encaissement"', false);

        // Deux actes au catalogue, mais un seul tarifé : l'autre ne porte
        // aucun montant à reporter.
        $this->assertSame(1, substr_count($response->getContent(), 'data-amount='));
    }

    public function test_an_act_whose_tariff_changed_carries_the_new_price(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $act = $this->makeAct('CONS-GEN');
        $this->setTariff($act, 2_000);
        $this->setTariff($act, 2_500);

        $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee('data-amount="2500"', false)
            ->assertDontSee('data-amount="2000"', false);
    }

    public function test_an_empty_catalogue_does_not_break_the_form(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)
            ->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee('Acte encaissé')
            ->assertSee('Aucun (encaissement hors catalogue)');
    }
}
