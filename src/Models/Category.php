<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Keneya\Pharmacie\Support\Facility;

/**
 * Une catégorie du catalogue : antibiotiques, antipaludiques, pansements…
 *
 * Elle sert à ranger et à filtrer. Elle se désactive, elle ne se supprime pas :
 * les produits qui s'y rattachent, et les mouvements qui les suivent, doivent
 * rester lisibles.
 */
class Category extends Model
{
    protected $table = 'pharmacie_categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /**
     * @param  Builder<Category>  $query
     * @return Builder<Category>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }
}
