<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature\Http;

use Keneya\FinanceCaisse\Access\UserPermissions;
use Keneya\FinanceCaisse\Cashiers\HostCashier;
use Keneya\FinanceCaisse\Contracts\CashierDirectory;
use Keneya\FinanceCaisse\Finance;
use Keneya\FinanceCaisse\Models\AuditLog;
use Keneya\FinanceCaisse\Models\UserPermission;
use Keneya\FinanceCaisse\Tests\Support\TestUser;

/**
 * Écran « Utilisateurs » : les capacités de chacun dans Finance, réglées dans
 * le module, sans toucher aux rôles de l'hôte.
 */
class UserPermissionsHttpTest extends HttpTestCase
{
    /**
     * @param  list<TestUser>  $users
     */
    private function hostDeclares(array $users): void
    {
        $cashiers = array_map(
            static fn (TestUser $user): HostCashier => new HostCashier((string) $user->id, $user->name, 'Comptabilité'),
            $users,
        );

        $this->app->instance(CashierDirectory::class, new class($cashiers) implements CashierDirectory
        {
            /** @param list<HostCashier> $cashiers */
            public function __construct(private readonly array $cashiers) {}

            public function cashiers(): array
            {
                return $this->cashiers;
            }
        });
    }

    /**
     * @param  list<string>  $permissions
     */
    private function grant(TestUser $user, array $permissions): void
    {
        $this->actingAs($this->admin())
            ->post(route('finance.users.permissions.store'), ['user_id' => (string) $user->id, 'permissions' => $permissions])
            ->assertRedirect(route('finance.users.index'))
            ->assertSessionHas('finance_status');
    }

    public function test_the_admin_sees_the_users_the_host_lets_in_with_their_inherited_rights(): void
    {
        $awa = $this->cashier();
        $awa->update(['name' => 'Awa Keita']);
        $this->hostDeclares([$awa->fresh()]);

        $page = $this->actingAs($this->admin())
            ->get('/finance/utilisateurs')
            ->assertOk()
            ->assertSee('Awa Keita')
            ->assertSee('Comptabilité')
            ->assertSee("Rôles de l'application hôte", false)
            ->assertSee('Valider une clôture de caisse')
            // Jamais réglable ici : l'entrée et l'administration du module.
            ->assertDontSee('value="finance.access"', false)
            ->assertDontSee('value="finance.roles.manage"', false)
            ->getContent();

        // Point de départ : ce que son rôle de caissier lui donne.
        $this->assertMatchesRegularExpression('/value="finance\.sessions\.open"\s+checked/', (string) $page);
        $this->assertDoesNotMatchRegularExpression('/value="finance\.sessions\.validate"\s+checked/', (string) $page);
    }

    public function test_the_menu_shows_users_to_the_admin_only(): void
    {
        $this->actingAs($this->admin())->get('/finance')->assertSee(route('finance.users.index'), false);
        $this->actingAs($this->cashier())->get('/finance')->assertDontSee(route('finance.users.index'), false);
        $this->actingAs($this->cashier())->get('/finance/utilisateurs')->assertForbidden();
    }

    public function test_capacities_set_in_finance_grant_and_revoke_whatever_the_host_roles(): void
    {
        $awa = $this->cashier();
        $this->hostDeclares([$awa]);

        // Son rôle de caissier ne lui donne pas la validation des sessions.
        $this->actingAs($awa)->get('/finance/sessions')->assertForbidden();

        $this->grant($awa, ['finance.sessions.view', 'finance.sessions.validate', 'finance.catalog.view', 'finance.catalog.manage']);

        $this->assertSame(
            ['finance.catalog.view', 'finance.catalog.manage', 'finance.sessions.view', 'finance.sessions.validate'],
            app(UserPermissions::class)->for((string) $awa->id),
        );

        // Accordé ici : valider une session, rédiger des actes.
        $this->actingAs($awa)->get('/finance/sessions')->assertOk();
        $this->actingAs($awa)->get('/finance/catalogue/actes')->assertOk();
        $this->assertTrue($awa->fresh()->can('finance.catalog.manage'));

        // Décoché ici : refusé, bien que son rôle l'accorde.
        $this->assertFalse($awa->fresh()->can('finance.payments.create'));
        $this->actingAs($awa)->get('/finance/paiements')->assertForbidden();
        $this->actingAs($awa)
            ->post('/finance/caisse/sessions', ['cash_register_id' => $this->makeRegister('CAISSE-TICKET')->id, 'opening_float' => '0'])
            ->assertForbidden();

        // Le menu suit.
        $this->actingAs($awa)->get('/finance')
            ->assertSee(route('finance.review.index'), false)
            ->assertDontSee(route('finance.ledger.payments'), false);

        // Les rôles de l'hôte n'ont pas bougé.
        $this->assertTrue($awa->fresh()->hasPermissionTo('finance.payments.create'));
    }

