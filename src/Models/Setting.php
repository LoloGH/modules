<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un paramètre financier réglé depuis l'application. Sans ligne, le fichier
 * de configuration fait foi.
 *
 * @property string $key
 * @property ?string $value
 */
class Setting extends Model
{
    protected $table = 'finance_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
