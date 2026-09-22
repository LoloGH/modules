<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Actions\RecordPayment;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\CashSession;
use Keneya\FinanceCaisse\Models\PatientDeposit;
use Keneya\FinanceCaisse\Models\Payment;
use Keneya\FinanceCaisse\Models\PaymentMethod;
use Keneya\FinanceCaisse\Services\CashSessionCalculator;
use Keneya\FinanceCaisse\Services\PatientAccount;
use Keneya\FinanceCaisse\Support\Rbac;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Les avances et le compte financier du patient : l'argent reçu d'avance
 * entre dans le tiroir sans être une recette, et règle ensuite les actes du
 * patient par le moyen « Compte patient ».
 */
class PatientAccountHttpTest extends HttpTestCase
{
    private const PATIENT = 'PAT-000123';

    private function accountMethod(): PaymentMethod
    {
        return $this->makeMethod('compte_patient', PaymentMethod::KIND_PATIENT_ACCOUNT);
    }

    private function caisse(TestUser $cashier): CashSession
    {
        return $this->openSession($cashier, 10_000, $this->makeRegister('CAISSE-SERVICES'));
    }

    private function deposit(TestUser $cashier, CashSession $session, int $amount): void
    {
        $this->actingAs($cashier)
            ->post(route('finance.cash.deposits.store', $session), [
                'payment_method_id' => $this->cashMethod()->id,
                'amount' => (string) $amount,
                'patient_id' => self::PATIENT,
                'patient_name' => 'Aminata Traoré',
                'note' => 'Avance sur hospitalisation',
            ])
            ->assertRedirect(route('finance.cash.sessions.show', $session))
            ->assertSessionHas('finance_status');
    }

    public function test_a_deposit_enters_the_drawer_without_being_revenue(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);

        $this->deposit($cashier, $session, 20_000);

        $deposit = PatientDeposit::sole();
        $this->assertSame(20_000, $deposit->amount);
        $this->assertStringStartsWith('AVA-', $deposit->number);
        $this->assertSame(1, AuditLog::where('event', 'deposit_recorded')->count());

        // Dans le tiroir : 10 000 de fonds + 20 000 d'avance.
        $totals = app(CashSessionCalculator::class)->totals($session->refresh());
        $this->assertSame(30_000, $totals['expected_cash']);
        $this->assertSame(1, $totals['deposits_count']);

        // Pas une recette : ni paiement, ni ligne dans les recettes.
        $this->assertSame(0, Payment::count());
        $this->actingAs($cashier)->get('/finance/recettes')->assertSee('0 FCFA');

        // Le reçu s'imprime, et la session montre l'avance.
        $this->get(route('finance.cash.deposits.receipt', $deposit))->assertOk()->assertSee("REÇU D'AVANCE", false);
        $this->get(route('finance.cash.sessions.show', $session))->assertSee('Avance sur hospitalisation');
    }

    public function test_the_account_pays_the_acts_of_the_patient_and_never_goes_below_zero(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        $this->deposit($cashier, $session, 20_000);

        $paiement = app(RecordPayment::class)->handle($session->refresh(), $this->accountMethod(), 15_000, $cashier, [
            'patient_id' => self::PATIENT,
            'description' => 'Consultation',
        ]);

        $this->assertSame(15_000, $paiement->amount);
        $this->assertSame(5_000, app(PatientAccount::class)->balance(self::PATIENT));

        // Le tiroir n'a pas bougé : l'argent y était déjà.
        $this->assertSame(30_000, app(CashSessionCalculator::class)->totals($session->refresh())['expected_cash']);

        // Au-delà du solde, refus.
        $this->assertViolation(
            'ne contient que',
            fn () => app(RecordPayment::class)->handle($session->refresh(), $this->accountMethod(), 6_000, $cashier, ['patient_id' => self::PATIENT]),
        );

        // Sans patient désigné, aucun compte ne se devine.
        $this->assertViolation(
            'doit désigner le patient',
            fn () => app(RecordPayment::class)->handle($session->refresh(), $this->accountMethod(), 1_000, $cashier),
        );
    }

    public function test_the_account_screen_shows_the_statement_and_the_balance(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        $this->deposit($cashier, $session, 20_000);
        app(RecordPayment::class)->handle($session->refresh(), $this->accountMethod(), 15_000, $cashier, [
            'patient_id' => self::PATIENT,
            'description' => 'Consultation',
        ]);

        $this->actingAs($this->accountant())->get('/finance/comptes')
            ->assertOk()
            ->assertSee('Aminata Traoré')
            ->assertSee(self::PATIENT)
            ->assertSee('5 000 FCFA');

        $this->get('/finance/comptes/'.self::PATIENT)
            ->assertOk()
            ->assertSee('Avance sur hospitalisation')
            ->assertSee('Consultation')
            ->assertSee('Utilisation')
            ->assertSee('20 000 FCFA')
            ->assertSee('5 000 FCFA');

        // La recherche retrouve le compte, et un inconnu reste vide.
        $this->get('/finance/comptes?q=Aminata')->assertSee(self::PATIENT);
        $this->get('/finance/comptes?q=Inconnu')->assertSee('Aucun compte');
    }

    public function test_a_spent_deposit_cannot_be_cancelled_but_an_untouched_one_can(): void
    {
        $cashier = $this->cashier();
        $session = $this->caisse($cashier);
        $this->deposit($cashier, $session, 20_000);
        app(RecordPayment::class)->handle($session->refresh(), $this->accountMethod(), 15_000, $cashier, [
            'patient_id' => self::PATIENT,
            'description' => 'Consultation',
        ]);

        $deposit = PatientDeposit::sole();

        $this->actingAs($this->accountant())->from(route('finance.cash.sessions.show', $session))
            ->post(route('finance.cash.deposits.cancel', $deposit), ['reason' => 'Erreur de saisie'])
            ->assertSessionHas('finance_error');

        $this->assertSame(PatientDeposit::STATUS_VALID, $deposit->refresh()->status);

        // Une seconde avance, intacte, s'annule.
        $this->deposit($cashier, $session->refresh(), 3_000);
        $intacte = PatientDeposit::query()->latest('id')->first();

        $this->actingAs($this->accountant())
            ->post(route('finance.cash.deposits.cancel', $intacte), ['reason' => 'Erreur de saisie'])
            ->assertRedirect(route('finance.cash.sessions.show', $session->id));

        $this->assertSame(PatientDeposit::STATUS_CANCELLED, $intacte->refresh()->status);
        $this->assertSame(5_000, app(PatientAccount::class)->balance(self::PATIENT));
        $this->assertSame(30_000, app(CashSessionCalculator::class)->totals($session->refresh())['expected_cash']);
    }

    public function test_the_account_screens_need_their_right(): void
    {
        // Le caissier voit le compte : c'est lui qui dit au patient ce qu'il
        // lui reste. La direction, qui ne touche pas à la caisse, ne l'a pas.
        $this->actingAs($this->userWithRole(Rbac::ROLE_DIRECTOR))->get('/finance/comptes')->assertForbidden();
        $this->actingAs($this->cashier())->get('/finance/comptes')->assertOk();
        $this->actingAs($this->accountant())->get('/finance/comptes')->assertOk();

        // Le menu ne montre que ce que le compte peut ouvrir.
        $this->actingAs($this->accountant())->get('/finance')->assertSee(route('finance.accounts.index'), false);
    }
}
