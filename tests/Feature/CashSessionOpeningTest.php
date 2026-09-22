<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\OpenCashSession;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

class CashSessionOpeningTest extends TestCase
{
    use CashFixtures;

    public function test_opening_records_cashier_register_and_float(): void
    {
        $cashier = $this->makeUser(['name' => 'Salif Konaté']);
        $register = $this->makeRegister('CAISSE-TICKET');

        $session = app(OpenCashSession::class)->handle($register, $cashier, 25_000);

        $this->assertSame('SES-'.now()->year.'-000001', $session->number);
        $this->assertTrue($session->isOpen());
        $this->assertSame(25_000, $session->opening_float);
        $this->assertSame((string) $cashier->id, $session->cashier_id);
        $this->assertSame('Salif Konaté', $session->cashier_name);
        $this->assertSame($register->id, $session->cash_register_id);
        $this->assertNotNull($session->opened_at);
    }

    public function test_opening_is_audited(): void
    {
        $cashier = $this->makeUser();

        $session = $this->openSession($cashier, 5_000);

        $log = AuditLog::where('event', 'session_opened')->sole();

        $this->assertSame((string) $session->id, $log->subject_id);
        $this->assertSame((string) $cashier->id, $log->user_id);
        $this->assertSame(5_000, $log->new_values['opening_float']);
    }

    public function test_a_negative_float_is_refused(): void
    {
        $this->assertViolation('fonds initial', fn () => $this->openSession($this->makeUser(), -1));
    }

    public function test_an_inactive_register_cannot_be_opened(): void
    {
        $register = $this->makeRegister('ANCIENNE', false);

        $this->assertViolation('désactivée', fn () => $this->openSession($this->makeUser(), 0, $register));
    }

    public function test_only_one_open_session_per_register(): void
    {
        $register = $this->makeRegister();
        $this->openSession($this->makeUser(), 0, $register);

        $this->assertViolation('sur cette caisse', fn () => $this->openSession($this->makeUser(), 0, $register));
    }

    public function test_only_one_open_session_per_cashier_by_default(): void
    {
        $cashier = $this->makeUser();
        $this->openSession($cashier, 0, $this->makeRegister('A'));

        $this->assertViolation('tient déjà un tiroir ouvert', fn () => $this->openSession($cashier, 0, $this->makeRegister('B')));
    }

    public function test_a_new_session_can_open_once_the_previous_one_is_closed(): void
    {
        $cashier = $this->makeUser();
        $register = $this->makeRegister();

        $first = $this->openSession($cashier, 1_000, $register);
        app(CloseCashSession::class)->handle($first, 1_000, $cashier);

        $second = $this->openSession($cashier, 0, $register);

        $this->assertSame('SES-'.now()->year.'-000002', $second->number);
    }
}
