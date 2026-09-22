<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CancelInvoice;
use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordInsuranceRejection;
use Keneya\FinanceCaisse\Actions\RecordInsuranceSettlement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Insurer;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Prise en charge : part assurance et part patient, règlements et rejets de
 * l'assureur, et ce que chacun doit encore.
 */
class InsuranceTest extends TestCase
{
    use CashFixtures;
    use CatalogFixtures;

    private function insurer(int $rate = 80, bool $active = true): Insurer
    {
        static $n = 0;
        $n++;

        return Insurer::create(['code' => 'ASS-'.$n, 'name' => 'Assureur '.$n, 'default_rate' => $rate, 'is_active' => $active]);
    }

    private function invoice(int $amount, ?Insurer $insurer = null, int $rate = 80): Invoice
    {
        static $n = 0;
        $n++;
        $act = $this->makeAct('ACTE-'.$n);
        $this->setTariff($act, $amount);

        return app(CreateInvoice::class)->handle('PAT-00001', 'Aminata Traoré', [['act_id' => $act->id, 'quantity' => 1]], null, $this->makeUser(),
            $insurer === null ? null : ['insurer_id' => $insurer->id, 'rate' => $rate, 'policy_number' => 'PEC-77']);
    }

    public function test_a_covered_invoice_splits_insurer_and_patient_shares(): void
    {
        $invoice = $this->invoice(10_000, $this->insurer(), 80);

        $this->assertSame(8_000, $invoice->insurer_share);
        $this->assertSame(2_000, $invoice->patient_share);
        $this->assertSame(80, $invoice->coverage_rate);
        $this->assertSame('PEC-77', $invoice->policy_number);
        $this->assertSame(2_000, $invoice->balance());
        $this->assertSame(8_000, $invoice->insurerOutstanding());
        $this->assertSame(Invoice::STATUS_UNPAID, $invoice->status);
        $this->assertSame(Invoice::CLAIM_PENDING, $invoice->claim_status);
    }

    public function test_the_insurer_share_is_rounded_to_the_franc(): void
    {
        $invoice = $this->invoice(2_345, $this->insurer(), 70); // 1 641,5

        $this->assertSame(1_642, $invoice->insurer_share);
        $this->assertSame(703, $invoice->patient_share);
        $this->assertSame(2_345, $invoice->insurer_share + $invoice->patient_share);
    }

    public function test_a_full_coverage_leaves_nothing_for_the_patient(): void
    {
        $invoice = $this->invoice(5_000, $this->insurer(), 100);

        $this->assertSame(0, $invoice->balance());
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertFalse($invoice->canBePaid());
    }

    public function test_without_insurer_nothing_changes(): void
    {
        $invoice = $this->invoice(3_000);

        $this->assertSame(3_000, $invoice->patient_share);
        $this->assertSame(0, $invoice->insurer_share);
        $this->assertNull($invoice->claim_status);
        $this->assertSame(3_000, $invoice->balance());
    }

    public function test_an_inactive_insurer_or_a_wrong_rate_is_refused(): void
    {
        $act = $this->makeAct('X');
        $this->setTariff($act, 1_000);
        $action = app(CreateInvoice::class);
        $lines = [['act_id' => $act->id, 'quantity' => 1]];

        $this->assertViolation("n'est pas actif", fn () => $action->handle(null, 'A', $lines, null, $this->makeUser(), ['insurer_id' => $this->insurer(80, false)->id, 'rate' => 80]));
        $this->assertViolation('entre 1 et 100', fn () => $action->handle(null, 'A', $lines, null, $this->makeUser(), ['insurer_id' => $this->insurer()->id, 'rate' => 0]));
        $this->assertSame(0, Invoice::count());
    }

    public function test_the_patient_pays_only_his_share(): void
    {
        $invoice = $this->invoice(10_000, $this->insurer(), 80);
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $this->assertViolation('ne doit plus que', fn () => app(RecordPayment::class)->handle($session, $this->cashMethod(), 10_000, $cashier, ['invoice_id' => $invoice->id]));

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 2_000, $cashier, ['invoice_id' => $invoice->id]);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(8_000, $invoice->insurerOutstanding());
    }

    public function test_settlements_move_the_claim_to_partial_then_settled(): void
    {
        $invoice = $this->invoice(10_000, $this->insurer(), 80);
        $settle = app(RecordInsuranceSettlement::class);

        $first = $settle->handle($invoice, 5_000, 'VIR-1', '2026-09-20', $this->makeUser());
        $this->assertMatchesRegularExpression('/^REG-\d{4}-\d{6}$/', $first->number);
        $invoice->refresh();
        $this->assertSame(Invoice::CLAIM_PARTIAL, $invoice->claim_status);
        $this->assertSame(3_000, $invoice->insurerOutstanding());

        $this->assertViolation('ne doit plus que', fn () => $settle->handle($invoice, 4_000, null, null, $this->makeUser()));

        $settle->handle($invoice, 3_000, null, null, $this->makeUser());
        $invoice->refresh();
        $this->assertSame(Invoice::CLAIM_SETTLED, $invoice->claim_status);
        $this->assertSame(0, $invoice->insurerOutstanding());
        $this->assertSame(2, AuditLog::where('event', 'insurance_settlement_recorded')->count());
    }

    public function test_a_rejection_moves_the_amount_to_the_patient(): void
    {
        $invoice = $this->invoice(10_000, $this->insurer(), 80);
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        // Le patient a réglé sa part : sa facture est payée…
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 2_000, $cashier, ['invoice_id' => $invoice->id]);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);

        // …jusqu'à ce que l'assureur rejette une partie.
        app(RecordInsuranceRejection::class)->handle($invoice, 3_000, 'Acte non couvert', $this->makeUser());
        $invoice->refresh();

        $this->assertSame(3_000, $invoice->insurer_rejected);
        $this->assertSame(5_000, $invoice->insurerOutstanding());
        $this->assertSame(3_000, $invoice->balance());
        $this->assertSame(Invoice::STATUS_PARTIAL, $invoice->status);
        $this->assertSame(Invoice::CLAIM_PARTIAL, $invoice->claim_status);

        // Tout rejeté : la créance est « Rejetée ».
        app(RecordInsuranceRejection::class)->handle($invoice, 5_000, 'Hors contrat', $this->makeUser());
        $this->assertSame(Invoice::CLAIM_REJECTED, $invoice->fresh()->claim_status);

        $this->assertViolation('doit avoir un motif', fn () => app(RecordInsuranceRejection::class)->handle($invoice, 1, ' ', $this->makeUser()));
    }

    public function test_settlements_and_rejections_need_an_insured_open_invoice(): void
    {
        $plain = $this->invoice(1_000);
        $this->assertViolation('aucun assureur', fn () => app(RecordInsuranceSettlement::class)->handle($plain, 100, null, null, $this->makeUser()));

        $covered = $this->invoice(1_000, $this->insurer(), 50);
        app(CancelInvoice::class)->handle($covered, 'Erreur', $this->makeUser());
        $this->assertViolation('Annulée', fn () => app(RecordInsuranceRejection::class)->handle($covered, 100, 'X', $this->makeUser()));
    }

    public function test_an_invoice_settled_by_the_insurer_cannot_be_cancelled(): void
    {
        $invoice = $this->invoice(10_000, $this->insurer(), 80);
        app(RecordInsuranceSettlement::class)->handle($invoice, 1_000, null, null, $this->makeUser());

        $this->assertViolation("réglée en partie par l'assureur", fn () => app(CancelInvoice::class)->handle($invoice, 'Erreur', $this->makeUser()));
    }
}
