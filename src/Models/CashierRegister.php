<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une caisse qu'un caissier a le droit d'ouvrir.
 *
 * L'absence totale de ligne pour un caissier vaut « toutes les caisses ».
 * C'est ce qui rend le réglage sans danger : on restreint quand on en a
 * besoin, et ne rien régler laisse l'établissement fonctionner comme avant.
 */
class CashierRegister extends Model
{
    protected $table = 'finance_cashier_registers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cash_register_id' => 'integer'];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    /**
     * Les caisses auxquelles ce caissier est affecté.
     *
     * Une liste vide veut dire « aucune restriction », pas « aucune caisse » :
     * l'appelant doit passer par `allows()` plutôt que d'interpréter ce
     * tableau lui-même.
     *
     * @return list<int>
     */
    public static function assignedIdsFor(string $cashierId): array
    {
        return static::query()
            ->where('cashier_id', $cashierId)
            ->pluck('cash_register_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Ce caissier a-t-il le droit d'ouvrir cette caisse ?
     */
    public static function allows(string $cashierId, int $registerId): bool
    {
        $assigned = static::assignedIdsFor($cashierId);

        return $assigned === [] || in_array($registerId, $assigned, true);
    }
}
