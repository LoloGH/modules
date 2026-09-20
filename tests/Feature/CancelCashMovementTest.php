<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * On n'efface jamais une opération de caisse : on l'annule, avec un motif.
 */
class CancelCashMovementTest extends TestCase
{
    use CashFixtures;

    public function test_a_cancelled_payment_stays_visible_but_leaves_the_totals(): void
    {
        $cashier = $this->makeUser();
        $accountant = $this->makeUser(['name' => 'Comptable HFD']);
        $session = $this->openSession($cashier, 0);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 9_000, $cashier);

        $cancelled = app(CancelCashMovement::class)->payment($payment, 'Patient parti sans soins', $accountant);

        $this->assertTrue($cancelled->isCancelled());
        $this->assertSame('Patient parti sans soins', $cancelled->cancellation_reason);
        $this->assertSame('Comptable HFD', $cancelled->cancelled_by_name);
        $this->assertNotNull($cancelled->cancelled_at);

        // Toujours en base, mais plus dans les totaux.
        $this->assertSame(1, Payment::count());
        $this->assertSame(0, app(CashSessionCalculator::class)->totals($session)['expected_cash']);
    }

    public function test_a_cancellation_needs_a_reason(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $cashier);

        $this->assertViolation('motif', fn () => app(CancelCashMovement::class)->payment($payment, '  ', $cashier));
        $this->assertFalse(Payment::findOrFail($payment->id)->isCancelled());
    }

    public function test_a_payment_cannot_be_cancelled_twice(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $cashier);
        app(CancelCashMovement::class)->payment($payment, 'Erreur', $cashier);

        $this->assertViolation('déjà annulé', fn () => app(CancelCashMovement::class)->payment($payment, 'Encore', $cashier));
        $this->assertSame(1, AuditLog::where('event', 'payment_cancelled')->count());
    }

    public function test_nothing_can_be_cancelled_once_the_session_is_closed(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 0);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 4_000, $cashier);
        app(CloseCashSession::class)->handle($session, 4_000, $cashier);

        $this->assertViolation('remboursement ou un ajustement', fn () => app(CancelCashMovement::class)->payment($payment, 'Trop tard', $cashier));
        $this->assertFalse(Payment::findOrFail($payment->id)->isCancelled());
    }

    public function test_cancelling_a_disbursement_puts_the_cash_back(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 10_000);
        $disbursement = app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 6_000, 'Achat', $cashier);

        $this->assertSame(4_000, app(CashSessionCalculator::class)->totals($session)['expected_cash']);

        $cancelled = app(CancelCashMovement::class)->disbursement($disbursement, 'Achat annulé', $cashier);

        $this->assertTrue($cancelled->isCancelled());
        $this->assertSame(10_000, app(CashSessionCalculator::class)->totals($session)['expected_cash']);
        $this->assertSame(1, Disbursement::count());
    }

    public function test_a_cancellation_is_audited_with_old_and_new_status(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 2_000, $cashier);

        app(CancelCashMovement::class)->payment($payment, 'Doublon', $cashier);

        $log = AuditLog::where('event', 'payment_cancelled')->sole();

        $this->assertSame('valid', $log->old_values['status']);
        $this->assertSame('cancelled', $log->new_values['status']);
        $this->assertSame('Doublon', $log->new_values['reason']);
    }
}
