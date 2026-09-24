<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Access;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Keneya\Pharmacie\Models\UserPermission;
use Keneya\Pharmacie\Support\Actor;
use Keneya\Pharmacie\Support\Rbac;

/**
 * Les capacités réglées dans la pharmacie, personne par personne (écran
 * « Utilisateurs »).
 *
 * L'hôte décide qui ENTRE dans le module (`pharmacie.access`) ; ce qu'on y
 * fait peut se régler ici, sans toucher aux rôles ni aux types de personnel
 * de l'hôte. Quelqu'un dont les capacités sont réglées ici a exactement
 * celles-là : ce qui est coché est accordé, ce qui ne l'est pas est refusé,
 * quels que soient ses rôles ailleurs. Sans réglage, rien ne change, ses
 * rôles décident, comme avant.
 *
 * La décision passe par un `Gate::before` enregistré avant ceux de spatie et
 * de l'hôte (voir PharmacieServiceProvider) : sans quoi un rôle qui accorde
 * une permission l'emporterait sur un refus réglé ici, et l'écran mentirait.
 */
final class UserPermissions
{
    /**
     * Permissions qui ne se règlent jamais ici : l'entrée dans le module
     * appartient à l'hôte, et son administration (paramètres et droits)
     * reste à l'administrateur. La confier depuis cet écran permettrait de
     * s'en attribuer l'accès à soi-même.
     */
    private const RESERVED = ['pharmacie.access', 'pharmacie.settings.manage', 'pharmacie.roles.manage'];

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
     * Décision pour le Gate : vrai ou faux si les capacités de cette personne
     * sont réglées dans la pharmacie, nul sinon (les rôles décident).
     */
    public function decide(Authenticatable $user, string $ability): ?bool
    {
        if (! str_starts_with($ability, 'pharmacie.') || ! in_array($ability, self::grantable(), true)) {
            return null;
        }

        $permissions = $this->for(Actor::id($user));

        return $permissions === null ? null : in_array($ability, $permissions, true);
    }

    /**
     * Les capacités réglées ici pour cette personne, ou nul s'il n'y en a pas.
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

        // L'intersection écarte une capacité disparue d'une version à
        // l'autre : un réglage ancien ne ressuscite pas un droit qui
        // n'existe plus.
        return array_values(array_intersect(self::grantable(), (array) $row->permissions));
    }
}
