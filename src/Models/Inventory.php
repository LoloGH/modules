<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Un inventaire : ce que le systeme croyait avoir, et ce qu'on a compte.
 *
 * L'ecart ne corrige rien tant que quelqu'un d'autre ne l'a pas valide :
 * celui qui compte n'est pas celui qui decide que le stock avait tort.
 */
class Inventory extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_VALIDATED = 'validated';

    public const STATUS_CANCELLED = 'cancelled';

    public const SCOPE_FULL = 'full';

    public const SCOPE_CATEGORY = 'category';

    public const SCOPE_PRODUCT = 'product';

    protected $table = 'pharmacie_inventories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['validated_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    /**
     * @return array<string, string>
     */
    public static function scopeLabels(): array
    {
        return [
            self::SCOPE_FULL => 'Inventaire complet',
            self::SCOPE_CATEGORY => 'Par categorie',
            self::SCOPE_PRODUCT => 'Un produit',
        ];
    }

    public function scopeLabel(): string
    {
        return self::scopeLabels()[$this->scope] ?? (string) $this->scope;
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_VALIDATED => 'Valide',
            self::STATUS_CANCELLED => 'Abandonne',
            default => 'En cours',
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_VALIDATED => 'ok',
            self::STATUS_CANCELLED => 'off',
            default => 'warn',
        };
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryLine::class, 'inventory_id');
    }

    /** Le nombre de lignes dont le compte ne tombe pas juste. */
    public function gapCount(): int
    {
        return $this->lines->filter(fn (InventoryLine $line): bool => $line->gap !== 0)->count();
    }

    /**
     * @param  Builder<Inventory>  $query
     * @return Builder<Inventory>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
