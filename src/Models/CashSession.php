<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Session de caisse : ouverte -> clôturée (par le caissier) -> validée (par
 * un autre profil). Une session validée ne bouge plus.
 */
class CashSession extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_VALIDATED = 'validated';

    protected $table = 'finance_cash_sessions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opening_float' => 'integer',
            'expected_cash' => 'integer',
            'counted_cash' => 'integer',
            'variance' => 'integer',
            'totals' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(CashRegister::class, 'cash_register_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'cash_session_id');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class, 'cash_session_id');
    }

    /**
     * @param  Builder<CashSession>  $query
     * @return Builder<CashSession>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /**
     * Combien de tiroirs ce caissier tient ouverts : une session seule est un
     * tiroir, des caisses ouvertes ensemble avec un seul fonds en font un.
     * C'est ce qui se compare à sa limite.
     */
    public static function openDrawersFor(string $cashierId, bool $lock = false): int
    {
        $query = static::query()->open()->where('cashier_id', $cashierId);

        if ($lock) {
            $query->lockForUpdate();
        }

        $sessions = $query->get(['id', 'drawer_key']);

        return $sessions->whereNull('drawer_key')->count()
            + $sessions->whereNotNull('drawer_key')->pluck('drawer_key')->unique()->count();
    }

    /** Ouverte avec d'autres caisses, sur un seul fonds. */
    public function isGrouped(): bool
    {
        return $this->drawer_key !== null;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'Ouverte',
            self::STATUS_CLOSED => 'Clôturée, à valider',
            self::STATUS_VALIDATED => 'Validée',
            default => (string) $this->status,
        };
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    public function isValidated(): bool
    {
        return $this->status === self::STATUS_VALIDATED;
    }
}
