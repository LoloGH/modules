<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Keneya\Pharmacie\Support\Facility;

/**
 * Un produit du catalogue : médicament, consommable, dispositif ou article
 * d'hygiène.
 *
 * Le produit dit ce qu'on référence ; il ne dit ni combien il en reste, ni
 * jusqu'à quand il est utilisable, cela appartient aux lots.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 */
class Product extends Model
{
    public const KIND_MEDICINE = 'medicine';

    public const KIND_CONSUMABLE = 'consumable';

    public const KIND_DEVICE = 'device';

    public const KIND_HYGIENE = 'hygiene';

    public const KIND_OTHER = 'other';

    protected $table = 'pharmacie_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_generic' => 'boolean',
            'is_active' => 'boolean',
            'is_controlled' => 'boolean',
            'min_threshold' => 'integer',
            'max_threshold' => 'integer',
            'sale_price' => 'integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [
            self::KIND_MEDICINE => 'Médicament',
            self::KIND_CONSUMABLE => 'Consommable médical',
            self::KIND_DEVICE => 'Dispositif médical',
            self::KIND_HYGIENE => 'Produit d\'hygiène',
            self::KIND_OTHER => 'Autre article',
        ];
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? (string) $this->kind;
    }

    /**
     * Ce qu'on lit d'un produit en une ligne : « Amoxicilline 500 mg,
     * gélule ». C'est ce qui évite de délivrer le mauvais dosage.
     */
    public function label(): string
    {
        return trim(implode(' ', array_filter([
            $this->name,
            $this->dosage,
            $this->form === null ? null : '('.$this->form.')',
        ])));
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /**
     * L'établissement regardé : tant qu'il n'y en a qu'un, la portée ne se
     * voit pas ; le jour où il y en a deux, rien n'est à réécrire.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeOfFacility(Builder $query, ?int $facilityId = null): Builder
    {
        return $query->where('facility_id', $facilityId ?? Facility::current());
    }

    /**
     * La recherche du comptoir : un nom, une DCI, une marque, un code ou un
     * code-barres. On tape ce qu'on a sous les yeux.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopeSearch(Builder $query, ?string $terms): Builder
    {
        if ($terms === null || $terms === '') {
            return $query;
        }

        $like = '%'.$terms.'%';

        return $query->where(fn (Builder $where) => $where
            ->where('name', 'like', $like)
            ->orWhere('dci', 'like', $like)
            ->orWhere('brand_name', 'like', $like)
            ->orWhere('code', 'like', $like)
            ->orWhere('barcode', 'like', $like)
            ->orWhere('therapeutic_class', 'like', $like));
    }
}
