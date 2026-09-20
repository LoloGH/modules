<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Tests\Unit;

use Keneya\FinanceCaisse\Support\Rbac;
use Keneya\FinanceCaisse\Tests\TestCase;
use Spatie\Permission\Models\Role;

class RbacTest extends TestCase
{
    public function test_every_permission_is_prefixed_and_unique(): void
    {
        $all = Rbac::allPermissions();

        $this->assertSame($all, array_values(array_unique($all)));

        foreach ($all as $permission) {
            $this->assertStringStartsWith('finance.', $permission);
        }
    }

    public function test_every_starter_permission_is_declared(): void
    {
        $all = Rbac::allPermissions();

        foreach (Rbac::rolePermissions() as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains($permission, $all, "{$role} : {$permission}");
            }
        }
    }

    public function test_the_locked_admin_permissions_are_declared(): void
    {
        foreach (Rbac::lockedAdminPermissions() as $permission) {
            $this->assertContains($permission, Rbac::allPermissions());
        }
    }

    public function test_the_access_ability_is_a_declared_permission(): void
    {
        $this->assertContains((string) config('finance.access.ability'), Rbac::allPermissions());
    }

    public function test_host_roles_translate_and_drop_missing_roles(): void
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        config()->set('finance.roles', [Rbac::ROLE_CASHIER => 'cashier']);
        Role::create(['name' => 'cashier', 'guard_name' => $guard]);

        $this->assertSame(['cashier'], Rbac::hostRoles(Rbac::ROLE_CASHIER));

        // Non déclaré et absent de la base : liste vide, jamais une erreur.
        $this->assertSame([], Rbac::hostRoles(Rbac::ROLE_ACCOUNTANT));

        // Non déclaré mais présent : il se traduit par lui-même.
        Role::create(['name' => Rbac::ROLE_ACCOUNTANT, 'guard_name' => $guard]);
        $this->assertSame([Rbac::ROLE_ACCOUNTANT], Rbac::hostRoles(Rbac::ROLE_ACCOUNTANT));
    }
}
