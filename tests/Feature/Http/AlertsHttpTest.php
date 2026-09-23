<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Services\AlertCenter;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Les alertes : ce qui attend un geste, pour celui qui peut le faire.
 */
class AlertsHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private function caisse(TestUser $cashier, int $float = 10_000): CashSession
    {
        return $this->openSession($cashier, $float, $this->makeRegister('CAISSE-TICKET'));
    }

    private function act(int $price = 10_000): Act
    {
        $act = Act::create(['code' => 'CONS', 'name' => 'Consultation', 'is_active' => true]);
        $this->setTariff($act, $price);

        return $act;
    }

    public function test_a_closure_with_a_gap_is_raised_to_the_control_only(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        app(CloseCashSession::class)->handle($session->refresh(), 8_000, $cashier, 'Billet manquant');

        // Le contrôle voit la clôture et son écart.
        $this->actingAs($this->accountant())->get('/finance/alertes')
            ->assertOk()
            ->assertSee('1 clôture(s) de caisse à valider')
            ->assertSee('1 avec un écart')
            ->assertSee('À faire maintenant');

        // Le caissier ne valide pas : l'alerte ne lui est pas adressée.
        $this->actingAs($cashier)->get('/finance/alertes')
            ->assertOk()
            ->assertSee('Rien à signaler');
    }

    public function test_the_bell_counts_what_awaits_the_reader(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        app(CloseCashSession::class)->handle($session->refresh(), 10_000, $cashier);

        $accountant = $this->accountant();
        $this->assertSame(1, app(AlertCenter::class)->count($accountant));
        $this->assertSame(0, app(AlertCenter::class)->count($cashier));

        $this->actingAs($accountant)->get('/finance')
            ->assertOk()
            ->assertSee('1 alerte(s) à traiter')
            ->assertSee(route('finance.alerts.index'), false);

        // Validée, l'alerte disparaît d'elle-même.
        $this->post(route('finance.review.approve', CashSession::query()->sole()), ['note' => 'Compté ensemble'])
            ->assertRedirect();

        $this->assertSame(0, app(AlertCenter::class)->count($accountant));
        $this->actingAs($accountant)->get('/finance/alertes')->assertSee('Rien à signaler');
    }

    public function test_an_overdue_receivable_is_raised_with_its_amount(): void
    {
        $invoice = app(CreateInvoice::class)->handle(
            'PAT-000123',
            'Aminata Traoré',
            [['act_id' => $this->act(12_000)->id, 'quantity' => 1]],
            null,
            $this->makeUser(),
        );

        // Le délai des patients est de 0 jour : une facture d'hier est échue.
        $invoice->forceFill(['created_at' => now()->subDays(3)])->save();

        $this->actingAs($this->accountant())->get('/finance/alertes')
            ->assertOk()
            ->assertSee('1 créance(s) de patients échue(s)')
            ->assertSee('12 000 FCFA');
    }

    public function test_a_forgotten_session_and_a_full_drawer_are_raised_to_their_cashier(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier, 40_000);
        $session->forceFill(['opened_at' => now()->subHours(20)])->save();

        app(RecordPayment::class)->handle($session->refresh(), $this->cashMethod(), 20_000, $cashier);

        config()->set('finance.alerts.cash_ceiling', 50_000);

        $this->actingAs($cashier)->get('/finance/alertes')
            ->assertOk()
            ->assertSee('session(s) ouverte(s) depuis plus de 12 heures')
            ->assertSee('Tiroir Caisse CAISSE-TICKET au-dessus du plafond')
            ->assertSee('60 000 FCFA');

        // Sans plafond réglé, rien n'est dit sur le tiroir.
        config()->set('finance.alerts.cash_ceiling', 0);

        $this->actingAs($cashier)->get('/finance/alertes')
            ->assertDontSee('au-dessus du plafond');
    }

    public function test_the_thresholds_are_set_in_the_settings_screen(): void
    {
        $this->actingAs($this->admin())->get('/finance/parametres')
            ->assertOk()
            ->assertSee('Plafond d&#039;espèces dans un tiroir', false)
            ->assertSee('Session ouverte signalée après (heures)');

        $this->post(route('finance.settings.update'), ['settings' => ['alerts.cash_ceiling' => '75 000']])
            ->assertRedirect(route('finance.settings.index'));

        $this->assertSame(75000, config('finance.alerts.cash_ceiling'));
    }
}
