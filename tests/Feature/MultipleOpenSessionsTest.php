<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Plusieurs tiroirs pour un même caissier : autorisé si l'établissement le
 * règle ainsi, jamais deux personnes sur la même caisse.
 */
class MultipleOpenSessionsTest extends TestCase
{
    use CashFixtures;

    private function setEstablishmentLimit(int $limit): void
    {
        config()->set('finance.cash.max_open_sessions_per_cashier', $limit);
    }

    public function test_the_default_limit_is_one(): void
    {
        $this->assertSame(1, CashierSetting::defaultLimit());
        $this->assertSame(1, CashierSetting::limitFor('peu-importe'));
    }

    public function test_with_a_limit_of_two_a_cashier_holds_two_registers(): void
    {
        $this->setEstablishmentLimit(2);

        $cashier = $this->makeUser();

        $first = $this->openSession($cashier, 0, $this->makeRegister('A'));
        $second = $this->openSession($cashier, 0, $this->makeRegister('B'));

        $this->assertTrue($first->isOpen());
        $this->assertTrue($second->isOpen());
        $this->assertSame(2, CashSession::query()->open()->where('cashier_id', (string) $cashier->id)->count());
    }

    public function test_the_third_one_is_refused_when_the_limit_is_two(): void
    {
        $this->setEstablishmentLimit(2);

        $cashier = $this->makeUser();
        $this->openSession($cashier, 0, $this->makeRegister('A'));
        $this->openSession($cashier, 0, $this->makeRegister('B'));

        $this->assertViolation('limite atteinte', fn () => $this->openSession($cashier, 0, $this->makeRegister('C')));

        $this->assertSame(2, CashSession::count());
    }

    public function test_the_same_register_is_never_opened_twice_by_its_own_cashier(): void
    {
        $this->setEstablishmentLimit(3);

        $cashier = $this->makeUser();
        $register = $this->makeRegister('A');

        $this->openSession($cashier, 0, $register);

        // Message distinct de celui qu'obtient un collègue : c'est sa caisse.
        $this->assertViolation('Vous tenez déjà la caisse', fn () => $this->openSession($cashier, 0, $register));

        $this->assertSame(1, CashSession::count());
    }

    public function test_two_cashiers_never_share_a_register_whatever_the_limit(): void
    {
        $this->setEstablishmentLimit(5);

        $register = $this->makeRegister('A');
        $this->openSession($this->makeUser(), 0, $register);

        $this->assertViolation('sur cette caisse', fn () => $this->openSession($this->makeUser(), 0, $register));
    }

    public function test_a_personal_override_is_more_permissive_than_the_default(): void
    {
        $this->setEstablishmentLimit(1);

        $cashier = $this->makeUser();
        CashierSetting::create(['cashier_id' => (string) $cashier->id, 'max_open_sessions' => 3]);

        $this->openSession($cashier, 0, $this->makeRegister('A'));
        $this->openSession($cashier, 0, $this->makeRegister('B'));
        $this->openSession($cashier, 0, $this->makeRegister('C'));

        $this->assertSame(3, CashSession::count());
        $this->assertViolation('limite atteinte', fn () => $this->openSession($cashier, 0, $this->makeRegister('D')));
    }

    public function test_a_personal_override_is_also_more_restrictive_than_the_default(): void
    {
        $this->setEstablishmentLimit(4);

        $strict = $this->makeUser();
        CashierSetting::create(['cashier_id' => (string) $strict->id, 'max_open_sessions' => 1]);

        $this->openSession($strict, 0, $this->makeRegister('A'));

        $this->assertViolation('tient déjà un tiroir ouvert', fn () => $this->openSession($strict, 0, $this->makeRegister('B')));

        // Son collègue, lui, reste au défaut de l'établissement.
        $other = $this->makeUser();
        $this->openSession($other, 0, $this->makeRegister('C'));
        $this->openSession($other, 0, $this->makeRegister('D'));

        $this->assertSame(3, CashSession::count());
    }

    public function test_an_override_left_null_falls_back_to_the_default(): void
    {
        $this->setEstablishmentLimit(2);

        $cashier = $this->makeUser();
        CashierSetting::create(['cashier_id' => (string) $cashier->id, 'max_open_sessions' => null]);

        $this->assertSame(2, CashierSetting::limitFor((string) $cashier->id));
    }

