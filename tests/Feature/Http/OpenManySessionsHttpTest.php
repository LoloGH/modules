<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashierRegister;
use Keneya\FinanceCaisse\Models\CashierSetting;
use Keneya\FinanceCaisse\Models\CashSession;

/**
 * Ouvrir plusieurs caisses d'un coup : groupées (un seul fonds, un tiroir) ou
 * séparées (un fonds par caisse). La limite se compte en tiroirs. Tout ou rien.
 */
class OpenManySessionsHttpTest extends HttpTestCase
{
    private function allow(int $limit, string $cashierId): void
    {
        CashierSetting::create(['cashier_id' => $cashierId, 'max_open_sessions' => $limit]);
    }

    public function test_with_several_free_registers_both_modes_are_offered_even_with_a_limit_of_one(): void
    {
        $this->makeRegister('CAISSE-TICKET');
        $this->makeRegister('CAISSE-SERVICES');

        $this->actingAs($this->cashier())->get('/finance/caisse')
            ->assertOk()
            ->assertSee('Ouvrir les caisses cochées')
            ->assertSee('Groupées : un seul fonds, un seul tiroir')
            ->assertSee('Séparées : un fonds par caisse')
            ->assertSee('Fonds initial unique');
    }

    public function test_with_a_single_free_register_the_simple_form_remains(): void
    {
        $this->makeRegister('CAISSE-TICKET');

        $this->actingAs($this->cashier())->get('/finance/caisse')
            ->assertOk()
            ->assertSee('Ouvrir la session')
            ->assertDontSee('Ouvrir les caisses cochées');
    }

    // ------------------------------------------------------ Groupées

