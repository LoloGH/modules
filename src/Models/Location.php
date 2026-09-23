<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Un emplacement de stock : pharmacie centrale, réserve, comptoir, urgences,
 * maternité, bloc, hospitalisation…
 *
 * Savoir « combien on en a » ne suffit pas : il faut savoir **où**. C'est ce
 * qui permet les transferts, les inventaires partiels et le rappel d'un lot.
 */
class Location extends Model
{
    public const KIND_PHARMACY = 'pharmacy';

    public const KIND_STORE = 'store';

    public const KIND_COUNTER = 'counter';

    public const KIND_WARD = 'ward';

    protected $table = 'pharmacie_locations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            self::KIND_PHARMACY => 'Pharmacie',
            self::KIND_STORE => 'Réserve',
            self::KIND_COUNTER => 'Comptoir',
            self::KIND_WARD => 'Service de soins',
        ];
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? (string) $this->kind;
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class, 'location_id');
    }

    /**
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /**
     * @param  Builder<Location>  $query
     * @return Builder<Location>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }

    /**
     * L'emplacement par défaut : celui où l'on reçoit et d'où l'on sert tant
     * que l'établissement n'en a déclaré qu'un.
     */
    public static function default(?int $facilityId = null): ?self
    {
        return self::query()->ofFacility($facilityId)->active()->orderBy('id')->first();
    }
}
