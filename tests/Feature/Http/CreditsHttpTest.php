<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CreateInvoice;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\Disbursement;
use Keneya\FinanceCaisse\Models\Discount;
use Keneya\FinanceCaisse\Models\Invoice;
use Keneya\FinanceCaisse\Models\PatientDeposit;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Models\Refund;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Services\PatientAccount;
use Keneya\FinanceCaisse\Support\Rbac;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Remises et remboursements : demandés par qui encaisse, tranchés par
 * quelqu'un d'autre, et payés à la caisse pour ce qui sort du tiroir.
 */
class CreditsHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    private const PATIENT = 'PAT-000123';

    private function caisse(TestUser $cashier): CashSession
    {
        return $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-SERVICES'));
    }

    private function act(int $price = 10_000): Act
    {
        $act = Act::create(['code' => 'CONS', 'name' => 'Consultation', 'is_active' => true]);
        $this->setTariff($act, $price);

        return $act;
    }

    private function invoice(int $price = 10_000): Invoice
    {
        return app(CreateInvoice::class)->handle(
            self::PATIENT,
            'Aminata Traoré',
            [['act_id' => $this->act($price)->id, 'quantity' => 1]],
            null,
            $this->makeUser(),
        );
    }

    public function test_a_discount_lowers_what_the_patient_owes_once_approved(): void
    {
        $invoice = $this->invoice();
        $cashier = $this->cashier();

        $this->actingAs($cashier)
            ->post(route('finance.credits.discounts.store'), [
                'invoice_id' => $invoice->id,
                'amount' => '4 000',
                'reason' => 'Patient indigent',
            ])
            ->assertRedirect(route('finance.credits.index'));

        $discount = Discount::sole();
        $this->assertStringStartsWith('AVO-', $discount->number);
        $this->assertSame(Discount::STATUS_REQUESTED, $discount->status);

        // Tant qu'elle n'est pas approuvée, elle ne change rien.
        $this->assertSame(10_000, $invoice->refresh()->balance());

        // Celui qui a demandé n'approuve pas.
        $this->actingAs($cashier)->from('/finance/remises-et-remboursements')
            ->post(route('finance.credits.discounts.decide', $discount), ['decision' => 'approve'])
            ->assertForbidden();

        $this->actingAs($this->accountant())
            ->post(route('finance.credits.discounts.decide', $discount), ['decision' => 'approve'])
            ->assertRedirect(route('finance.credits.index'));

        $invoice->refresh();
        $this->assertSame(4_000, (int) $invoice->discount);
        $this->assertSame(6_000, $invoice->balance());
        $this->assertSame(1, AuditLog::where('event', 'discount_approved')->count());

        // Le reste dû payé, la facture est soldée.
        $session = $this->caisse($cashier);
        app(RecordPayment::class)->handle($session, $this->cashMethod(), 6_000, $cashier, ['invoice_id' => $invoice->id]);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);
    }

    public function test_a_discount_never_exceeds_what_is_still_owed(): void
    {
        $invoice = $this->invoice();
        $cashier = $this->cashier();

        $this->actingAs($cashier)->from('/finance/remises-et-remboursements')
            ->post(route('finance.credits.discounts.store'), [
                'invoice_id' => $invoice->id,
                'amount' => '12 000',
                'reason' => 'Trop généreux',
            ])
            ->assertSessionHas('finance_error');

        $this->assertSame(0, Discount::count());

        // Deux demandes ne réservent pas deux fois la même somme.
        $this->post(route('finance.credits.discounts.store'), ['invoice_id' => $invoice->id, 'amount' => '7 000', 'reason' => 'Un']);
        $this->from('/finance/remises-et-remboursements')
            ->post(route('finance.credits.discounts.store'), ['invoice_id' => $invoice->id, 'amount' => '7 000', 'reason' => 'Deux'])
            ->assertSessionHas('finance_error');

        $this->assertSame(1, Discount::count());
    }

    public function test_a_refused_discount_changes_nothing_and_keeps_its_reason(): void
    {
        $invoice = $this->invoice();

        $this->actingAs($this->cashier())
            ->post(route('finance.credits.discounts.store'), ['invoice_id' => $invoice->id, 'amount' => '2 000', 'reason' => 'Geste']);

        $discount = Discount::sole();

        // Un refus sans motif n'est pas un refus.
        $this->actingAs($this->accountant())->from('/finance/remises-et-remboursements')
            ->post(route('finance.credits.discounts.decide', $discount), ['decision' => 'refuse'])
            ->assertSessionHasErrors('reason');

        $this->post(route('finance.credits.discounts.decide', $discount), ['decision' => 'refuse', 'reason' => 'Sans justificatif'])
            ->assertRedirect(route('finance.credits.index'));

        $this->assertSame(Discount::STATUS_REFUSED, $discount->refresh()->status);
        $this->assertSame(0, (int) $invoice->refresh()->discount);
        $this->get('/finance/remises-et-remboursements')->assertSee('Sans justificatif');
    }

    public function test_a_refund_of_a_payment_is_approved_then_paid_out_of_the_drawer(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 8_000, $cashier, [
            'patient_id' => self::PATIENT,
            'patient_name' => 'Aminata Traoré',
            'description' => 'Consultation',
        ]);

        $this->actingAs($cashier)
            ->post(route('finance.credits.refunds.store'), [
                'source' => Refund::SOURCE_PAYMENT,
                'payment_id' => $payment->id,
                'amount' => '8 000',
                'reason' => 'Acte non réalisé',
            ])
            ->assertRedirect(route('finance.credits.index'));

        $refund = Refund::sole();
        $this->assertStringStartsWith('RBT-', $refund->number);
        $this->assertSame(self::PATIENT, $refund->patient_id);

        // Tant qu'il n'est pas approuvé, la caisse ne le propose pas.
        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $session))
            ->assertDontSee('Remboursements à payer');

        $this->actingAs($this->accountant())
            ->post(route('finance.credits.refunds.decide', $refund), ['decision' => 'approve'])
            ->assertRedirect(route('finance.credits.index'));

        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $session))
            ->assertSee('Remboursements à payer')
            ->assertSee($refund->number);

        // Payé : l'argent sort du tiroir par un décaissement ordinaire.
        $this->post(route('finance.cash.refunds.pay', [$session, $refund]), [
            'payment_method_id' => $this->cashMethod()->id,
        ])->assertRedirect(route('finance.cash.sessions.show', $session));

        $refund->refresh();
        $this->assertSame(Refund::STATUS_PAID, $refund->status);
        $this->assertNotNull($refund->disbursement_id);

        $disbursement = Disbursement::sole();
        $this->assertSame('remboursement', $disbursement->category);
        $this->assertSame(8_000, (int) $disbursement->amount);

        // 10 000 de fonds + 8 000 encaissés - 8 000 rendus.
        $this->assertSame(10_000, app(CashSessionCalculator::class)->totals($session->refresh())['expected_cash']);
        $this->assertSame(1, AuditLog::where('event', 'refund_paid')->count());

        // On ne rembourse pas deux fois le même encaissement.
        $this->actingAs($cashier)->from('/finance/remises-et-remboursements')
            ->post(route('finance.credits.refunds.store'), [
                'source' => Refund::SOURCE_PAYMENT,
                'payment_id' => $payment->id,
                'amount' => '1 000',
                'reason' => 'Encore',
            ])
            ->assertSessionHas('finance_error');
    }

    public function test_the_balance_of_an_account_is_given_back_and_leaves_the_account(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        $this->actingAs($cashier)->post(route('finance.cash.deposits.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'amount' => '20 000',
            'patient_id' => self::PATIENT,
            'patient_name' => 'Aminata Traoré',
        ]);

        app(RecordPayment::class)->handle(
            $session->refresh(),
            $this->makeMethod('compte_patient', PaymentMethod::KIND_PATIENT_ACCOUNT),
            15_000,
            $cashier,
            ['patient_id' => self::PATIENT, 'description' => 'Consultation'],
        );

        // Le solde, et pas un franc de plus.
        $this->actingAs($cashier)->from('/finance/remises-et-remboursements')
            ->post(route('finance.credits.refunds.store'), [
                'source' => Refund::SOURCE_ACCOUNT,
                'patient_id' => self::PATIENT,
                'amount' => '6 000',
                'reason' => 'Solde rendu',
            ])
            ->assertSessionHas('finance_error');

        $this->post(route('finance.credits.refunds.store'), [
            'source' => Refund::SOURCE_ACCOUNT,
            'patient_id' => self::PATIENT,
            'amount' => '5 000',
            'reason' => 'Solde rendu à la sortie',
        ])->assertRedirect(route('finance.credits.index'));

        $refund = Refund::sole();

        // Demandé, il retient déjà sa place : rien d'autre ne s'engage.
        $this->assertSame(0, app(PatientAccount::class)->available(self::PATIENT));

        $this->actingAs($this->accountant())->post(route('finance.credits.refunds.decide', $refund), ['decision' => 'approve']);
        $this->actingAs($cashier)->post(route('finance.cash.refunds.pay', [$session->refresh(), $refund]), [
            'payment_method_id' => $this->cashMethod()->id,
        ])->assertRedirect();

        $this->assertSame(0, app(PatientAccount::class)->balance(self::PATIENT));
        $this->assertSame(1, PatientDeposit::count());

        // Le tiroir : 10 000 + 20 000 d'avance - 5 000 rendus.
        $this->assertSame(25_000, app(CashSessionCalculator::class)->totals($session->refresh())['expected_cash']);

        $this->actingAs($this->accountant())->get('/finance/comptes/'.self::PATIENT)
            ->assertSee('Remboursé')
            ->assertSee('5 000 FCFA');
    }

    public function test_a_fully_refunded_invoice_is_marked_refunded(): void
    {
        $invoice = $this->invoice();
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 10_000, $cashier, ['invoice_id' => $invoice->id]);
        $this->assertSame(Invoice::STATUS_PAID, $invoice->refresh()->status);

        $this->actingAs($cashier)->post(route('finance.credits.refunds.store'), [
            'source' => Refund::SOURCE_INVOICE,
            'invoice_id' => $invoice->id,
            'amount' => '10 000',
            'reason' => 'Hospitalisation annulée',
        ]);

        $refund = Refund::sole();
        $this->actingAs($this->accountant())->post(route('finance.credits.refunds.decide', $refund), ['decision' => 'approve']);
        $this->actingAs($cashier)->post(route('finance.cash.refunds.pay', [$session->refresh(), $refund]), [
            'payment_method_id' => $this->cashMethod()->id,
        ])->assertRedirect();

        $this->assertSame(Invoice::STATUS_REFUNDED, $invoice->refresh()->status);
        $this->assertSame(0, $invoice->balance());

        // Une facture close n'accepte plus de remise.
        $this->actingAs($cashier)->from('/finance/remises-et-remboursements')
            ->post(route('finance.credits.discounts.store'), ['invoice_id' => $invoice->id, 'amount' => '1 000', 'reason' => 'Trop tard'])
            ->assertSessionHas('finance_error');
    }

    public function test_the_screen_and_its_actions_follow_the_rights(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_DIRECTOR))
            ->get('/finance/remises-et-remboursements')->assertForbidden();

        // Le caissier demande, sans pouvoir trancher.
        $this->actingAs($this->cashier())->get('/finance/remises-et-remboursements')
            ->assertOk()
            ->assertSee('Demander une remise')
            ->assertSee('Demander un remboursement');

        // Le contrôle tranche, sans avoir à demander.
        $this->actingAs($this->accountant())->get('/finance/remises-et-remboursements')
            ->assertOk()
            ->assertDontSee('Demander une remise');

        $this->actingAs($this->accountant())->get('/finance')
            ->assertSee(route('finance.credits.index'), false);

        $this->assertSame(0, Payment::count());
    }
}