    public function test_grouped_registers_share_one_fund_and_one_drawer_even_with_a_limit_of_one(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');
        $pharmacie = $this->makeRegister('CAISSE-PHARMACIE');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'groupees',
            'registers' => [$ticket->id, $services->id, $pharmacie->id],
            'opening_float' => '15 000',
        ])
            ->assertRedirect(route('finance.cash.index'))
            ->assertSessionHas('finance_status', '3 caisse(s) ouverte(s) ensemble : Caisse CAISSE-TICKET, Caisse CAISSE-SERVICES, Caisse CAISSE-PHARMACIE. Fonds initial unique de 15 000 FCFA, porté par Caisse CAISSE-TICKET.');

        $sessions = CashSession::query()->open()->where('cashier_id', (string) $cashier->id)->get();

        $this->assertSame([$ticket->id => 15_000, $services->id => 0, $pharmacie->id => 0],
            $sessions->pluck('opening_float', 'cash_register_id')->map(fn ($v) => (int) $v)->all());
        $this->assertCount(1, $sessions->pluck('drawer_key')->unique());
        $this->assertNotNull($sessions->first()->drawer_key);
        $this->assertSame(1, CashSession::openDrawersFor((string) $cashier->id));
        $this->assertSame(3, AuditLog::where('event', 'session_opened')->count());

        // Le bureau le montre : trois caisses, un tiroir.
        $this->get('/finance/caisse')
            ->assertSee('3 caisse(s), 1 tiroir(s) sur 1 autorisé(s)')
            ->assertSee('Tiroir commun');
    }

    /**
     * Le fonds appartient au tiroir : les trois caisses du groupe l'affichent,
     * au lieu de montrer « 0 FCFA » sur deux d'entre elles comme si elles
     * avaient ouvert les mains vides.
     */
    public function test_the_drawer_fund_is_shown_on_every_register_of_the_group(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'groupees',
            'registers' => [$ticket->id, $services->id],
            'opening_float' => '15 000',
        ]);

        // Deux visites : la premiere consomme le message d'ouverture, qui cite
        // lui aussi le fonds. Ce qu'on mesure ici, c'est le tableau.
        $this->get('/finance/caisse');
        $page = $this->get('/finance/caisse')->assertOk()->getContent();

        // Les deux lignes portent le fonds du tiroir, et le disent.
        $this->assertSame(2, substr_count((string) $page, 'fonds du tiroir'));
        $this->assertSame(2, substr_count((string) $page, '15 000 FCFA'));
    }

    /**
     * La clôture porte sur le tiroir, pas sur une caisse : un seul comptage,
     * opposé au théorique de l'ensemble.
     */
    public function test_the_closing_form_speaks_of_the_drawer_and_closes_them_all(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'groupees',
            'registers' => [$ticket->id, $services->id],
            'opening_float' => '15 000',
        ]);

        $session = CashSession::query()->open()->orderBy('id')->firstOrFail();

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertOk()
            ->assertSee('Clôturer le tiroir')
            ->assertSee('une seule fois', false)
            ->assertSee('Tiroir commun à 2 caisses', false);

        $this->post(route('finance.cash.sessions.close', $session), ['counted_cash' => '15 000'])
            ->assertSessionHas('finance_status');

        $this->assertSame(0, CashSession::query()->open()->count());
    }

    public function test_after_a_grouped_opening_a_separate_drawer_needs_a_higher_limit(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');
        $pharmacie = $this->makeRegister('CAISSE-PHARMACIE');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'groupees', 'registers' => [$ticket->id, $services->id], 'opening_float' => '0',
        ]);

        // Limite 1 atteinte : un second tiroir est refusé.
        $this->post('/finance/caisse/sessions', ['cash_register_id' => $pharmacie->id, 'opening_float' => '0'])
            ->assertSessionHas('finance_error');

        $this->assertSame(2, CashSession::count());
    }

    // ------------------------------------------------------ Séparées

    public function test_separate_registers_each_get_their_own_fund_and_drawer(): void
    {
        $cashier = $this->cashier();
        $this->allow(3, (string) $cashier->id);
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'separees',
            'registers' => [$ticket->id, $services->id],
            'floats' => [$ticket->id => '10 000', $services->id => '20000'],
        ])
            ->assertRedirect(route('finance.cash.index'))
            ->assertSessionHas('finance_status', '2 caisse(s) ouverte(s) séparément : Caisse CAISSE-TICKET, Caisse CAISSE-SERVICES. Fonds initiaux : 30 000 FCFA au total.');

        $sessions = CashSession::query()->open()->where('cashier_id', (string) $cashier->id)->get();

        $this->assertSame([$ticket->id => 10_000, $services->id => 20_000],
            $sessions->pluck('opening_float', 'cash_register_id')->map(fn ($v) => (int) $v)->all());
        $this->assertTrue($sessions->every(fn (CashSession $s) => $s->drawer_key === null));
        $this->assertSame(2, CashSession::openDrawersFor((string) $cashier->id));
    }

    public function test_separate_registers_beyond_the_limit_open_none(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        $this->actingAs($cashier)->from('/finance/caisse')->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'separees',
            'registers' => [$ticket->id, $services->id],
            'floats' => [$ticket->id => '1000', $services->id => '1000'],
        ])->assertSessionHas('finance_error');

        $this->assertSame(0, CashSession::count());
    }

    // ------------------------------------------------------ Règles communes

    /**
     * Trois caisses séparées demandées par un caissier limité à un tiroir : le
     * refus doit nommer la vraie cause.
     *
     * Le contrôle se faisait caisse par caisse : la première s'ouvrait, la
     * deuxième butait, et le message annonçait « Ce caissier tient déjà un
     * tiroir ouvert » à quelqu'un qui n'en tenait aucun, puisque le tiroir en
     * question venait d'être annulé par le retour en arrière. On lui demandait
     * de clôturer une session qui n'existait pas.
     */
    public function test_separate_registers_beyond_the_limit_explain_the_real_cause(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');
        $pharmacie = $this->makeRegister('CAISSE-PHARMACIE');

        $this->actingAs($cashier)->from('/finance/caisse')->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'separees',
            'registers' => [$ticket->id, $services->id, $pharmacie->id],
            'floats' => [$ticket->id => '3000', $services->id => '30000', $pharmacie->id => '5000'],
        ])->assertSessionHas('finance_error', function (string $message): bool {
            return str_contains($message, 'Vous ne pouvez tenir que 1 tiroir(s)')
                && str_contains($message, '3 caisses séparées demandent 3 tiroirs')
                && str_contains($message, 'Ouvrez-les ensemble')
                // Ce qu'il ne doit plus dire : il n'en tient aucun.
                && ! str_contains($message, 'tient déjà un tiroir ouvert');
        });

        $this->assertSame(0, CashSession::count());
    }

    /** Avec la limite relevée, les mêmes caisses s'ouvrent séparément. */
    public function test_separate_registers_fit_once_the_limit_is_raised(): void
    {
        $cashier = $this->cashier();
        $this->allow(3, (string) $cashier->id);
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');
        $pharmacie = $this->makeRegister('CAISSE-PHARMACIE');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'separees',
            'registers' => [$ticket->id, $services->id, $pharmacie->id],
            'floats' => [$ticket->id => '3000', $services->id => '30000', $pharmacie->id => '5000'],
        ])->assertSessionHas('finance_status');

        $this->assertSame(3, CashSession::query()->open()->count());
        $this->assertSame(3, CashSession::openDrawersFor((string) $cashier->id));
    }

    /** Un tiroir déjà tenu se dit comme tel, sans inventer de caisses. */
    public function test_an_already_held_drawer_is_named_in_the_refusal(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        $this->openSession($cashier, 1_000, $ticket);

        $this->actingAs($cashier)->from('/finance/caisse')->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'groupees',
            'registers' => [$services->id],
            'opening_float' => '1000',
        ])->assertSessionHas('finance_error', function (string $message): bool {
            return str_contains($message, 'Vous tenez déjà 1 tiroir(s) sur 1 autorisé(s)');
        });

        $this->assertSame(1, CashSession::query()->open()->count());
    }

    public function test_one_refused_register_opens_none_and_names_it(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');
        $services = $this->makeRegister('CAISSE-SERVICES');

        $this->openSession($this->cashier(), 0, $services);

        $this->actingAs($cashier)->from('/finance/caisse')->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'groupees',
            'registers' => [$ticket->id, $services->id],
            'opening_float' => '1000',
        ])
            ->assertRedirect('/finance/caisse')
            ->assertSessionHas('finance_error', "Aucune caisse n'a été ouverte. Caisse CAISSE-SERVICES : Une session est déjà ouverte sur cette caisse.");

        $this->assertSame(0, CashSession::query()->where('cashier_id', (string) $cashier->id)->count());
    }

    public function test_assignments_still_apply(): void
    {
        $cashier = $this->cashier();
        $mine = $this->makeRegister('CAISSE-TICKET');
        $notMine = $this->makeRegister('CAISSE-SERVICES');
        CashierRegister::create(['cashier_id' => (string) $cashier->id, 'cash_register_id' => $mine->id]);

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), [
            'mode' => 'groupees', 'registers' => [$mine->id, $notMine->id], 'opening_float' => '0',
        ])->assertSessionHas('finance_error');

        $this->assertSame(0, CashSession::count());
    }

    public function test_invalid_input_is_refused(): void
    {
        $cashier = $this->cashier();
        $ticket = $this->makeRegister('CAISSE-TICKET');

        $this->actingAs($cashier)->post(route('finance.cash.sessions.open-many'), ['mode' => 'groupees', 'opening_float' => '0'])
            ->assertSessionHasErrors('registers');

        $this->post(route('finance.cash.sessions.open-many'), ['mode' => 'groupees', 'registers' => [$ticket->id], 'opening_float' => '-5'])
            ->assertSessionHasErrors('opening_float');

        $this->post(route('finance.cash.sessions.open-many'), ['mode' => 'separees', 'registers' => [$ticket->id], 'floats' => [$ticket->id => '-5']])
            ->assertSessionHasErrors('floats.'.$ticket->id);

        $this->post(route('finance.cash.sessions.open-many'), ['mode' => 'autre', 'registers' => [$ticket->id]])
            ->assertSessionHasErrors('mode');

        $this->assertSame(0, CashSession::count());
    }

    public function test_without_the_right_to_open_it_is_forbidden(): void
    {
        $ticket = $this->makeRegister('CAISSE-TICKET');

        $this->actingAs($this->accountant())
            ->post(route('finance.cash.sessions.open-many'), ['mode' => 'groupees', 'registers' => [$ticket->id], 'opening_float' => '0'])
            ->assertForbidden();
    }

    // ------------------------------------------- Identifiant du patient

    public function test_the_payment_block_asks_for_the_patient_identifier_and_the_list_shows_it(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier);

        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $session))
            ->assertSee('Identifiant du patient')
            ->assertSee('name="patient_id"', false);

        $this->post(route('finance.cash.payments.store', $session), [
            'payment_method_id' => $this->cashMethod()->id,
            'amount' => '1000',
            'patient_id' => 'PAT-00042',
            'patient_name' => 'Aminata Traoré',
        ]);

        $this->get(route('finance.cash.sessions.show', $session))
            ->assertSee('Aminata Traoré')
            ->assertSee('PAT-00042');
    }
}
