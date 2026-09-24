<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Un fournisseur : son identité, ses conditions, et tout ce qu'il a livré.
 *
 * On ne le supprime pas : ses commandes et ses lots resteraient orphelins, et
 * l'on ne saurait plus d'où vient un médicament, ce qui est précisément ce
 * qu'un rappel de lot demande de savoir.
 */
class Supplier extends Model
{
    protected $table = 'pharmacie_suppliers';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'payment_days' => 'integer',
            'lead_time_days' => 'integer',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    public function receptions(): HasMany
    {
        return $this->hasMany(Reception::class, 'supplier_id');
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /**
     * @param  Builder<Supplier>  $query
     * @return Builder<Supplier>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
