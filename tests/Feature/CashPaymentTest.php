<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

class CashPaymentTest extends TestCase
{
    use CashFixtures;

    public function test_a_payment_is_recorded_in_the_open_session(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 15_000, $cashier, [
            'patient_id' => 42,
            'patient_name' => '  Awa Traoré ',
            'description' => 'Consultation externe',
        ]);

        $this->assertSame('PAI-'.now()->year.'-000001', $payment->number);
        $this->assertSame(15_000, $payment->amount);
        $this->assertSame(Payment::STATUS_VALID, $payment->status);
        $this->assertSame('42', $payment->patient_id);
        $this->assertSame('Awa Traoré', $payment->patient_name);
        $this->assertSame($session->id, $payment->cash_session_id);
    }

    public function test_a_payment_is_audited(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 3_000, $cashier);

        $log = AuditLog::where('event', 'payment_recorded')->sole();

        $this->assertSame((string) $payment->id, $log->subject_id);
        $this->assertSame(3_000, $log->new_values['amount']);
        $this->assertStringContainsString('3 000 FCFA', (string) $log->description);
    }

    public function test_a_zero_or_negative_amount_is_refused(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $this->assertViolation('supérieur à zéro', fn () => app(RecordPayment::class)->handle($session, $this->cashMethod(), 0, $cashier));
        $this->assertViolation('supérieur à zéro', fn () => app(RecordPayment::class)->handle($session, $this->cashMethod(), -500, $cashier));
        $this->assertSame(0, Payment::count());
    }

    public function test_a_closed_session_refuses_payments(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 0);
        app(CloseCashSession::class)->handle($session, 0, $cashier);

        $this->assertViolation("n'est pas ouverte", fn () => app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $cashier));
    }

    public function test_another_cashier_cannot_use_the_session(): void
    {
        $owner = $this->makeUser();
        $session = $this->openSession($owner);

        $this->assertViolation('autre caissier', fn () => app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $this->makeUser()));
    }

    public function test_an_inactive_method_is_refused(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);
        $inactive = $this->makeMethod('ancien', 'cash', false, false);

        $this->assertViolation('désactivé', fn () => app(RecordPayment::class)->handle($session, $inactive, 1_000, $cashier));
    }

    public function test_a_reference_is_required_when_the_method_demands_it(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);
        $momo = $this->momoMethod();

        $this->assertViolation('exige une référence', fn () => app(RecordPayment::class)->handle($session, $momo, 5_000, $cashier));
        $this->assertViolation('exige une référence', fn () => app(RecordPayment::class)->handle($session, $momo, 5_000, $cashier, ['reference' => '   ']));

        $payment = app(RecordPayment::class)->handle($session, $momo, 5_000, $cashier, ['reference' => 'MM-20260920-77']);

        $this->assertSame('MM-20260920-77', $payment->reference);
    }

    public function test_a_refused_payment_leaves_no_trace_and_burns_no_number(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $this->assertViolation('exige une référence', fn () => app(RecordPayment::class)->handle($session, $this->momoMethod(), 5_000, $cashier));

        $this->assertSame(0, Payment::count());
        $this->assertSame(0, AuditLog::where('event', 'payment_recorded')->count());

        $next = app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $cashier);

        $this->assertSame('PAI-'.now()->year.'-000001', $next->number);
    }
}
