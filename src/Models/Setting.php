<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un réglage de la pharmacie, posé depuis l'application. Sans ligne, le
 * fichier de configuration fait foi.
 *
 * @property string $key
 * @property ?string $value
 */
class Setting extends Model
{
    protected $table = 'pharmacie_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
