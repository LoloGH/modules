<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Un transfert d'un emplacement à un autre.
 *
 * Quatre états, quatre responsabilités : celui qui demande n'est pas celui
 * qui valide, ni celui qui sort les unités, ni celui qui les reçoit.
 *
 * Entre « envoyé » et « reçu », les unités sont **en transit** : sorties de
 * l'emplacement d'origine, pas encore entrées à destination.
 */
class Transfer extends Model
{
    public const STATUS_REQUESTED = 'requested';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_SENT = 'sent';

    public const STATUS_RECEIVED = 'received';

    public const STATUS_REFUSED = 'refused';

    protected $table = 'pharmacie_transfers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_REQUESTED => 'Demandé',
            self::STATUS_APPROVED => 'Validé',
            self::STATUS_SENT => 'En transit',
            self::STATUS_RECEIVED => 'Reçu',
            self::STATUS_REFUSED => 'Refusé',
        ];
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? (string) $this->status;
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_RECEIVED => 'ok',
            self::STATUS_SENT => 'info',
            self::STATUS_REFUSED => 'danger',
            self::STATUS_APPROVED => 'warn',
            default => 'off',
        };
    }

    public function isInTransit(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'from_location_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'to_location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(TransferLine::class, 'transfer_id');
    }

    /**
     * @param  Builder<Transfer>  $query
     * @return Builder<Transfer>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
