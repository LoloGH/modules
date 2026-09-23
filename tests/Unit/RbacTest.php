<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Unit;

use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Le référentiel des droits : toutes les permissions sont préfixées, et la
 * séparation des tâches tient dès la déclaration des rôles.
 */
class RbacTest extends TestCase
{
    public function test_every_permission_is_prefixed_and_unique(): void
    {
        $permissions = Rbac::allPermissions();

        $this->assertSame($permissions, array_unique($permissions));

        foreach ($permissions as $permission) {
            $this->assertStringStartsWith('pharmacie.', $permission);
        }
    }

    public function test_the_roles_only_carry_declared_permissions(): void
    {
        $known = Rbac::allPermissions();

        foreach (Rbac::rolePermissions() as $role => $permissions) {
            foreach ($permissions as $permission) {
                $this->assertContains($permission, $known, "{$role} porte une permission inconnue : {$permission}");
            }
        }
    }

    public function test_who_dispenses_does_not_validate_the_stock(): void
    {
        $dispenser = Rbac::rolePermissions()[Rbac::ROLE_DISPENSER];

        $this->assertContains('pharmacie.dispensing.create', $dispenser);
        $this->assertNotContains('pharmacie.inventory.validate', $dispenser);
        $this->assertNotContains('pharmacie.stock.adjust', $dispenser);

        // Et le magasinier, qui compte et reçoit, ne délivre pas.
        $storekeeper = Rbac::rolePermissions()[Rbac::ROLE_STOREKEEPER];

        $this->assertContains('pharmacie.stock.receive', $storekeeper);
        $this->assertNotContains('pharmacie.dispensing.create', $storekeeper);
    }

    public function test_the_admin_keeps_what_opens_the_rights_screen(): void
    {
        $admin = Rbac::rolePermissions()[Rbac::ROLE_ADMIN];

        foreach (Rbac::lockedAdminPermissions() as $permission) {
            $this->assertContains($permission, $admin);
        }
    }
}
