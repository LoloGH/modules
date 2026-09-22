<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Illuminate\Database\QueryException;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Le motif d'un encaissement : l'acte du catalogue. C'est lui qui permettra
 * de dire ce que rapporte la consultation, le laboratoire ou l'imagerie.
 */
class PaymentActTest extends TestCase
{
    use CashFixtures;
    use CatalogFixtures;

    private function record(int $amount, array $details = []): Payment
    {
        $cashier = $this->makeUser();

        return app(RecordPayment::class)->handle(
            $this->openSession($cashier, 0),
            $this->cashMethod(),
            $amount,
            $cashier,
            $details,
        );
    }

    public function test_a_payment_carries_the_act_it_pays_for(): void
    {
        $act = $this->makeAct('CONS-GEN', $this->makeCenter('CONSULTATION'));

        $payment = $this->record(2_000, ['act_id' => $act->id]);

        $this->assertSame($act->id, $payment->act_id);
        $this->assertTrue($payment->act->is($act));
        $this->assertSame('CONSULTATION', $payment->act->center->code);
    }

    public function test_the_act_name_becomes_the_label_when_none_is_typed(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->assertSame($act->name, $this->record(2_000, ['act_id' => $act->id])->description);
    }

    public function test_a_typed_label_wins_over_the_act_name(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $payment = $this->record(2_000, ['act_id' => $act->id, 'description' => 'Consultation de nuit']);

        $this->assertSame('Consultation de nuit', $payment->description);
        $this->assertSame($act->id, $payment->act_id);
    }

    public function test_a_payment_without_an_act_is_still_allowed(): void
    {
        $payment = $this->record(5_000, ['description' => 'Avance sur hospitalisation']);

        $this->assertNull($payment->act_id);
        $this->assertSame('Avance sur hospitalisation', $payment->description);
    }

    public function test_a_deactivated_act_can_no_longer_be_charged(): void
    {
        $act = $this->makeAct('CONS-GEN');
        $act->update(['is_active' => false]);

        $this->assertViolation('est désactivé', fn () => $this->record(2_000, ['act_id' => $act->id]));

        $this->assertSame(0, Payment::count());
    }

    public function test_an_unknown_act_is_refused(): void
    {
        $this->assertViolation('n\'existe pas', fn () => $this->record(2_000, ['act_id' => 4_242]));

        $this->assertSame(0, Payment::count());
    }

    public function test_the_act_is_written_to_the_audit_trail(): void
    {
        $act = $this->makeAct('LAB-GE', $this->makeCenter('LABORATOIRE'));

        $this->record(1_500, ['act_id' => $act->id]);

        $log = AuditLog::where('event', 'payment_recorded')->sole();

        $this->assertSame('LAB-GE', $log->new_values['act']);
        $this->assertStringContainsString('acte LAB-GE', (string) $log->description);
    }

    public function test_an_act_that_has_been_charged_cannot_be_deleted(): void
    {
        $act = $this->makeAct('CONS-GEN');

        $this->record(2_000, ['act_id' => $act->id]);

        // `restrictOnDelete` : on ne détruit pas ce qui explique un encaissement.
        $this->expectException(QueryException::class);

        $act->delete();
    }

    public function test_the_amount_stays_the_cashier_s_decision(): void
    {
        $act = $this->makeAct('CONS-GEN');
        $this->setTariff($act, 2_000);

        // Un acompte sur un acte tarifé 2 000 reste possible : le tarif
        // renseigne le caissier, il ne le contraint pas.
        $payment = $this->record(500, ['act_id' => $act->id]);

        $this->assertSame(500, $payment->amount);
        $this->assertSame(2_000, $act->activeTariff()->amount);
    }
}
