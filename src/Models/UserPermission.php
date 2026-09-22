<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Les capacités d'un utilisateur dans le module, réglées dans Finance.
 *
 * @property int $id
 * @property string $user_id
 * @property ?string $user_name
 * @property list<string> $permissions
 * @property ?string $updated_by_id
 * @property ?string $updated_by_name
 */
class UserPermission extends Model
{
    protected $table = 'finance_user_permissions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }
}
