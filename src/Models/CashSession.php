<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

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

    /**
     * Les sessions du même tiroir, celle-ci comprise, dans l'ordre d'ouverture.
     *
     * Un tiroir commun est UN tiroir : un seul fonds, un seul tas d'espèces,
     * un seul comptage. Tout ce qui parle d'argent liquide se raisonne donc
     * sur cet ensemble, et non sur une caisse prise à part. Une session seule
     * se rend elle-même, pour que le reste du code n'ait pas deux cas à tenir.
     *
     * `$lock` verrouille les lignes : la clôture les touche toutes.
     *
     * @return Collection<int, CashSession>
     */
    public function drawerSessions(bool $lock = false): Collection
    {
        if (! $this->isGrouped()) {
            return new Collection([
                $lock ? static::query()->whereKey($this->getKey())->lockForUpdate()->firstOrFail() : $this,
            ]);
        }

        $query = static::query()
            ->where('drawer_key', $this->drawer_key)
            // Un tiroir appartient a un caissier : deux caissiers ne partagent
            // jamais une cle, mais la condition ecrit la regle.
            ->where('cashier_id', $this->cashier_id)
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    /**
     * Le fonds du tiroir : celui que porte la caisse ouverte la première, que
     * les autres partagent. C'est ce que contenait le tiroir à l'ouverture, et
     * c'est la seule lecture juste pour chacune des caisses du groupe.
     */
    public function drawerOpeningFloat(): int
    {
        return (int) $this->drawerSessions()->sum('opening_float');
    }

    /**
     * La caisse qui porte le tiroir : la première ouverte, celle qui a reçu le
     * fonds. C'est elle qui portera l'écart de clôture, pour qu'un écart de
     * tiroir ne se compte qu'une seule fois dans les totaux.
     */
    public function isDrawerReference(): bool
    {
        return ! $this->isGrouped()
            || (int) $this->drawerSessions()->first()?->getKey() === (int) $this->getKey();
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
