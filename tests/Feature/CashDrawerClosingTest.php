<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\OpenCashSessions;
use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Actions\ValidateCashSession;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\TestUser;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Un tiroir commun se compte une seule fois.
 *
 * Des caisses ouvertes groupées partagent un tiroir : un fonds, un tas
 * d'espèces. Les clôturer une par une demandait au caissier de compter trois
 * fois le même tiroir, chaque fois comparé à un théorique partiel : la
 * première caisse portait le fonds, les deux autres partaient de zéro. La
 * question n'avait pas de réponse honnête, puisqu'on ne compte pas un tiers
 * de tiroir.
 *
 * Ce qui est éprouvé ici : un comptage pour tout le tiroir, les caisses qui
 * se ferment ensemble, et des totaux qui restent justes une fois additionnés.
 */
class CashDrawerClosingTest extends TestCase
{
    use CashFixtures;

    /**
     * Trois caisses, un tiroir, 15 000 de fonds.
     * Ticket encaisse 20 000, Services 5 000 et décaisse 4 000, Pharmacie rien.
     * Théorique du tiroir : 15 000 + 25 000 - 4 000 = 36 000.
     *
     * @return array{0: TestUser, 1: CashSession, 2: CashSession, 3: CashSession}
     */
    private function drawer(): array
    {
        $cashier = $this->makeUser();

        [$ticket, $services, $pharmacie] = app(OpenCashSessions::class)->grouped([
            $this->makeRegister('CAISSE-TICKET')->id,
            $this->makeRegister('CAISSE-SERVICES')->id,
            $this->makeRegister('CAISSE-PHARMACIE')->id,
        ], 15_000, $cashier);

        $record = app(RecordPayment::class);
        $record->handle($ticket, $this->cashMethod(), 20_000, $cashier);
        $record->handle($services, $this->cashMethod(), 5_000, $cashier);
        app(RecordDisbursement::class)->handle($services, $this->cashMethod(), 4_000, 'Achat de gants', $cashier);

        return [$cashier, $ticket->refresh(), $services->refresh(), $pharmacie->refresh()];
    }

    public function test_the_theoretical_amount_covers_the_whole_drawer(): void
    {
        [$cashier, $ticket] = $this->drawer();

        // 36 000 comptés, aucun écart : la preuve que le théorique opposé au
        // caissier est celui du tiroir entier, et non celui d'une caisse.
        $closed = app(CloseCashSession::class)->handle($ticket, 36_000, $cashier);

        $this->assertTrue($closed->isClosed());
        $this->assertSame(0, (int) CashSession::query()->sum('variance'));
        $this->assertSame(36_000, (int) CashSession::query()->sum('counted_cash'));
    }

    public function test_closing_one_register_closes_every_register_of_the_drawer(): void
    {
        [$cashier, $ticket, $services, $pharmacie] = $this->drawer();

        app(CloseCashSession::class)->handle($services, 36_000, $cashier);

        foreach ([$ticket, $services, $pharmacie] as $session) {
            $this->assertTrue($session->refresh()->isClosed());
            $this->assertNotNull($session->closed_at);
        }

        $this->assertSame(0, CashSession::query()->open()->count());
        $this->assertSame(0, CashSession::openDrawersFor((string) $cashier->id));
    }

    /**
     * Chaque caisse garde le théorique qu'elle a apporté : c'est ce qui permet
     * de savoir d'où vient l'argent une fois le tiroir fermé.
     */
    public function test_each_register_keeps_its_own_theoretical_share(): void
    {
        [$cashier, $ticket, $services, $pharmacie] = $this->drawer();

        app(CloseCashSession::class)->handle($ticket, 36_000, $cashier);

        $this->assertSame(35_000, (int) $ticket->refresh()->expected_cash);
        $this->assertSame(1_000, (int) $services->refresh()->expected_cash);
        $this->assertSame(0, (int) $pharmacie->refresh()->expected_cash);
    }

    /**
     * L'écart est celui du tiroir, et il ne se compte qu'une fois : porté par
     * la caisse qui porte le fonds. Sans cela, un manque de 1 000 se lirait
     * trois fois dans les totaux de l'établissement.
     */
    public function test_the_variance_belongs_to_the_drawer_and_is_counted_once(): void
    {
        [$cashier, $ticket, $services, $pharmacie] = $this->drawer();

        app(CloseCashSession::class)->handle($pharmacie, 35_000, $cashier, 'Un billet manquant');

        $this->assertSame(-1_000, (int) $ticket->refresh()->variance);
        $this->assertSame('Un billet manquant', $ticket->variance_reason);

        $this->assertSame(0, (int) $services->refresh()->variance);
        $this->assertSame(0, (int) $pharmacie->refresh()->variance);
        $this->assertNull($services->variance_reason);

        $this->assertSame(-1_000, (int) CashSession::query()->sum('variance'));
        $this->assertSame(35_000, (int) CashSession::query()->sum('counted_cash'));
    }

