<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Keneya\Pharmacie\Support\Facility;

/**
 * Un lot : la seule chose qui dise jusqu'à quand un produit est utilisable.
 *
 * Un même médicament vit en plusieurs lots à la fois, de péremptions et de
 * prix différents. Un lot épuisé n'est pas supprimé : son histoire — reçu de
 * qui, servi à qui — doit rester lisible des années après.
 *
 * L'état « périmé » et l'état « épuisé » ne sont pas stockés : ils se lisent
 * de la date et des quantités, pour qu'ils ne puissent jamais mentir.
 *
 * @property int $id
 * @property string $number
 */
class Batch extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DESTROYED = 'destroyed';

    protected $table = 'pharmacie_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'received_on' => 'date',
            'manufactured_on' => 'date',
            'expires_on' => 'date',
            'purchase_price' => 'integer',
            'sale_price' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'batch_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'batch_id');
    }

    /**
     * Ce qu'il reste de ce lot, tous emplacements confondus.
     */
    public function onHand(): int
    {
        return (int) $this->stocks()->sum('quantity');
    }

    public function available(): int
    {
        return (int) $this->stocks()->sum('quantity') - (int) $this->stocks()->sum('reserved');
    }

    public function isExpired(?Carbon $on = null): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(($on ?? now())->startOfDay());
    }

    public function isBlocked(): bool
    {
        return in_array($this->status, [self::STATUS_BLOCKED, self::STATUS_DESTROYED], true);
    }

    /**
     * Un lot délivrable : ni périmé, ni bloqué, ni détruit. Le reste — la
     * quantité disponible — se vérifie au moment de sortir.
     */
    public function isDispensable(?Carbon $on = null): bool
    {
        return ! $this->isBlocked() && ! $this->isExpired($on);
    }

    /**
     * Dans combien de jours il périme : négatif s'il est déjà périmé.
     */
    public function daysToExpiry(?Carbon $on = null): ?int
    {
        return $this->expires_on === null
            ? null
            : (int) ($on ?? now())->startOfDay()->diffInDays($this->expires_on, false);
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->status === self::STATUS_DESTROYED => 'Détruit',
            $this->status === self::STATUS_BLOCKED => 'Bloqué',
            $this->isExpired() => 'Périmé',
            $this->onHand() === 0 => 'Épuisé',
            default => 'Actif',
        };
    }

    public function statusTone(): string
    {
        return match ($this->statusLabel()) {
            'Détruit', 'Bloqué', 'Périmé' => 'danger',
            'Épuisé' => 'off',
            default => 'ok',
        };
    }

    /**
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }

    /**
     * L'ordre FEFO : ce qui périme en premier sort en premier. Un lot sans
     * date passe en dernier — on ne le fait pas passer avant une date connue.
     *
     * @param  Builder<Batch>  $query
     * @return Builder<Batch>
     */
    public function scopeFefo(Builder $query): Builder
    {
        return $query->orderByRaw('expires_on is null')->orderBy('expires_on')->orderBy('id');
    }
}
