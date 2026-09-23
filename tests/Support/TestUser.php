<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Tests\Support;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Utilisateur de l'hôte de test : le compte, la session et les rôles que
 * l'application hôte fournirait.
 */
class TestUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    protected $guarded = [];
}
