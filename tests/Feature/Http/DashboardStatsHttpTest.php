<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Support\Rbac;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Les statistiques du tableau de bord : la période regardée, ce qu'elle
 * compare, d'où vient l'argent et ce qui reste dû.
 */
class DashboardStatsHttpTest extends HttpTestCase
{
    private function caisse(TestUser $cashier): CashSession
    {
        return $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-SERVICES'));
    }

    private function act(string $code, string $name, ?AnalyticCenter $center): Act
    {
        return Act::create(['code' => $code, 'name' => $name, 'analytic_center_id' => $center?->id, 'is_active' => true]);
    }

    private function center(string $code, string $name, string $kind = AnalyticCenter::KIND_REVENUE): AnalyticCenter
    {
        return AnalyticCenter::create(['code' => $code, 'name' => $name, 'kind' => $kind, 'is_active' => true]);
    }

    public function test_the_period_is_chosen_and_the_figures_follow_it(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        $hier = app(RecordPayment::class)->handle($session, $this->cashMethod(), 4_000, $cashier, ['description' => 'Hier']);
        $hier->forceFill(['created_at' => now()->subDay()])->save();

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 6_000, $cashier, ['description' => "Aujourd'hui"]);

        // Le jour : la recette du jour, comparée à la veille (+50 %).
        $this->actingAs($this->accountant())->get('/finance')
            ->assertOk()
            ->assertSee('Recettes du jour')
            ->assertSee('6 000')
            ->assertSee('+50 % vs hier');

        // Sept jours : les deux journées additionnées.
        $this->get('/finance?periode=7j')
            ->assertOk()
            ->assertSee('Recettes des 7 derniers jours')
            ->assertSee('10 000');

        // Une période inconnue retombe sur le jour plutôt que de casser.
        $this->get('/finance?periode=n-importe-quoi')->assertOk()->assertSee('Recettes du jour');
    }

    public function test_the_result_of_the_period_takes_the_expenses_out(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 20_000, $cashier, ['description' => 'Consultation']);
        app(RecordDisbursement::class)->handle($session->refresh(), $this->cashMethod(), 5_000, 'Fournitures', $cashier);

        $this->actingAs($this->accountant())->get('/finance')
            ->assertOk()
            ->assertSee('Résultat du jour')
            ->assertSee('15 000')
            ->assertSee('Dépenses du jour');
    }

    public function test_the_dashboard_says_where_the_money_comes_from(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        $labo = $this->center('LABO', 'Laboratoire');
        $nfs = $this->act('NFS', 'Numeration', $labo);

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 9_000, $cashier, ['act_id' => $nfs->id]);
        app(RecordPayment::class)->handle($session->refresh(), $this->cashMethod(), 1_000, $cashier, ['description' => 'Divers']);

        $this->actingAs($this->accountant())->get('/finance')
            ->assertOk()
            ->assertSee('Recettes par service')
            ->assertSee('Laboratoire')
            ->assertSee('90 % des recettes')
            ->assertSee('Non rattaché')
            ->assertSee('Actes les plus encaissés')
            ->assertSee('Numeration');
    }

    public function test_what_is_still_owed_and_what_awaits_a_decision_are_shown(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        // Une avance : de l'argent encaissé qui reste dû au patient.
        $this->actingAs($cashier)->post(route('finance.cash.deposits.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'amount' => '20 000',
            'patient_id' => 'PAT-000123',
            'patient_name' => 'Aminata Traoré',
        ]);

        // Une clôture qui attend le contrôle.
        $this->post(route('finance.cash.sessions.close', $session->refresh()), ['counted_cash' => '30 000']);

        $this->actingAs($this->accountant())->get('/finance')
            ->assertOk()
            ->assertSee('Ce qui reste dû')
            ->assertSee('Avances dues aux patients')
            ->assertSee('20 000 FCFA')
            ->assertSee('À traiter')
            ->assertSee('1 clôture(s) à valider');

        // Une avance n'est pas une recette : le résultat du jour reste nul.
        $this->assertSame(0, Payment::query()->valid()->count());
    }

    public function test_a_profile_only_reads_the_figures_it_may_see(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        $this->actingAs($cashier)->post(route('finance.cash.deposits.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'amount' => '5 000',
            'patient_id' => 'PAT-000123',
        ]);

        // La direction lit les créances, mais n'a pas le compte financier
        // des patients : la ligne des avances ne s'affiche pas chez elle.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DIRECTOR))->get('/finance')
            ->assertOk()
            ->assertSee('Créances des patients')
            ->assertDontSee('Avances dues aux patients');

        // Le caissier, lui, doit pouvoir dire au patient ce qu'il lui reste.
        $this->actingAs($cashier)->get('/finance')
            ->assertOk()
            ->assertSee('Avances dues aux patients');
    }
}
