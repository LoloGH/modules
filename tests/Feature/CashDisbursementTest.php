<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

class CashDisbursementTest extends TestCase
{
    use CashFixtures;

    public function test_a_cash_disbursement_lowers_the_expected_cash(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 10_000);

        $disbursement = app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 4_000, 'Achat de gants', $cashier, ['beneficiary' => 'Pharmacie centrale']);

        $this->assertSame('DEC-'.now()->year.'-000001', $disbursement->number);
        $this->assertSame('Pharmacie centrale', $disbursement->beneficiary);
        $this->assertSame(6_000, app(CashSessionCalculator::class)->totals($session)['expected_cash']);
        $this->assertSame(1, AuditLog::where('event', 'disbursement_recorded')->count());
    }

    public function test_a_reason_is_mandatory(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $this->assertViolation('motif', fn () => app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 1_000, '   ', $cashier));
        $this->assertSame(0, Disbursement::count());
    }

    public function test_cash_cannot_leave_more_than_the_drawer_holds(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 10_000);

        $this->assertViolation('impossible', fn () => app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 10_001, 'Trop', $cashier));

        // Pile ce qu'il y a : accepté.
        app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 10_000, 'Tout le fonds', $cashier);

        $this->assertSame(0, app(CashSessionCalculator::class)->totals($session)['expected_cash']);
    }

    public function test_cash_received_can_be_paid_out_again(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 0);

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 8_000, $cashier);

        app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 8_000, 'Remise en banque', $cashier);

        $this->assertSame(0, app(CashSessionCalculator::class)->totals($session)['expected_cash']);
    }

    public function test_a_non_cash_disbursement_does_not_touch_the_drawer(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 10_000);

        app(RecordDisbursement::class)->handle($session, $this->momoMethod(), 50_000, 'Paiement fournisseur', $cashier, ['reference' => 'MM-1']);

        $totals = app(CashSessionCalculator::class)->totals($session);

        $this->assertSame(10_000, $totals['expected_cash']);
        $this->assertSame(50_000, $totals['by_method']['mobile_money']['out']);
    }

    public function test_patient_account_and_insurance_cannot_pay_money_out(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        foreach ([PaymentMethod::KIND_PATIENT_ACCOUNT, PaymentMethod::KIND_INSURANCE] as $kind) {
            $method = $this->makeMethod($kind, $kind);

            $this->assertViolation('ne peut pas servir', fn () => app(RecordDisbursement::class)->handle($session, $method, 1_000, 'Test', $cashier));
        }
    }

    public function test_another_cashier_cannot_record_in_the_session(): void
    {
        $session = $this->openSession($this->makeUser());

        $this->assertViolation('autre caissier', fn () => app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 100, 'Test', $this->makeUser()));
    }
}
