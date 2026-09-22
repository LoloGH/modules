<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Centre analytique : l'unité qui porte les produits et les charges
 * (Laboratoire, Imagerie, Maternité…). Sert à répondre plus tard à
 * « combien rapporte tel service ».
 *
 * La hiérarchie est libre (Pôle -> Service -> Activité) : un centre a un
 * parent facultatif. Un centre se désactive, il ne se supprime pas.
 */
class AnalyticCenter extends Model
{
    public const KIND_REVENUE = 'revenue';

    public const KIND_COST = 'cost';

    public const KIND_BOTH = 'both';

    protected $table = 'finance_analytic_centers';

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
            self::KIND_REVENUE => 'Produits',
            self::KIND_COST => 'Charges',
            self::KIND_BOTH => 'Produits et charges',
        ];
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? (string) $this->kind;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function acts(): HasMany
    {
        return $this->hasMany(Act::class, 'analytic_center_id');
    }

    /**
     * @param  Builder<AnalyticCenter>  $query
     * @return Builder<AnalyticCenter>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }

    /**
     * Les centres qui peuvent porter une recette (un acte, un encaissement).
     *
     * @param  Builder<AnalyticCenter>  $query
     * @return Builder<AnalyticCenter>
     */
    public function scopeForRevenue(Builder $query): Builder
    {
        return $query->active()->whereIn('kind', [self::KIND_REVENUE, self::KIND_BOTH]);
    }

    /**
     * Les centres qui peuvent porter une charge (un décaissement).
     *
     * @param  Builder<AnalyticCenter>  $query
     * @return Builder<AnalyticCenter>
     */
    public function scopeForCharges(Builder $query): Builder
    {
        return $query->active()->whereIn('kind', [self::KIND_COST, self::KIND_BOTH]);
    }

    public function acceptsRevenue(): bool
    {
        return in_array($this->kind, [self::KIND_REVENUE, self::KIND_BOTH], true);
    }

    public function acceptsCharges(): bool
    {
        return in_array($this->kind, [self::KIND_COST, self::KIND_BOTH], true);
    }
}
