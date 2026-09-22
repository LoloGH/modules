<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * Écran Assurances, prise en charge à l'émission d'une facture, règlements et
 * rejets depuis la fiche facture, droits.
 */
class InsuranceHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private function insurer(): Insurer
    {
        return Insurer::create(['code' => 'INPS', 'name' => 'INPS', 'default_rate' => 80, 'is_active' => true]);
    }

    private function coveredInvoice(): Invoice
    {
        $act = $this->makeAct('ECHO');
        $this->setTariff($act, 10_000);

        return app(CreateInvoice::class)->handle('PAT-00001', 'Aminata Traoré', [['act_id' => $act->id, 'quantity' => 1]], null, $this->makeUser(),
            ['insurer_id' => $this->insurer()->id, 'rate' => 80, 'policy_number' => 'PEC-77']);
    }

    public function test_the_menu_leads_to_insurance_and_the_screen_shows_the_shares(): void
    {
        $invoice = $this->coveredInvoice();

        $this->actingAs($this->accountant())->get('/finance/assurances')
            ->assertOk()
            ->assertSee('Prises en charge')
            ->assertSee($invoice->number)
            ->assertSee('Aminata Traoré')
            ->assertSee('INPS')
            ->assertSee('PEC-77')
            ->assertSee('8 000 FCFA')
            ->assertSee('2 000 FCFA')
            ->assertSee('En attente')
            ->assertSee('Créance assurance')
            ->assertSee('Nouvel assureur');
    }

    public function test_the_control_creates_and_toggles_an_insurer(): void
    {
        $this->actingAs($this->accountant())->post(route('finance.insurers.store'), [
            'code' => 'amo', 'name' => 'Assurance maladie obligatoire', 'default_rate' => '70',
        ])->assertRedirect(route('finance.insurance.index'));

        $insurer = Insurer::query()->sole();
        $this->assertSame('AMO', $insurer->code);
        $this->assertSame(70, $insurer->default_rate);

        $this->post(route('finance.insurers.toggle', $insurer))->assertRedirect();
        $this->assertFalse($insurer->fresh()->is_active);
    }

    public function test_the_cashier_applies_a_coverage_when_issuing_an_invoice(): void
    {
        $insurer = $this->insurer();
        $act = $this->makeAct('CONS');
        $this->setTariff($act, 5_000);

        $this->actingAs($this->cashier())->get(route('finance.invoices.create'))
            ->assertSee('Prise en charge')
            ->assertSee('INPS (80 %)');

        $this->post(route('finance.invoices.store'), [
            'patient_name' => 'Awa Keita',
            'lines' => [['act_id' => $act->id, 'quantity' => '2']],
            'insurer_id' => $insurer->id,
            'coverage_rate' => '75',
            'policy_number' => 'PEC-9',
        ]);

        $invoice = Invoice::query()->sole();
        $this->assertSame(7_500, $invoice->insurer_share);
        $this->assertSame(2_500, $invoice->patient_share);

        $this->get(route('finance.invoices.show', $invoice))
            ->assertSee('Prise en charge')
            ->assertSee('7 500 FCFA')
            ->assertSee('2 500 FCFA')
            ->assertDontSee("Enregistrer un règlement de l'assureur", false);   // le caissier ne règle pas

        // La facture imprimée porte les deux parts.
        $this->get(route('finance.invoices.print', $invoice))
            ->assertSee('Part assurance')
            ->assertSee('Part patient')
            ->assertSee('PEC-9');
    }

    public function test_a_rate_is_required_with_an_insurer(): void
    {
        $insurer = $this->insurer();
        $act = $this->makeAct('CONS');
        $this->setTariff($act, 5_000);

        $this->actingAs($this->cashier())->post(route('finance.invoices.store'), [
            'patient_name' => 'Awa', 'lines' => [['act_id' => $act->id, 'quantity' => '1']], 'insurer_id' => $insurer->id,
        ])->assertSessionHasErrors('coverage_rate');
    }

    public function test_the_control_records_a_settlement_and_a_rejection_from_the_invoice(): void
    {
        $invoice = $this->coveredInvoice();

        $this->actingAs($this->accountant())->get(route('finance.invoices.show', $invoice))
            ->assertSee('Enregistrer un règlement de l\'assureur', false)
            ->assertSee('Enregistrer un rejet');

        $this->post(route('finance.insurance.settle', $invoice), ['amount' => '5 000', 'reference' => 'VIR-88'])
            ->assertRedirect(route('finance.invoices.show', $invoice))
            ->assertSessionHas('finance_status');

        $this->post(route('finance.insurance.reject', $invoice), ['amount' => '1000', 'reason' => 'Acte non couvert'])
            ->assertRedirect(route('finance.invoices.show', $invoice));

        $invoice->refresh();
        $this->assertSame(5_000, $invoice->insurer_paid);
        $this->assertSame(1_000, $invoice->insurer_rejected);
        $this->assertSame(2_000, $invoice->insurerOutstanding());
        $this->assertSame(3_000, $invoice->balance());

        $this->get('/finance/assurances')
            ->assertSee('VIR-88')
            ->assertSee('Acte non couvert')
            ->assertSee('Partiellement réglée');

        // Au-delà du reste dû : refusé.
        $this->from(route('finance.invoices.show', $invoice))
            ->post(route('finance.insurance.settle', $invoice), ['amount' => '9000'])
            ->assertSessionHas('finance_error');
    }

    public function test_rights_are_checked_route_by_route(): void
    {
        $invoice = $this->coveredInvoice();

        $this->actingAs($this->makeUser())->get('/finance/assurances')->assertForbidden();

        $cashier = $this->cashier();
        $this->actingAs($cashier)->get('/finance/assurances')->assertOk()->assertDontSee('Nouvel assureur');
        $this->actingAs($cashier)->post(route('finance.insurance.settle', $invoice), ['amount' => '100'])->assertForbidden();
        $this->actingAs($cashier)->post(route('finance.insurers.store'), ['code' => 'X', 'name' => 'X', 'default_rate' => '50'])->assertForbidden();
    }
}