    public function test_a_variance_on_the_drawer_still_needs_a_justification(): void
    {
        [$cashier, $ticket] = $this->drawer();

        $this->assertViolation(
            'doit être justifié',
            fn () => app(CloseCashSession::class)->handle($ticket, 35_000, $cashier),
        );

        $this->assertSame(3, CashSession::query()->open()->count());
    }

    /**
     * Compter tout le tiroir sur la seule caisse Ticket donnait autrefois un
     * excédent de 1 000 : son théorique à elle valait 35 000. Le piège n'existe
     * plus, puisque la question porte sur le tiroir.
     */
    public function test_counting_the_whole_drawer_is_no_longer_an_error_on_one_register(): void
    {
        [$cashier, $ticket] = $this->drawer();

        $closed = app(CloseCashSession::class)->handle($ticket, 36_000, $cashier);

        $this->assertSame(0, (int) $closed->variance);
    }

    public function test_every_register_of_the_drawer_is_audited(): void
    {
        [$cashier, $ticket] = $this->drawer();

        app(CloseCashSession::class)->handle($ticket, 36_000, $cashier);

        $this->assertSame(3, AuditLog::where('event', 'session_closed')->count());
        $this->assertStringContainsString(
            'clôturée avec son tiroir (3 caisses)',
            (string) AuditLog::where('event', 'session_closed')->latest('id')->value('description'),
        );
    }

    /** Une caisse seule garde exactement le comportement d'avant. */
    public function test_a_lone_register_closes_on_its_own_figures(): void
    {
        $cashier = $this->makeUser();
        $session = $this->openSession($cashier, 10_000);

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 20_000, $cashier);

        $closed = app(CloseCashSession::class)->handle($session, 30_000, $cashier);

        $this->assertSame(30_000, (int) $closed->expected_cash);
        $this->assertSame(30_000, (int) $closed->counted_cash);
        $this->assertSame(0, (int) $closed->variance);
        $this->assertStringContainsString(
            'clôturée : théorique',
            (string) AuditLog::where('event', 'session_closed')->latest('id')->value('description'),
        );
    }

    /** Un caissier ne clôture pas le tiroir d'un autre. */
    public function test_the_drawer_belongs_to_its_cashier(): void
    {
        [, $ticket] = $this->drawer();

        $this->assertViolation(
            'appartient à un autre caissier',
            fn () => app(CloseCashSession::class)->handle($ticket, 36_000, $this->makeUser()),
        );
    }

    /**
     * Un tiroir compté une fois ne se contrôle qu'une fois : la validation
     * emporte toutes ses caisses.
     *
     * Les presenter en trois validations distinctes donnait au controle trois
     * gestes pour un seul fait, dont deux portant sur des lignes a zero, avec
     * le risque d'en valider une et de laisser le tiroir a moitie controle.
     */
    public function test_validating_one_register_validates_the_whole_drawer(): void
    {
        [$cashier, $ticket, $services, $pharmacie] = $this->drawer();

        app(CloseCashSession::class)->handle($ticket, 36_000, $cashier);

        $controle = $this->makeUser(['name' => 'Comptable du centre']);
        app(ValidateCashSession::class)->handle($services, $controle, 'Conforme');

        foreach ([$ticket, $services, $pharmacie] as $session) {
            $this->assertTrue($session->refresh()->isValidated());
            $this->assertSame('Comptable du centre', $session->validator_name);
            $this->assertSame('Conforme', $session->validation_note);
        }

        $this->assertSame(3, AuditLog::where('event', 'session_validated')->count());
        $this->assertStringContainsString(
            'validée avec son tiroir (3 caisses)',
            (string) AuditLog::where('event', 'session_validated')->latest('id')->value('description'),
        );
    }

    /** Le caissier ne valide pas davantage son tiroir que sa caisse. */
    public function test_the_cashier_never_validates_his_own_drawer(): void
    {
        [$cashier, $ticket] = $this->drawer();

        app(CloseCashSession::class)->handle($ticket, 36_000, $cashier);

        $this->assertViolation(
            'ne peut pas valider sa propre clôture',
            fn () => app(ValidateCashSession::class)->handle($ticket, $cashier),
        );

        $this->assertSame(0, CashSession::query()->where('status', CashSession::STATUS_VALIDATED)->count());
    }
}
