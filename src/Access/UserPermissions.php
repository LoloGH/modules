<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Keneya\FinanceCaisse\Models\UserPermission;
use Keneya\FinanceCaisse\Support\Actor;
use Keneya\FinanceCaisse\Support\Rbac;

/**
 * Les capacités réglées dans Finance, utilisateur par utilisateur (écran
 * « Utilisateurs »).
 *
 * L'hôte décide qui ENTRE dans le module (`finance.access`) ; ce qu'on y
 * fait peut se régler ici, sans toucher aux rôles de l'hôte. Un utilisateur
 * dont les capacités sont réglées dans Finance a exactement celles-là : ce
 * qui est coché est accordé, ce qui ne l'est pas est refusé, quels que soient
 * ses rôles chez l'hôte. Sans réglage, rien ne change : ses rôles décident.
 *
 * La décision passe par un `Gate::before` enregistré avant ceux de spatie et
 * de l'hôte (voir FinanceServiceProvider) : sans quoi un rôle qui accorde une
 * permission l'emporterait sur un refus réglé ici.
 */
final class UserPermissions
{
    /**
     * Permissions qui ne se règlent jamais ici : l'entrée dans le module
     * appartient à l'hôte, et l'administration du module (paramètres, droits)
     * reste à l'administrateur, la confier depuis cet écran permettrait de
     * s'en attribuer l'accès.
     */
    private const RESERVED = ['finance.access', 'finance.settings.manage', 'finance.roles.manage'];

    /** @var array<string, list<string>|null> capacités lues, par utilisateur */
    private array $loaded = [];

    /**
     * Les capacités qui se règlent ici, groupées comme dans Rbac.
     *
     * @return array<string, array<string, string>>
     */
    public static function grantableGroups(): array
    {
        $groups = [];

        foreach (Rbac::permissionGroups() as $group => $permissions) {
            $kept = array_diff_key($permissions, array_flip(self::RESERVED));

            if ($kept !== []) {
                $groups[$group] = $kept;
            }
        }

        return $groups;
    }

    /**
     * @return list<string>
     */
    public static function grantable(): array
    {
        return array_merge(...array_map(
            static fn (array $group): array => array_keys($group),
            array_values(self::grantableGroups()),
        ));
    }

    /**
     * Décision pour le Gate : vrai ou faux si les capacités de cet
     * utilisateur sont réglées dans Finance, nul sinon (les rôles décident).
     */
    public function decide(Authenticatable $user, string $ability): ?bool
    {
        if (! str_starts_with($ability, 'finance.') || ! in_array($ability, self::grantable(), true)) {
            return null;
        }

        $permissions = $this->for(Actor::id($user));

        return $permissions === null ? null : in_array($ability, $permissions, true);
    }

    /**
     * Les capacités réglées dans Finance pour cet utilisateur, ou nul s'il
     * n'en a pas.
     *
     * @return list<string>|null
     */
    public function for(string $userId): ?array
    {
        if (! array_key_exists($userId, $this->loaded)) {
            $this->loaded[$userId] = $this->read($userId);
        }

        return $this->loaded[$userId];
    }

    /**
     * À appeler après un changement : la décision suivante relit la base.
     */
    public function forget(?string $userId = null): void
    {
        if ($userId === null) {
            $this->loaded = [];

            return;
        }

        unset($this->loaded[$userId]);
    }

    /**
     * @return list<string>|null
     */
    private function read(string $userId): ?array
    {
        try {
            $row = UserPermission::query()->where('user_id', $userId)->first();
        } catch (QueryException) {
            // Migration pas encore jouée chez l'hôte : personne n'a de
            // réglage, les rôles décident comme avant.
            return null;
        }

        if ($row === null) {
            return null;
        }

        return array_values(array_intersect(self::grantable(), (array) $row->permissions));
    }
}
