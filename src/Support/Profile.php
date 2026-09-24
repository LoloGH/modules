<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Throwable;

/**
 * Ce qu'on affiche d'un utilisateur dans l'en-tête : ses initiales et son
 * profil dans la pharmacie.
 *
 * Purement décoratif. Le modèle utilisateur appartient à l'hôte : il peut ne
 * pas connaître les rôles de spatie, et une lecture qui échoue ne doit jamais
 * casser une page, on retombe alors sur rien du tout.
 */
final class Profile
{
    public static function initials(?Authenticatable $user): string
    {
        $name = $user === null ? null : Actor::name($user);

        if ($name === null) {
            return '?';
        }

        $parts = preg_split('/[\s.@_-]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === []) {
            return '?';
        }

        $first = mb_substr($parts[0], 0, 1);
        $last = count($parts) > 1 ? mb_substr($parts[count($parts) - 1], 0, 1) : '';

        return mb_strtoupper($first.$last);
    }

    /**
     * Le libellé du rôle de cet utilisateur dans la pharmacie, s'il en a un et si
     * l'hôte expose bien les rôles.
     */
    public static function roleLabel(?Authenticatable $user): ?string
    {
        if ($user === null || ! method_exists($user, 'getRoleNames')) {
            return null;
        }

        try {
            $roles = $user->getRoleNames()->all();
        } catch (Throwable) {
            return null;
        }

        foreach (Rbac::roleLabels() as $role => $label) {
            if (in_array($role, $roles, true)) {
                return $label;
            }
        }

        return null;
    }
}
