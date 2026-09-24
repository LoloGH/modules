<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Keneya\Pharmacie\Support\Facility;

/**
 * Une perte ou une destruction : ce qui sort du stock sans avoir ete
 * delivre.
 *
 * Toujours avec un motif, toujours avec un auteur, et une valeur, car une
 * perte coute quelque chose, et ce quelque chose doit se lire dans les
 * rapports.
 */
class Loss extends Model
{
    public const KIND_EXPIRED = 'perime';

    public const KIND_BROKEN = 'casse';

    public const KIND_THEFT = 'vol';

    public const KIND_DAMAGED = 'deterioration';

    public const KIND_OTHER = 'autre';

    protected $table = 'pharmacie_losses';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'value' => 'integer', 'destroyed' => 'boolean'];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            self::KIND_EXPIRED => 'Perime',
            self::KIND_BROKEN => 'Casse',
            self::KIND_THEFT => 'Vol',
            self::KIND_DAMAGED => 'Deterioration',
            self::KIND_OTHER => 'Autre',
        ];
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? (string) $this->kind;
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class, 'batch_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    /**
     * @param  Builder<Loss>  $query
     * @return Builder<Loss>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
