<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Actions\SetConsultationTicket;
use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Tests\Support\CashFixtures;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;
use Keneya\FinanceCaisse\Tests\TestCase;

/**
 * Le ticket de consultation : un seul acte le porte, jamais deux.
 */
class ConsultationTicketTest extends TestCase
{
    use CashFixtures;
    use CatalogFixtures;

    public function test_a_new_act_is_not_a_ticket(): void
    {
        $this->assertFalse($this->makeAct('CONS-GEN')->fresh()->is_consultation_ticket);
    }

    public function test_marking_an_act_makes_it_the_ticket_and_is_audited(): void
    {
        $act = $this->makeAct('TICKET');

        $this->mark($act, true);

        $this->assertTrue($act->fresh()->is_consultation_ticket);
        $this->assertSame(1, AuditLog::where('event', 'consultation_ticket_set')->count());
    }

    public function test_only_one_act_is_the_ticket_at_a_time(): void
    {
        $first = $this->makeAct('TICKET-A');
        $second = $this->makeAct('TICKET-B');
        $third = $this->makeAct('TICKET-C');

        $this->mark($first, true);
        $this->mark($second, true);
        $this->mark($third, true);

        $this->assertSame(1, Act::where('is_consultation_ticket', true)->count());
        $this->assertTrue($third->fresh()->is_consultation_ticket);
        $this->assertFalse($first->fresh()->is_consultation_ticket);
        $this->assertFalse($second->fresh()->is_consultation_ticket);
    }

    public function test_unmarking_leaves_no_ticket_and_is_audited(): void
    {
        $act = $this->makeAct('TICKET');
        $this->mark($act, true);

        $this->mark($act, false);

        $this->assertSame(0, Act::where('is_consultation_ticket', true)->count());
        $this->assertSame(1, AuditLog::where('event', 'consultation_ticket_unset')->count());
    }

    public function test_repeating_the_current_state_changes_nothing(): void
    {
        $act = $this->makeAct('TICKET');
        $this->mark($act, true);
        $this->mark($act, true);
        $this->mark($this->makeAct('AUTRE'), false);

        $this->assertTrue($act->fresh()->is_consultation_ticket);
        $this->assertSame(1, AuditLog::where('event', 'like', 'consultation_ticket_%')->count());
    }

    public function test_a_deactivated_act_cannot_become_the_ticket(): void
    {
        $current = $this->makeAct('TICKET');
        $this->mark($current, true);
        $inactive = $this->makeAct('VIEUX', null, false);

        $this->assertViolation('est désactivé', fn () => $this->mark($inactive, true));

        // Le refus n'a rien défait : l'ancien ticket l'est toujours.
        $this->assertTrue($current->fresh()->is_consultation_ticket);
        $this->assertFalse($inactive->fresh()->is_consultation_ticket);
    }

    private function mark(Act $act, bool $isTicket): Act
    {
        return app(SetConsultationTicket::class)->handle($act, $isTicket, $this->makeUser());
    }
}
