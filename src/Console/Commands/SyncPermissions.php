<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Console\Commands;

use Illuminate\Console\Command;
use Keneya\Pharmacie\Support\Rbac;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crée les permissions et les rôles du module, sans jamais lancer un seeder.
 *
 * Idempotente et sans danger sur une base de production : on ne lance pas
 * `db:seed` sur une base qui contient des données.
 *
 *  - les permissions manquantes sont créées ;
 *  - l'administrateur reçoit toujours toutes les permissions Pharmacie ;
 *  - un rôle qui n'existe pas encore est créé avec ses permissions de départ ;
 *  - un rôle qui existe déjà n'est JAMAIS modifié : l'administrateur a pu
 *    ajuster sa matrice, on ne la lui reprend pas.
 */
final class SyncPermissions extends Command
{
    protected $signature = 'pharmacie:sync-permissions';

    protected $description = 'Crée les permissions et rôles du module Pharmacie (idempotent, sans seeder)';

    public function handle(PermissionRegistrar $registrar): int
    {
        $guard = (string) config('auth.defaults.guard', 'web');

        $permissionsCreated = 0;

        foreach (Rbac::allPermissions() as $name) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => $guard]);

            if ($permission->wasRecentlyCreated) {
                $permissionsCreated++;
            }
        }

        $rolesCreated = [];

        foreach (Rbac::rolePermissions() as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);

            if ($roleName === Rbac::ROLE_ADMIN) {
                $role->givePermissionTo($permissions);

                continue;
            }

            if ($role->wasRecentlyCreated) {
                $role->givePermissionTo($permissions);
                $rolesCreated[] = $roleName;
            }
        }

        $registrar->forgetCachedPermissions();

        $this->info(sprintf(
            '%d permission(s) créée(s), %d rôle(s) créé(s)%s.',
            $permissionsCreated,
            count($rolesCreated),
            $rolesCreated === [] ? '' : ' ('.implode(', ', $rolesCreated).')',
        ));

        return self::SUCCESS;
    }
}