    public function test_an_absurd_configured_default_never_locks_the_cash_desk(): void
    {
        // Une faute de frappe dans la configuration ne doit pas empêcher tout
        // le monde d'ouvrir sa caisse : on retombe sur 1.
        foreach ([0, -3, 'beaucoup'] as $absurd) {
            config()->set('finance.cash.max_open_sessions_per_cashier', $absurd);

            $this->assertSame(1, CashierSetting::defaultLimit(), var_export($absurd, true));
        }
    }

    public function test_closing_one_session_frees_a_slot(): void
    {
        $this->setEstablishmentLimit(1);

        $cashier = $this->makeUser();
        $first = $this->openSession($cashier, 0, $this->makeRegister('A'));

        app(CloseCashSession::class)->handle($first, 0, $cashier);

        $second = $this->openSession($cashier, 0, $this->makeRegister('B'));

        $this->assertTrue($second->isOpen());
    }

    public function test_the_limit_follows_the_cashier_not_the_register(): void
    {
        $this->setEstablishmentLimit(2);

        $generous = $this->makeUser();
        CashierSetting::create(['cashier_id' => (string) $generous->id, 'max_open_sessions' => 1]);

        $registerA = $this->makeRegister('A');
        $registerB = $this->makeRegister('B');

        $this->openSession($generous, 0, $registerA);
        $this->assertViolation('tient déjà un tiroir ouvert', fn () => $this->openSession($generous, 0, $registerB));

        // La caisse B reste libre pour quelqu'un d'autre.
        $this->assertTrue($this->openSession($this->makeUser(), 0, $registerB)->isOpen());
    }

    // ------------------------------------------- Affectation aux caisses

    public function test_without_any_assignment_every_register_is_allowed(): void
    {
        $cashier = $this->makeUser();
        $register = $this->makeRegister('A');

        $this->assertSame([], CashierRegister::assignedIdsFor((string) $cashier->id));
        $this->assertTrue(CashierRegister::allows((string) $cashier->id, $register->id));
        $this->assertTrue($this->openSession($cashier, 0, $register)->isOpen());
    }

    public function test_an_assigned_cashier_opens_only_his_registers(): void
    {
        $this->setEstablishmentLimit(3);

        $cashier = $this->makeUser();
        $mine = $this->makeRegister('A');
        $notMine = $this->makeRegister('B');

        CashierRegister::create(['cashier_id' => (string) $cashier->id, 'cash_register_id' => $mine->id]);

        $this->assertTrue($this->openSession($cashier, 0, $mine)->isOpen());

        $this->assertViolation('pas affecté à la caisse', fn () => $this->openSession($cashier, 0, $notMine));

        $this->assertSame(1, CashSession::count());
    }

    public function test_an_assignment_restricts_only_the_cashier_it_names(): void
    {
        $restricted = $this->makeUser();
        $free = $this->makeUser();

        $mine = $this->makeRegister('A');
        $other = $this->makeRegister('B');

        CashierRegister::create(['cashier_id' => (string) $restricted->id, 'cash_register_id' => $mine->id]);

        // Son collègue n'est affecté à rien : toutes les caisses lui restent ouvertes.
        $this->assertTrue($this->openSession($free, 0, $other)->isOpen());
        $this->assertTrue(CashierRegister::allows((string) $free->id, $mine->id));
    }

    public function test_an_assignment_never_bypasses_the_one_cashier_per_register_rule(): void
    {
        $register = $this->makeRegister('A');

        $first = $this->makeUser();
        $second = $this->makeUser();

        // Les deux sont affectés à la même caisse : cela n'autorise pas
        // pour autant deux personnes sur le même tiroir.
        foreach ([$first, $second] as $cashier) {
            CashierRegister::create(['cashier_id' => (string) $cashier->id, 'cash_register_id' => $register->id]);
        }

        $this->openSession($first, 0, $register);

        $this->assertViolation('sur cette caisse', fn () => $this->openSession($second, 0, $register));
    }

    public function test_an_existing_session_survives_losing_the_assignment(): void
    {
        $cashier = $this->makeUser();
        $register = $this->makeRegister('A');

        $session = $this->openSession($cashier, 0, $register);

        // L'affectation se contrôle à l'ouverture : une session en cours
        // n'est pas interrompue parce qu'un réglage a changé.
        CashierRegister::create(['cashier_id' => (string) $cashier->id, 'cash_register_id' => $this->makeRegister('B')->id]);

        $this->assertTrue($session->fresh()->isOpen());
        $this->assertFalse(CashierRegister::allows((string) $cashier->id, $register->id));
    }
}
