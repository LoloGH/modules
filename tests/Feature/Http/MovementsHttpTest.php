<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Payment;

class MovementsHttpTest extends HttpTestCase
{
    public function test_the_cashier_records_a_payment_from_the_session_page(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000);

        $this->actingAs($cashier)
            ->post(route('finance.cash.payments.store', $session), [
                'payment_method_id' => $this->cashMethod()->id,
                'amount' => '15 000',
                'patient_name' => 'Awa Traoré',
                'description' => 'Consultation externe',
            ])
            ->assertRedirect(route('finance.cash.sessions.show', $session))
            ->assertSessionHas('finance_status');

        $payment = Payment::query()->sole();

        $this->assertSame(15_000, $payment->amount);
        $this->assertSame('Awa Traoré', $payment->patient_name);

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee($payment->number)
            ->assertSee('Awa Traoré')
            ->assertSee('25 000 FCFA'); // théorique : 10 000 + 15 000
    }

    public function test_an_invalid_amount_is_a_form_error(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $back = route('finance.cash.sessions.show', $session);

        $this->from($back)->actingAs($cashier)
            ->post(route('finance.cash.payments.store', $session), ['payment_method_id' => $this->cashMethod()->id, 'amount' => '0'])
            ->assertRedirect($back)
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Payment::count());
    }

    public function test_a_rule_violation_comes_back_as_a_message_with_the_form_kept(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);
        $back = route('finance.cash.sessions.show', $session);

        // Mobile Money exige une référence : règle du domaine, pas de la validation.
        $this->from($back)->actingAs($cashier)
            ->post(route('finance.cash.payments.store', $session), ['payment_method_id' => $this->momoMethod()->id, 'amount' => '5000'])
            ->assertRedirect($back)
            ->assertSessionHas('finance_error')
            ->assertSessionHasInput('amount', '5000');

        $this->assertSame(0, Payment::count());
    }

    public function test_the_cashier_records_a_disbursement_with_a_reason(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000);

        $this->actingAs($cashier)
            ->post(route('finance.cash.disbursements.store', $session), [
                'payment_method_id' => $this->cashMethod()->id,
                'amount' => '4000',
                'reason' => 'Achat de gants',
            ])
            ->assertRedirect(route('finance.cash.sessions.show', $session));

        $this->assertSame(1, Disbursement::count());

        $this->from(route('finance.cash.sessions.show', $session))
            ->post(route('finance.cash.disbursements.store', $session), [
                'payment_method_id' => $this->cashMethod()->id,
                'amount' => '1000',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(1, Disbursement::count());
    }

    public function test_another_cashier_cannot_see_or_use_the_session(): void
    {
        $owner = $this->cashier();
        $session = $this->openSession($owner);
        $intruder = $this->cashier();

        $this->actingAs($intruder)
            ->get(route('finance.cash.sessions.show', $session))
            ->assertForbidden();

        $this->from('/finance/caisse')->actingAs($intruder)
            ->post(route('finance.cash.payments.store', $session), ['payment_method_id' => $this->cashMethod()->id, 'amount' => '1000'])
            ->assertSessionHas('finance_error');

        $this->assertSame(0, Payment::count());
    }

    public function test_the_accountant_can_read_any_session(): void
    {
        $session = $this->openSession($this->cashier());

        $this->actingAs($this->accountant())
            ->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee($session->number);
    }

    public function test_the_cashier_cannot_cancel_but_the_accountant_can(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 0);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 6_000, $cashier);

        $this->actingAs($cashier)
            ->post(route('finance.cash.payments.cancel', $payment), ['reason' => 'Erreur'])
            ->assertForbidden();
        $this->assertFalse(Payment::findOrFail($payment->id)->isCancelled());

        $this->actingAs($this->accountant())
            ->post(route('finance.cash.payments.cancel', $payment), ['reason' => 'Saisie en double'])
            ->assertRedirect(route('finance.cash.sessions.show', $session));

        $this->assertTrue(Payment::findOrFail($payment->id)->isCancelled());

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee('Annulé par')
            ->assertSee('Saisie en double');
    }

    public function test_a_cancellation_needs_a_reason(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 0);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 6_000, $cashier);

        $this->from(route('finance.cash.sessions.show', $session))
            ->actingAs($this->accountant())
            ->post(route('finance.cash.payments.cancel', $payment), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertFalse(Payment::findOrFail($payment->id)->isCancelled());
    }

    public function test_the_accountant_can_cancel_a_disbursement(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000);
        $disbursement = app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 3_000, 'Achat', $cashier);

        $this->actingAs($cashier)
            ->post(route('finance.cash.disbursements.cancel', $disbursement), ['reason' => 'Erreur'])
            ->assertForbidden();

        $this->actingAs($this->accountant())
            ->post(route('finance.cash.disbursements.cancel', $disbursement), ['reason' => 'Achat annulé'])
            ->assertRedirect(route('finance.cash.sessions.show', $session));

        $this->assertTrue(Disbursement::findOrFail($disbursement->id)->isCancelled());
    }
}
