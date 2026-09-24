<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Les capacités réglées dans la pharmacie pour une personne.
 *
 * Une ligne ici veut dire : « ses droits sont exactement ceux-là ». Pas de
 * ligne veut dire : « ses rôles dans l'application hôte décident ». Les deux
 * situations se lisent à l'écran, elles ne se devinent pas.
 *
 * @property string $user_id
 * @property array<int, string> $permissions
 */
class UserPermission extends Model
{
    protected $table = 'pharmacie_user_permissions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
