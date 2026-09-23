<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Keneya\Pharmacie\Services\StockLedger;
use Keneya\Pharmacie\Support\Facility;

/**
 * Ce qu'il y a, pour un lot et un emplacement donnés.
 *
 * Cette ligne est une commodité de lecture : elle se recalcule toujours des
 * mouvements (voir {@see StockLedger}). Elle ne se
 * modifie que sous verrou, et jamais à la main.
 */
class Stock extends Model
{
    protected $table = 'pharmacie_stocks';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'reserved' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
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
     * Ce qu'on peut réellement servir : le physique, moins ce qui est déjà
     * promis.
     */
    public function available(): int
    {
        return max(0, (int) $this->quantity - (int) $this->reserved);
    }

    /**
     * @param  Builder<Stock>  $query
     * @return Builder<Stock>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }

    /**
     * @param  Builder<Stock>  $query
     * @return Builder<Stock>
     */
    public function scopeInStock(Builder $query): Builder
    {
        return $query->where('quantity', '>', 0);
    }
}
