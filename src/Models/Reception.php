<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Une réception : le contrôle de ce qui arrive, et la pièce qui l'atteste.
 *
 * Elle ne se modifie pas après coup. Une erreur constatée plus tard se
 * corrige par un ajustement de stock motivé, qui reste lisible à côté d'elle.
 */
class Reception extends Model
{
    protected $table = 'pharmacie_receptions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'total' => 'integer'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceptionLine::class, 'reception_id');
    }

    /**
     * @param  Builder<Reception>  $query
     * @return Builder<Reception>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
