<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Keneya\Pharmacie\Support\Facility;

/**
 * Ce qu'un organisme couvre sur un produit, et a quel taux.
 *
 * Le nom de l'organisme est recopie : si la caisse le renomme, la regle reste
 * lisible telle qu'elle a ete posee.
 */
class ProductCoverage extends Model
{
    protected $table = 'pharmacie_product_coverages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate' => 'integer'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /**
     * @param  Builder<ProductCoverage>  $query
     * @return Builder<ProductCoverage>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }

    /**
     * @param  Builder<ProductCoverage>  $query
     * @return Builder<ProductCoverage>
     */
    public function scopeForInsurer(Builder $query, string $insurerRef): Builder
    {
        return $query->where('insurer_ref', $insurerRef);
    }
}
