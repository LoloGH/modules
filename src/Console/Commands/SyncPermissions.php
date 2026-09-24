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
 *  - un rôle qui existe déjà n'est JAMAIS modifié en silence : l'administrateur
 *    a pu ajuster sa matrice, on ne la lui reprend pas.
 *
 * Une mise à jour du module peut introduire des permissions qui n'existaient
 * pas quand les rôles ont été créés. La commande dit alors ce qui manquerait
 * à chaque rôle par rapport aux valeurs de départ, et `--grant-missing`
 * l'accorde — sur décision explicite, jamais d'office.
 */
final class SyncPermissions extends Command
{
    protected $signature = 'pharmacie:sync-permissions
                            {--grant-missing : Accorder aux rôles existants les permissions de départ qui leur manquent}';

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
        $granted = [];
        $pending = [];

        foreach (Rbac::rolePermissions() as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => $guard]);

            if ($roleName === Rbac::ROLE_ADMIN) {
                $role->givePermissionTo($permissions);

                continue;
            }

            if ($role->wasRecentlyCreated) {
                $role->givePermissionTo($permissions);
                $rolesCreated[] = $roleName;

                continue;
            }

            // Le rôle existe déjà : on regarde seulement ce que les valeurs
            // de départ lui donneraient et qu'il n'a pas — typiquement les
            // permissions apparues avec une version plus récente du module.
            $missing = array_values(array_diff($permissions, $role->permissions->pluck('name')->all()));

            if ($missing === []) {
                continue;
            }

            if ($this->option('grant-missing')) {
                $role->givePermissionTo($missing);
                $granted[$roleName] = $missing;

                continue;
            }

            $pending[$roleName] = $missing;
        }

        $registrar->forgetCachedPermissions();

        foreach ($granted as $roleName => $permissions) {
            $this->info(sprintf('%s : %d permission(s) accordée(s) : %s', $roleName, count($permissions), implode(', ', $permissions)));
        }

        foreach ($pending as $roleName => $permissions) {
            $this->warn(sprintf(
                '%s : %d permission(s) des valeurs de départ lui manquent : %s',
                $roleName,
                count($permissions),
                implode(', ', $permissions),
            ));
        }

        if ($pending !== []) {
            $this->line("Rien n'a été changé sur ces rôles. Pour les accorder : pharmacie:sync-permissions --grant-missing");
        }

        $this->info(sprintf(
            '%d permission(s) créée(s), %d rôle(s) créé(s)%s.',
            $permissionsCreated,
            count($rolesCreated),
            $rolesCreated === [] ? '' : ' ('.implode(', ', $rolesCreated).')',
        ));

        return self::SUCCESS;
    }
}
