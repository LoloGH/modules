<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Feature\Http;

use Illuminate\Support\Facades\Gate;
use Keneya\Pharmacie\Access\UserPermissions;
use Keneya\Pharmacie\Contracts\StaffDirectory;
use Keneya\Pharmacie\Models\AuditLog;
use Keneya\Pharmacie\Models\UserPermission;
use Keneya\Pharmacie\Staff\HostStaff;
use Keneya\Pharmacie\Support\Rbac;
use Keneya\Pharmacie\Tests\Support\FakeStaffDirectory;
use Keneya\Pharmacie\Tests\TestCase;

/**
 * Écran « Utilisateurs » : qui a droit à quoi dans la pharmacie.
 *
 * Ce qui est coché est accordé, ce qui ne l'est pas est refusé, et cela
 * l'emporte sur les rôles de l'application hôte, sinon l'écran mentirait.
 */
class UserPermissionsHttpTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('pharmacie:sync-permissions')->assertSuccessful();
    }

    public function test_the_screen_lists_the_staff_the_host_lets_in(): void
    {
        $preparateur = $this->userWithRole(Rbac::ROLE_DISPENSER);
        $this->declare($preparateur->getKey(), 'Bakary Traoré', 'Préparateur');

        $this->actingAs($this->userWithRole(Rbac::ROLE_ADMIN))
            ->get(route('pharmacie.users.index'))
            ->assertOk()
            ->assertSee('Bakary Traoré')
            ->assertSee('Rôles de l\'application hôte', false);
    }

    public function test_what_is_set_here_wins_over_the_host_roles(): void
    {
        $pharmacien = $this->userWithRole(Rbac::ROLE_PHARMACIST);
        $this->declare($pharmacien->getKey(), 'Aïssata Cissé', 'Pharmacienne');

        // Son rôle lui donne le droit de délivrer.
        $this->assertTrue(Gate::forUser($pharmacien)->allows('pharmacie.dispensing.create'));

        $this->actingAs($this->userWithRole(Rbac::ROLE_ADMIN))
            ->post(route('pharmacie.users.permissions.store'), [
                'user_id' => (string) $pharmacien->getKey(),
                'permissions' => ['pharmacie.products.view', 'pharmacie.stock.view'],
            ])
            ->assertSessionHas('pharmacie_status');

        app(UserPermissions::class)->forget();

        // Ce qui est coché est accordé…
        $this->assertTrue(Gate::forUser($pharmacien)->allows('pharmacie.stock.view'));
        // …et ce qui ne l'est pas est refusé, malgré son rôle.
        $this->assertFalse(Gate::forUser($pharmacien)->allows('pharmacie.dispensing.create'));

        $this->assertSame(1, AuditLog::where('event', 'user_permissions_set')->count());
    }

    public function test_coming_back_to_the_host_roles_removes_the_setting(): void
    {
        $preparateur = $this->userWithRole(Rbac::ROLE_DISPENSER);
        $this->declare($preparateur->getKey(), 'Bakary Traoré');

        $admin = $this->userWithRole(Rbac::ROLE_ADMIN);

        $this->actingAs($admin)->post(route('pharmacie.users.permissions.store'), [
            'user_id' => (string) $preparateur->getKey(),
            'permissions' => [],
        ]);

        app(UserPermissions::class)->forget();
        $this->assertFalse(Gate::forUser($preparateur)->allows('pharmacie.dispensing.create'));

        $this->post(route('pharmacie.users.permissions.reset'), [
            'user_id' => (string) $preparateur->getKey(),
        ])->assertSessionHas('pharmacie_status');

        app(UserPermissions::class)->forget();

        $this->assertSame(0, UserPermission::query()->count());
        // Son rôle décide de nouveau.
        $this->assertTrue(Gate::forUser($preparateur)->allows('pharmacie.dispensing.create'));
    }

    public function test_nobody_sets_their_own_capabilities(): void
    {
        $admin = $this->userWithRole(Rbac::ROLE_ADMIN);
        $this->declare($admin->getKey(), 'Administrateur');

        $this->actingAs($admin)
            ->from(route('pharmacie.users.index'))
            ->post(route('pharmacie.users.permissions.store'), [
                'user_id' => (string) $admin->getKey(),
                'permissions' => ['pharmacie.stock.view'],
            ])
            ->assertSessionHas('pharmacie_error');

        $this->assertSame(0, UserPermission::query()->count());
    }

    public function test_the_door_and_the_administration_are_not_granted_here(): void
    {
        $preparateur = $this->userWithRole(Rbac::ROLE_DISPENSER);
        $this->declare($preparateur->getKey(), 'Bakary Traoré');

        $this->actingAs($this->userWithRole(Rbac::ROLE_ADMIN))
            ->from(route('pharmacie.users.index'))
            ->post(route('pharmacie.users.permissions.store'), [
                'user_id' => (string) $preparateur->getKey(),
                // Se donner les droits d'administration depuis cet écran
                // reviendrait à s'attribuer la clé de l'écran lui-même.
                'permissions' => ['pharmacie.roles.manage'],
            ])
            ->assertSessionHasErrors('permissions.0');

        $this->assertSame(0, UserPermission::query()->count());
        $this->assertNotContains('pharmacie.access', UserPermissions::grantable());
        $this->assertNotContains('pharmacie.settings.manage', UserPermissions::grantable());
    }

    public function test_setting_capabilities_is_a_right_of_its_own(): void
    {
        $this->actingAs($this->userWithRole(Rbac::ROLE_PHARMACIST))
            ->get(route('pharmacie.users.index'))
            ->assertForbidden();
    }

    private function declare(mixed $id, string $name, ?string $function = null): void
    {
        $this->app->instance(StaffDirectory::class, new FakeStaffDirectory([
            new HostStaff((string) $id, $name, $function),
        ]));
    }
}
