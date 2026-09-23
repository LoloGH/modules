<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\RecordDisbursement;
use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\Setting;
use Keneya\FinanceCaisse\Services\FinanceSettings;
use Keneya\FinanceCaisse\Support\Rbac;

/**
 * Les paramètres financiers : ce que l'établissement règle depuis
 * l'application, par-dessus le fichier de configuration.
 */
class SettingsHttpTest extends HttpTestCase
{
    /**
     * @param  array<string, string>  $values
     */
    private function save(array $values, bool $expectError = false): void
    {
        $response = $this->actingAs($this->admin())
            ->from('/finance/parametres')
            ->post(route('finance.settings.update'), ['settings' => $values]);

        $expectError
            ? $response->assertSessionHas('finance_error')
            : $response->assertRedirect(route('finance.settings.index'))->assertSessionHas('finance_status');
    }

    public function test_a_setting_overrides_the_configuration_file(): void
    {
        $this->assertSame(30, config('finance.receivables.insurer_due_days'));

        $this->save(['receivables.insurer_due_days' => '45']);

        $this->assertSame(45, app(FinanceSettings::class)->value('receivables.insurer_due_days'));
        $this->assertSame('45', Setting::query()->whereKey('receivables.insurer_due_days')->value('value'));
        $this->assertSame(1, AuditLog::where('event', 'settings_updated')->count());

        // Rien à changer si l'on renvoie la même valeur.
        $this->save(['receivables.insurer_due_days' => '45'], expectError: true);

        // Revenir au défaut du fichier efface la ligne.
        $this->save(['receivables.insurer_due_days' => '30']);
        $this->assertSame(0, Setting::query()->count());
    }

    public function test_the_prefix_of_the_next_pieces_follows_the_setting(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-TICKET'));

        $avant = app(RecordPayment::class)->handle($session, $this->cashMethod(), 1_000, $cashier);
        $this->assertStringStartsWith('PAI-', $avant->number);

        $this->save(['identifiers.prefixes.payment' => 'ENC']);

        $apres = app(RecordPayment::class)->handle($session->refresh(), $this->cashMethod(), 2_000, $cashier);

        // Le passé garde son numéro, la suite prend le nouveau préfixe.
        $this->assertStringStartsWith('PAI-', $avant->refresh()->number);
        $this->assertStringStartsWith('ENC-', $apres->number);
    }

    public function test_the_expense_categories_are_edited_but_never_lose_a_used_one(): void
    {
        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 20_000, $this->makeRegister('CAISSE-TICKET'));

        app(RecordDisbursement::class)->handle($session, $this->cashMethod(), 5_000, 'Achat', $cashier, ['category' => 'fournitures']);

        // Retirer une catégorie déjà portée par un décaissement : refusé.
        $this->save(['expense_categories' => 'divers : Divers'], expectError: true);

        // En ajouter une : accepté, et elle est proposée à la caisse.
        $this->save(['expense_categories' => "fournitures : Fournitures et consommables\nblanchisserie : Blanchisserie"]);

        $this->assertSame(
            ['fournitures' => 'Fournitures et consommables', 'blanchisserie' => 'Blanchisserie'],
            app(FinanceSettings::class)->value('expense_categories'),
        );

        $this->actingAs($cashier)->get(route('finance.cash.sessions.show', $session->refresh()))
            ->assertSee('Blanchisserie');
    }

    public function test_a_value_out_of_range_or_empty_is_refused(): void
    {
        $this->save(['cash.max_open_sessions_per_cashier' => '99'], expectError: true);
        $this->save(['facility.address' => '   '], expectError: true);

        $this->assertSame(0, Setting::query()->count());
    }

    public function test_the_screen_shows_the_current_values_and_needs_its_right(): void
    {
        $this->save(['facility.phone' => '+223 76 00 00 00']);

        $this->actingAs($this->admin())->get('/finance/parametres')
            ->assertOk()
            ->assertSee('Paramètres financiers')
            ->assertSee('+223 76 00 00 00')
            ->assertSee('Délai de paiement des patients (jours)')
            ->assertSee('Catégories de dépenses');

        // Le comptable et le caissier n'y touchent pas.
        $this->actingAs($this->accountant())->get('/finance/parametres')->assertForbidden();
        $this->actingAs($this->userWithRole(Rbac::ROLE_CASHIER))->get('/finance/parametres')->assertForbidden();

        $this->actingAs($this->admin())->get('/finance')
            ->assertSee(route('finance.settings.index'), false);
    }

    public function test_the_printed_documents_carry_the_established_identity(): void
    {
        $this->save(['facility.address' => 'Kayes, quartier Légal Ségou']);

        $cashier = $this->cashier();
        $session = $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-TICKET'));
        $payment = app(RecordPayment::class)->handle($session, $this->cashMethod(), 3_000, $cashier);

        $this->actingAs($cashier)
            ->get(route('finance.cash.payments.receipt', $payment))
            ->assertOk()
            ->assertSee('Kayes, quartier Légal Ségou');
    }
}