    public function test_the_entry_into_the_module_stays_with_the_host(): void
    {
        $awa = $this->cashier();
        $this->hostDeclares([$awa]);
        $this->grant($awa, ['finance.sessions.view']);

        // L'hôte ferme la porte : rien de ce qui est réglé ici ne la rouvre.
        Finance::authorizeAccessUsing(static fn (): bool => false);

        $this->actingAs($awa)->get('/finance/caisse')->assertForbidden();
    }

    public function test_going_back_to_the_host_roles_restores_them(): void
    {
        $awa = $this->cashier();
        $this->hostDeclares([$awa]);
        $this->grant($awa, []);

        $this->assertSame([], app(UserPermissions::class)->for((string) $awa->id));
        $this->actingAs($awa)->get('/finance/caisse')->assertForbidden();

        $this->actingAs($this->admin())
            ->post(route('finance.users.permissions.reset'), ['user_id' => (string) $awa->id])
            ->assertRedirect(route('finance.users.index'))
            ->assertSessionHas('finance_status');

        $this->assertSame(0, UserPermission::count());
        $this->actingAs($awa)->get('/finance/caisse')->assertOk();
    }

    public function test_every_change_is_audited(): void
    {
        $awa = $this->cashier();
        $this->hostDeclares([$awa]);

        $this->grant($awa, ['finance.reports.view']);

        $this->actingAs($this->admin())
            ->post(route('finance.users.permissions.reset'), ['user_id' => (string) $awa->id]);

        $this->assertSame(1, AuditLog::where('event', 'user_permissions_set')->count());
        $this->assertSame(1, AuditLog::where('event', 'user_permissions_reset')->count());
    }

    public function test_nothing_to_change_unknown_users_self_and_reserved_rights_are_refused(): void
    {
        $awa = $this->cashier();
        $this->hostDeclares([$awa]);
        $admin = $this->admin();

        $this->grant($awa, ['finance.reports.view']);

        // Rien à changer.
        $this->actingAs($admin)->from('/finance/utilisateurs')
            ->post(route('finance.users.permissions.store'), ['user_id' => (string) $awa->id, 'permissions' => ['finance.reports.view']])
            ->assertSessionHas('finance_error');

        // Inconnu de l'hôte, sans réglage.
        $this->actingAs($admin)->from('/finance/utilisateurs')
            ->post(route('finance.users.permissions.store'), ['user_id' => '9999', 'permissions' => []])
            ->assertSessionHas('finance_error');

        // Soi-même.
        $this->actingAs($admin)->from('/finance/utilisateurs')
            ->post(route('finance.users.permissions.store'), ['user_id' => (string) $admin->id, 'permissions' => []])
            ->assertSessionHas('finance_error');

        // Une capacité réservée ne se règle pas ici.
        $this->actingAs($admin)->from('/finance/utilisateurs')
            ->post(route('finance.users.permissions.store'), ['user_id' => (string) $awa->id, 'permissions' => ['finance.roles.manage']])
            ->assertSessionHasErrors('permissions.0');

        $this->assertSame(['finance.reports.view'], UserPermission::sole()->permissions);
    }

    public function test_a_user_without_access_anymore_keeps_the_row_visible_to_be_reset(): void
    {
        $awa = $this->cashier();
        $awa->update(['name' => 'Awa Keita']);
        $this->hostDeclares([$awa->fresh()]);
        $this->grant($awa, ['finance.reports.view']);

        $this->hostDeclares([]);

        $this->actingAs($this->admin())
            ->get('/finance/utilisateurs')
            ->assertOk()
            ->assertSee('Awa Keita')
            ->assertSee("N'a plus accès au module", false)
            ->assertSee("Revenir aux rôles de l'application hôte", false);
    }
}
