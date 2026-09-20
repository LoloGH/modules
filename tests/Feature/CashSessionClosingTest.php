<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CancelCashMovement;
use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\TestUser;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Le cœur de la réclamation : compter la caisse et voir l'écart.
 */
class CashSessionClosingTest extends TestCase
{
    use CashFixtures;

    /**
     * Fonds 10 000 ; espèces encaissées 20 000 + 5 000 (et 7 000 annulés) ;
     * Mobile Money 30 000 ; espèces décaissées 4 000.
     * Théorique du tiroir : 10 000 + 25 000 - 4 000 = 31 000.
     *
     * @return array{0: TestUser, 1: CashSession}
     */
    private function busySession(): array
    {
        $cashier = $this->makeUser();
        $accountant = $this->makeUser();
        $session = $this->openSession($cashier, 10_000);

        $record = app(RecordPayment::class);
        $record->handle($session, $this->cashMethod(), 20_000, $cashier);
        $record->handle($session, $this->cashMethod(), 5_000, $cashier);
        $record->handle($session, $this->momoMethod(), 30_000, $cashier, ['reference' => 'MM-1']);

        $mistake = $record->handle($session, $this->cashMethod(), 7_000, $cashier);
        app(CancelCashMovement::class)->payment($mistake, 'Saisie en double', $accountant);

        app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 4_000, 'Achat de gants', $cashier);

        return [$cashier, $session];
    }

    public function test_the_expected_cash_counts_only_cash_and_ignores_cancelled_payments(): void
    {
        [$cashier, $session] = $this->busySession();

        $closed = app(CloseCashSession::class)->handle($session, 31_000, $cashier);

        $this->assertSame(31_000, $closed->expected_cash);
        $this->assertSame(31_000, $closed->counted_cash);
        $this->assertSame(0, $closed->variance);
        $this->assertNull($closed->variance_reason);
        $this->assertTrue($closed->isClosed());
        $this->assertNotNull($closed->closed_at);
    }

    public function test_the_totals_by_method_are_kept_on_the_session(): void
    {
        [$cashier, $session] = $this->busySession();

        $closed = app(CloseCashSession::class)->handle($session, 31_000, $cashier);
        $totals = CashSession::findOrFail($closed->id)->totals;

        $this->assertSame(25_000, $totals['cash_in']);
        $this->assertSame(4_000, $totals['cash_out']);
        $this->assertSame(3, $totals['payments_count']);
        $this->assertSame(1, $totals['disbursements_count']);
        $this->assertSame(25_000, $totals['by_method']['especes']['in']);
        $this->assertSame(4_000, $totals['by_method']['especes']['out']);
        $this->assertSame(30_000, $totals['by_method']['mobile_money']['in']);
    }

    public function test_a_shortage_must_be_justified(): void
    {
        [$cashier, $session] = $this->busySession();

        $this->assertViolation('justifié', fn () => app(CloseCashSession::class)->handle($session, 30_000, $cashier));
        $this->assertViolation('justifié', fn () => app(CloseCashSession::class)->handle($session, 30_000, $cashier, '   '));

        // Rien n'a bougé : la session est toujours ouverte.
        $this->assertTrue(CashSession::findOrFail($session->id)->isOpen());

        $closed = app(CloseCashSession::class)->handle($session, 30_000, $cashier, 'Monnaie rendue en trop');

        $this->assertSame(-1_000, $closed->variance);
        $this->assertSame('Monnaie rendue en trop', $closed->variance_reason);
    }

    public function test_a_surplus_must_be_justified_too(): void
    {
        [$cashier, $session] = $this->busySession();

        $this->assertViolation('justifié', fn () => app(CloseCashSession::class)->handle($session, 31_500, $cashier));

        $closed = app(CloseCashSession::class)->handle($session, 31_500, $cashier, 'Pourboire laissé au guichet');

        $this->assertSame(500, $closed->variance);
    }

    public function test_a_reason_given_without_variance_is_not_kept(): void
    {
        [$cashier, $session] = $this->busySession();

        $closed = app(CloseCashSession::class)->handle($session, 31_000, $cashier, 'Pas nécessaire');

        $this->assertNull($closed->variance_reason);
    }

    public function test_a_negative_count_is_refused(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier);

        $this->assertViolation('négatif', fn () => app(CloseCashSession::class)->handle($session, -1, $cashier));
    }

    public function test_only_the_owner_can_close(): void
    {
        [$cashier, $session] = $this->busySession();

        $this->assertViolation('autre caissier', fn () => app(CloseCashSession::class)->handle($session, 31_000, $this->makeUser()));
    }

    public function test_a_session_cannot_be_closed_twice(): void
    {
        [$cashier, $session] = $this->busySession();
        app(CloseCashSession::class)->handle($session, 31_000, $cashier);

        $this->assertViolation("n'est pas ouverte", fn () => app(CloseCashSession::class)->handle($session, 31_000, $cashier));
    }

    public function test_closing_is_audited_with_the_variance(): void
    {
        [$cashier, $session] = $this->busySession();

        app(CloseCashSession::class)->handle($session, 30_000, $cashier, 'Erreur de rendu');

        $log = AuditLog::where('event', 'session_closed')->sole();

        $this->assertSame(31_000, $log->new_values['expected_cash']);
        $this->assertSame(30_000, $log->new_values['counted_cash']);
        $this->assertSame(-1_000, $log->new_values['variance']);
        $this->assertSame('Erreur de rendu', $log->new_values['variance_reason']);
    }
}
