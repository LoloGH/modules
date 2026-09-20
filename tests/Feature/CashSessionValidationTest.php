<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\ValidateCashSession;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Séparation des tâches : celui qui compte n'est pas celui qui valide.
 */
class CashSessionValidationTest extends TestCase
{
    use CashFixtures;

    private function closedSession(): array
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 5_000);
        $closed = app(CloseCashSession::class)->handle($session, 5_000, $cashier);

        return [$cashier, $closed];
    }

    public function test_another_user_validates_a_closed_session(): void
    {
        [, $session] = $this->closedSession();
        $accountant = $this->makeUser(['name' => 'Comptable HFD']);

        $validated = app(ValidateCashSession::class)->handle($session, $accountant, '  Conforme  ');

        $this->assertTrue($validated->isValidated());
        $this->assertSame((string) $accountant->id, $validated->validator_id);
        $this->assertSame('Comptable HFD', $validated->validator_name);
        $this->assertSame('Conforme', $validated->validation_note);
        $this->assertNotNull($validated->validated_at);
    }

    public function test_the_cashier_cannot_validate_their_own_closing(): void
    {
        [$cashier, $session] = $this->closedSession();

        $this->assertViolation('propre clôture', fn () => app(ValidateCashSession::class)->handle($session, $cashier));
        $this->assertTrue(CashSession::findOrFail($session->id)->isClosed());
    }

    public function test_an_open_session_cannot_be_validated(): void
    {
        $session = $this->openSession($this->makeUser());

        $this->assertViolation('clôturée', fn () => app(ValidateCashSession::class)->handle($session, $this->makeUser()));
    }

    public function test_a_session_cannot_be_validated_twice(): void
    {
        [, $session] = $this->closedSession();
        $accountant = $this->makeUser();
        app(ValidateCashSession::class)->handle($session, $accountant);

        $this->assertViolation('déjà validée', fn () => app(ValidateCashSession::class)->handle($session, $this->makeUser()));
    }

    public function test_validation_is_audited(): void
    {
        [, $session] = $this->closedSession();
        $accountant = $this->makeUser();

        app(ValidateCashSession::class)->handle($session, $accountant, 'RAS');

        $log = AuditLog::where('event', 'session_validated')->sole();

        $this->assertSame((string) $accountant->id, $log->user_id);
        $this->assertSame('validated', $log->new_values['status']);
    }

    public function test_a_validated_session_stays_locked_for_the_cashier(): void
    {
        [$cashier, $session] = $this->closedSession();
        app(ValidateCashSession::class)->handle($session, $this->makeUser());

        $this->assertViolation("n'est pas ouverte", fn () => app(CloseCashSession::class)->handle($session, 0, $cashier));
    }
}
