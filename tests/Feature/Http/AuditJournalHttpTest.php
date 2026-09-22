<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\CloseCashSession;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Actions\ValidateCashSession;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Support\Rbac;
use LogicException;

/**
 * Le journal d'audit : qui a fait quoi, lisible, filtrable, exportable — et
 * qu'on ne réécrit pas.
 */
class AuditJournalHttpTest extends HttpTestCase
{
    /**
     * Une journée de caisse ordinaire : ouverture, encaissement, clôture,
     * validation par quelqu'un d'autre.
     */
    private function journee(): CashSession
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-TICKET'));

        app(RecordPayment::class)->handle($session, $this->cashMethod(), 5_000, $cashier, [
            'patient_id' => 'PAT-000123',
            'description' => 'Consultation',
        ]);

        app(CloseCashSession::class)->handle($session->refresh(), 15_000, $cashier);
        app(ValidateCashSession::class)->handle($session->refresh(), $this->accountant(), 'Compté avec le caissier');

        return $session->refresh();
    }

    public function test_the_journal_reads_the_events_in_french_and_filters_them(): void
    {
        $session = $this->journee();

        $page = $this->actingAs($this->accountant())->get('/finance/journal?du=2000-01-01')
            ->assertOk()
            ->assertSee('Session de caisse ouverte')
            ->assertSee('Encaissement PAI-')
            ->assertSee('Clôture validée')
            ->assertSee($session->number);

        // Les chiffres de la clôture se lisent sans rouvrir la session.
        $page->assertSee('théorique 15 000 FCFA');

        // Filtre par événement. Le libellé figure aussi dans la liste
        // déroulante : ce qui disparaît, c'est la ligne, donc sa pièce.
        $this->get('/finance/journal?du=2000-01-01&evenement=session_validated')
            ->assertSee('Session '.$session->number.' validée')
            ->assertDontSee('Encaissement PAI-');

        // Filtre par auteur, et recherche sur la pièce.
        $this->get('/finance/journal?du=2000-01-01&auteur=introuvable')->assertSee('Aucune écriture');
        $this->get('/finance/journal?du=2000-01-01&q='.$session->number)->assertSee('Clôture validée');
        $this->get('/finance/journal?du=2000-01-01&q=PAT-000123')->assertSee('Aucune écriture');
    }

    public function test_the_validation_line_carries_the_figures_and_the_cashier(): void
    {
        $this->journee();

        $entry = AuditLog::query()->where('event', 'session_validated')->sole();

        $this->assertSame(15_000, $entry->new_values['expected_cash']);
        $this->assertSame(15_000, $entry->new_values['counted_cash']);
        $this->assertSame(0, $entry->new_values['variance']);
        $this->assertSame('Compté avec le caissier', $entry->new_values['note']);
        $this->assertNotSame($entry->user_id, (string) $entry->new_values['cashier']);
    }

    public function test_the_journal_is_exported_as_csv(): void
    {
        $this->journee();

        $csv = $this->actingAs($this->accountant())
            ->get('/finance/journal/export?du=2000-01-01')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->streamedContent();

        $this->assertStringContainsString('Date;Événement;Description;Auteur;Objet;', $csv);
        $this->assertStringContainsString('Clôture validée', $csv);
    }

    public function test_the_journal_needs_its_right_and_never_rewrites_a_line(): void
    {
        $this->journee();

        $this->actingAs($this->cashier())->get('/finance/journal')->assertForbidden();
        $this->actingAs($this->userWithRole(Rbac::ROLE_DIRECTOR))->get('/finance/journal')->assertForbidden();

        $this->actingAs($this->accountant())->get('/finance')
            ->assertSee(route('finance.audit.index'), false);

        $entry = AuditLog::query()->latest('id')->first();

        $this->expectException(LogicException::class);
        $entry->update(['description' => 'Réécrit']);
    }

    public function test_an_unknown_event_is_shown_as_it_is(): void
    {
        app(Auditor::class)->record(
            'evenement_inconnu',
            null,
            'Écrit par une autre version',
            [],
            [],
            $this->cashier(),
        );

        $this->actingAs($this->accountant())->get('/finance/journal?du=2000-01-01')
            ->assertOk()
            ->assertSee('evenement_inconnu')
            ->assertSee('Écrit par une autre version');
    }
}
