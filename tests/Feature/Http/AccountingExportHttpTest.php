<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AnalyticCenter;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Services\AccountingExport;
use Keneya\FinanceCaisse\Support\AccountingEntry;
use Keneya\FinanceCaisse\Support\LedgerFilters;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Les exports comptables : les écritures du module en partie double, avec
 * leurs comptes réglés par l'établissement.
 */
class AccountingExportHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private function caisse(TestUser $cashier): CashSession
    {
        return $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-TICKET'));
    }

    private function act(int $price, ?AnalyticCenter $center = null): Act
    {
        $act = Act::create(['code' => 'CONS', 'name' => 'Consultation', 'analytic_center_id' => $center?->id, 'is_active' => true]);
        $this->setTariff($act, $price);

        return $act;
    }

    /**
     * @return list<AccountingEntry>
     */
    private function entries(): array
    {
        return app(AccountingExport::class)->entries(new LedgerFilters(
            from: Carbon::today()->startOfDay(),
            to: Carbon::today()->endOfDay(),
        ));
    }

    public function test_every_fact_is_written_twice_and_the_export_balances(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 7_000, $cashier, ['description' => 'Consultation']);
        app(RecordDisbursement::class)->handle($session->refresh(), $this->cashMethod(), 2_000, 'Fournitures', $cashier, ['category' => 'fournitures']);

        $this->actingAs($cashier)->post(route('finance.cash.deposits.store', $session->refresh()), [
            'payment_method_id' => $this->cashMethod()->id,
            'amount' => '5 000',
            'patient_id' => 'PAT-000123',
        ]);

        $entries = $this->entries();
        $totals = app(AccountingExport::class)->totals($entries);

        $this->assertTrue($totals['balanced']);
        $this->assertSame(14_000, $totals['debit']);

        // Caisse : 7 000 encaissés + 5 000 d'avance − 2 000 décaissés.
        $balance = collect(app(AccountingExport::class)->balance($entries))->keyBy('account');

        $this->assertSame(12_000, $balance['571']['debit']);
        $this->assertSame(2_000, $balance['571']['credit']);
        $this->assertSame(7_000, $balance['706']['credit']);   // produits
        $this->assertSame(5_000, $balance['4191']['credit']);  // avance reçue
        $this->assertSame(2_000, $balance['605']['debit']);    // charge
    }

    public function test_an_invoice_settles_through_the_client_account(): void
    {
        $insurer = Insurer::create([
            'code' => 'AMO', 'name' => 'AMO', 'kind' => Insurer::KIND_INSURANCE,
            'default_rate' => 60, 'coverage_scope' => Insurer::SCOPE_ALL, 'is_active' => true,
        ]);

        $invoice = app(CreateInvoice::class)->handle(
            'PAT-000123',
            'Aminata Traoré',
            [['act_id' => $this->act(10_000)->id, 'quantity' => 1]],
            null,
            $this->makeUser(),
            ['insurer_id' => $insurer->id],
        );

        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 4_000, $cashier, ['invoice_id' => $invoice->id]);

        $balance = collect(app(AccountingExport::class)->balance($this->entries()))->keyBy('account');

        // La facture : 4 000 au patient, 6 000 à l'organisme, 10 000 de produits.
        $this->assertSame(4_000, $balance['4111']['debit']);
        $this->assertSame(6_000, $balance['4112']['debit']);
        $this->assertSame(10_000, $balance['706']['credit']);

        // L'encaissement solde le patient : il ne crée pas un produit de plus.
        $this->assertSame(4_000, $balance['4111']['credit']);
        $this->assertSame(4_000, $balance['571']['debit']);
        $this->assertTrue(app(AccountingExport::class)->totals($this->entries())['balanced']);
    }

    public function test_a_center_carries_its_own_revenue_account(): void
    {
        $labo = AnalyticCenter::create([
            'code' => 'LABO', 'name' => 'Laboratoire', 'kind' => AnalyticCenter::KIND_REVENUE,
            'account_code' => '7061', 'is_active' => true,
        ]);

        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 3_000, $cashier, ['act_id' => $this->act(3_000, $labo)->id]);

        $balance = collect(app(AccountingExport::class)->balance($this->entries()))->keyBy('account');

        $this->assertSame(3_000, $balance['7061']['credit']);
        $this->assertArrayNotHasKey('706', $balance->all());
    }

    public function test_a_cancelled_movement_never_reaches_the_accounts(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 9_000, $cashier);

        app(CancelCashMovement::class)->payment($payment, 'Erreur de saisie', $this->accountant());

        $this->assertSame([], $this->entries());
    }

    public function test_the_screen_shows_the_balance_and_downloads_the_journal(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 6_000, $cashier, ['description' => 'Consultation']);

        $this->actingAs($this->accountant())->get('/finance/comptabilite')
            ->assertOk()
            ->assertSee('Exports comptables')
            ->assertSee('Équilibré')
            ->assertSee('Caisse')
            ->assertSee('Produits des services');

        $csv = $this->get('/finance/comptabilite/journal')->assertOk()->streamedContent();
        $this->assertStringContainsString('Date;Journal;Pièce;Compte', $csv);
        $this->assertStringContainsString('571', $csv);

        $balance = $this->get('/finance/comptabilite/balance')->assertOk()->streamedContent();
        $this->assertStringContainsString('Compte;Libellé;Débit', $balance);

        // Le caissier n'exporte pas la comptabilité.
        $this->actingAs($cashier)->get('/finance/comptabilite')->assertForbidden();

        $this->actingAs($this->accountant())->get('/finance')
            ->assertSee(route('finance.accounting.index'), false);
    }

    public function test_the_accounts_are_set_in_the_settings_screen(): void
    {
        $this->actingAs($this->admin())
            ->post(route('finance.settings.update'), ['settings' => ['accounting.accounts.revenue' => '7062']])
            ->assertRedirect(route('finance.settings.index'));

        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $cashier);

        $balance = collect(app(AccountingExport::class)->balance($this->entries()))->keyBy('account');

        // Le compte réglé remplace celui du fichier, libellé compris.
        $this->assertArrayHasKey('7062', $balance->all());
        $this->assertSame('Produits des services', $balance['7062']['label']);
        $this->assertArrayNotHasKey('706', $balance->all());
    }
}
