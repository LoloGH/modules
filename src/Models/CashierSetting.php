<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Réglages d'un caissier. Aujourd'hui un seul : le nombre de sessions de
 * caisse qu'il peut tenir ouvertes en même temps.
 *
 * L'établissement fixe un défaut dans la configuration ; cette table ne sert
 * qu'aux exceptions. Pas de ligne, ou `max_open_sessions` nul, veut dire
 * « comme tout le monde ».
 */
class CashierSetting extends Model
{
    protected $table = 'finance_cashier_settings';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['max_open_sessions' => 'integer'];
    }

    /**
     * Le défaut de l'établissement.
     *
     * Une valeur absurde (0, négative, non numérique) fermerait la caisse à
     * tout le monde : on retombe sur 1 plutôt que de bloquer l'exploitation
     * à cause d'une faute de frappe dans la configuration.
     */
    public static function defaultLimit(): int
    {
        $configured = config('finance.cash.max_open_sessions_per_cashier', 1);

        return is_numeric($configured) && (int) $configured >= 1 ? (int) $configured : 1;
    }

    /**
     * La limite effective de ce caissier : sa surcharge si elle existe,
     * sinon le défaut de l'établissement.
     */
    public static function limitFor(string $cashierId): int
    {
        $override = static::query()->where('cashier_id', $cashierId)->value('max_open_sessions');

        return $override === null ? static::defaultLimit() : max(1, (int) $override);
    }
}
