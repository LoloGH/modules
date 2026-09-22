<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\Act;
use Keneya\FinanceCaisse\Tests\Support\CatalogFixtures;

/**
 * La case « ticket de consultation » sur la fiche d'un acte : structurer le
 * catalogue est administratif.
 */
class ConsultationTicketHttpTest extends HttpTestCase
{
    use CatalogFixtures;

    public function test_the_administrator_marks_an_act_as_the_ticket(): void
    {
        $act = $this->makeAct('TICKET');

        $this->actingAs($this->admin())
            ->get(route('finance.catalog.acts.show', $act))
            ->assertOk()
            ->assertSee('Cet acte est le ticket de consultation');

        $this->post(route('finance.catalog.acts.ticket', $act), ['is_consultation_ticket' => '1'])
            ->assertRedirect(route('finance.catalog.acts.show', $act));

        $this->assertTrue($act->fresh()->is_consultation_ticket);

        $this->get(route('finance.catalog.acts.show', $act))->assertSee('badge info', false);
    }

    public function test_marking_another_act_moves_the_ticket(): void
    {
        $first = $this->makeAct('TICKET-A');
        $second = $this->makeAct('TICKET-B');

        $this->actingAs($this->admin());
        $this->post(route('finance.catalog.acts.ticket', $first), ['is_consultation_ticket' => '1']);
        $this->post(route('finance.catalog.acts.ticket', $second), ['is_consultation_ticket' => '1']);

        $this->assertSame([$second->id], Act::where('is_consultation_ticket', true)->pluck('id')->all());
    }

    public function test_an_unchecked_box_removes_the_mark(): void
    {
        $act = $this->makeAct('TICKET');

        $this->actingAs($this->admin());
        $this->post(route('finance.catalog.acts.ticket', $act), ['is_consultation_ticket' => '1']);
        $this->post(route('finance.catalog.acts.ticket', $act), [])
            ->assertRedirect(route('finance.catalog.acts.show', $act));

        $this->assertFalse($act->fresh()->is_consultation_ticket);
    }

    public function test_a_deactivated_act_is_refused_with_a_message(): void
    {
        $act = $this->makeAct('VIEUX', null, false);

        $this->actingAs($this->admin())
            ->from(route('finance.catalog.acts.show', $act))
            ->post(route('finance.catalog.acts.ticket', $act), ['is_consultation_ticket' => '1'])
            ->assertRedirect(route('finance.catalog.acts.show', $act))
            ->assertSessionHas('finance_error');

        $this->assertFalse($act->fresh()->is_consultation_ticket);
    }

    public function test_cashier_and_accountant_can_neither_see_nor_use_the_box(): void
    {
        $act = $this->makeAct('TICKET');

        foreach ([$this->cashier(), $this->accountant()] as $user) {
            $this->actingAs($user)
                ->get(route('finance.catalog.acts.show', $act))
                ->assertOk()
                ->assertDontSee('Cet acte est le ticket de consultation');

            $this->actingAs($user)
                ->post(route('finance.catalog.acts.ticket', $act), ['is_consultation_ticket' => '1'])
                ->assertForbidden();
        }

        $this->assertFalse($act->fresh()->is_consultation_ticket);
    }
}
