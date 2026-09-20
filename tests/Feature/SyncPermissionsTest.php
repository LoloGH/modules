<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Feature;

use Keneya\FinanceCaisse\Support\Rbac;
use Keneya\FinanceCaisse\Tests\TestCase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * `finance:sync-permissions` remplace le seeder : elle doit être sans
 * danger sur une base qui contient déjà des données.
 */
class SyncPermissionsTest extends TestCase
{
    public function test_it_creates_every_permission_and_role_once(): void
    {
        $this->artisan('finance:sync-permissions')->assertSuccessful();

        $this->assertSame(count(Rbac::allPermissions()), Permission::count());
        $this->assertSame(count(Rbac::rolePermissions()), Role::count());

        $this->artisan('finance:sync-permissions')->assertSuccessful();

        $this->assertSame(count(Rbac::allPermissions()), Permission::count());
        $this->assertSame(count(Rbac::rolePermissions()), Role::count());
    }

    public function test_the_administrator_receives_every_permission(): void
    {
        $this->artisan('finance:sync-permissions')->assertSuccessful();

        $admin = Role::findByName(Rbac::ROLE_ADMIN);

        foreach (Rbac::allPermissions() as $permission) {
            $this->assertTrue($admin->hasPermissionTo($permission), $permission);
        }
    }

    public function test_the_cashier_cannot_touch_sensitive_operations(): void
    {
        $this->artisan('finance:sync-permissions')->assertSuccessful();

        $cashier = Role::findByName(Rbac::ROLE_CASHIER);

        $this->assertTrue($cashier->hasPermissionTo('finance.payments.create'));
        $this->assertTrue($cashier->hasPermissionTo('finance.refunds.request'));
        $this->assertTrue($cashier->hasPermissionTo('finance.disbursements.create'));

        foreach ([
            'finance.catalog.manage',
            'finance.tariffs.manage',
            'finance.sessions.validate',
            'finance.registers.manage',
            'finance.payments.cancel',
            'finance.disbursements.cancel',
            'finance.discounts.approve',
            'finance.refunds.approve',
            'finance.audit.view',
            'finance.settings.manage',
            'finance.roles.manage',
        ] as $permission) {
            $this->assertFalse($cashier->hasPermissionTo($permission), $permission);
        }
    }

    public function test_the_accountant_prices_the_catalog_but_does_not_structure_it(): void
    {
        $this->artisan('finance:sync-permissions')->assertSuccessful();

        $accountant = Role::findByName(Rbac::ROLE_ACCOUNTANT);

        // Un prix est une décision de gestion : elle revient au comptable.
        $this->assertTrue($accountant->hasPermissionTo('finance.catalog.view'));
        $this->assertTrue($accountant->hasPermissionTo('finance.tariffs.manage'));

        // La structure du catalogue (centres, actes) reste administrative.
        $this->assertFalse($accountant->hasPermissionTo('finance.catalog.manage'));

        // La direction et le caissier lisent le catalogue, sans plus.
        foreach ([Rbac::ROLE_DIRECTOR, Rbac::ROLE_CASHIER] as $name) {
            $role = Role::findByName($name);

            $this->assertTrue($role->hasPermissionTo('finance.catalog.view'), $name);
            $this->assertFalse($role->hasPermissionTo('finance.catalog.manage'), $name);
            $this->assertFalse($role->hasPermissionTo('finance.tariffs.manage'), $name);
        }
    }

    public function test_an_existing_role_is_never_modified(): void
    {
        Role::create([
            'name' => Rbac::ROLE_CASHIER,
            'guard_name' => (string) config('auth.defaults.guard', 'web'),
        ]);

        $this->artisan('finance:sync-permissions')->assertSuccessful();

        $this->assertCount(0, Role::findByName(Rbac::ROLE_CASHIER)->permissions);
    }

    public function test_a_role_permission_opens_the_module_without_a_host_decision(): void
    {
        $this->artisan('finance:sync-permissions')->assertSuccessful();

        // Aucune capacité accordée par l'hôte : seule la permission du rôle joue.
        $this->grantHostAccess(false);

        $this->actingAs($this->userWithRole(Rbac::ROLE_CASHIER))
            ->get('/finance')
            ->assertOk();

        $this->actingAs($this->makeUser())
            ->get('/finance')
            ->assertForbidden();
    }
}
