<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Illuminate\Support\Carbon;
use Keneya\FinanceCaisse\Actions\CancelInvoice;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordInsuranceRejection;
use Keneya\FinanceCaisse\Actions\RecordInsuranceSettlement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * Créances patients et assurances : solde, échéance, statut, ancienneté.
 */
class ReceivablesHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private function invoice(string $patient, int $amount, ?Insurer $insurer = null, int $rate = 80, int $daysAgo = 0): Invoice
    {
        static $n = 0;
        $n++;
        $act = $this->makeAct('ACTE-'.$n);
        $this->setTariff($act, $amount);

        $invoice = app(CreateInvoice::class)->handle('PAT-0'.$n, $patient, [['act_id' => $act->id, 'quantity' => 1]], null, $this->makeUser(),
            $insurer === null ? null : ['insurer_id' => $insurer->id, 'rate' => $rate]);

        if ($daysAgo > 0) {
            $invoice->forceFill(['created_at' => Carbon::today()->subDays($daysAgo)->setTime(10, 0)])->save();
        }

        return $invoice->fresh();
    }

    private function insurer(): Insurer
    {
        return Insurer::create(['code' => 'INPS', 'name' => 'INPS', 'default_rate' => 80, 'is_active' => true]);
    }

    public function test_patient_receivables_show_balance_due_date_and_status(): void
    {
        $recent = $this->invoice('Aminata Traoré', 5_000);
        $old = $this->invoice('Moussa Diarra', 3_000, daysAgo: 45);
        $paid = $this->invoice('Awa Keita', 1_000);
        $cancelled = $this->invoice('Annulé Patient', 2_000);
        app(CancelInvoice::class)->handle($cancelled, 'Erreur', $this->makeUser());

        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $cashier, ['invoice_id' => $paid->id]);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $cashier, ['invoice_id' => $old->id]);

        $this->actingAs($cashier)->get('/finance/creances')
            ->assertOk()
            ->assertSee($recent->number)
            ->assertSee('Aminata Traoré')
            ->assertSee('Moussa Diarra')
            ->assertDontSee('Awa Keita')          // réglée
            ->assertDontSee('Annulé Patient')     // annulée
            ->assertSee('2 000 FCFA')             // solde de Moussa
            ->assertSee('Échue · 45 j')
            ->assertSee('7 000 FCFA')             // créances patients : 5 000 + 2 000
            ->assertSee('Ancienneté')
            ->assertSee('31 – 60 jours');

        $this->get('/finance/creances?statut=echue')->assertSee('Moussa Diarra')->assertDontSee('Aminata Traoré');
        $this->get('/finance/creances?q=Aminata')->assertSee('Aminata Traoré')->assertDontSee('Moussa Diarra');
    }

    public function test_the_patient_delay_is_configurable(): void
    {
        config(['finance.receivables.patient_due_days' => 15]);
        $this->invoice('Aminata Traoré', 5_000, daysAgo: 10);

        $this->actingAs($this->cashier())->get('/finance/creances')
            ->assertSee('À échoir')
            ->assertDontSee('Échue ·')
            ->assertSee('Délai : 15 jour(s)');
    }

    public function test_insurer_receivables_follow_settlements_and_rejections(): void
    {
        $insurer = $this->insurer();
        $pending = $this->invoice('Aminata Traoré', 10_000, $insurer, 80, daysAgo: 40);  // 8 000 dus, échue (> 30 j)
        $partial = $this->invoice('Moussa Diarra', 10_000, $insurer, 50);                 // 5 000 dus
        $settled = $this->invoice('Awa Keita', 10_000, $insurer, 80);

        app(RecordInsuranceSettlement::class)->handle($partial, 2_000, null, null, $this->makeUser());
        app(RecordInsuranceRejection::class)->handle($partial, 1_000, 'Non couvert', $this->makeUser());
        app(RecordInsuranceSettlement::class)->handle($settled, 8_000, null, null, $this->makeUser());

        $this->actingAs($this->accountant())->get('/finance/creances?type=assurances')
            ->assertOk()
            ->assertSee($pending->number)
            ->assertSee('INPS')
            ->assertSee('Moussa Diarra')
            ->assertDontSee('Awa Keita')
            ->assertSee('2 000 FCFA')             // reste dû sur la partielle : 5 000 − 2 000 − 1 000
            ->assertSee('rejeté 1 000 FCFA')
            ->assertSee('Échue · 10 j')           // 40 jours − 30 de délai
            ->assertSee('10 000 FCFA');           // créances assurances : 8 000 + 2 000

        // Le rejet a rendu 1 000 au patient : il figure dans les créances patients.
        $this->get('/finance/creances')->assertSee('Moussa Diarra');
    }

    public function test_without_the_right_it_is_forbidden(): void
    {
        $this->actingAs($this->makeUser())->get('/finance/creances')->assertForbidden();
        $this->actingAs($this->cashier())->get('/finance/creances')->assertOk();
    }
}
