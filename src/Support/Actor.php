<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Identité d'un utilisateur telle qu'on la copie dans les écritures : un
 * identifiant en chaîne (valable quel que soit le type de clé de l'hôte) et
 * un nom, conservés même si le compte disparaît.
 */
final class Actor
{
    public static function id(Authenticatable $user): string
    {
        return (string) $user->getAuthIdentifier();
    }

    public static function name(Authenticatable $user): ?string
    {
        $label = data_get($user, 'name') ?? data_get($user, 'email');

        return is_string($label) ? $label : null;
    }
}
