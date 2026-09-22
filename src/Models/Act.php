<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Acte ou prestation facturable. Le prix ne vit pas ici : il vit dans les
 * tarifs, pour qu'il change sans réécrire l'acte ni l'historique.
 *
 * Un acte se désactive, il ne se supprime pas : une facture passée s'y
 * réfère.
 */
class Act extends Model
{
    protected $table = 'finance_acts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_consultation_ticket' => 'boolean',
            'dme_service_id' => 'integer',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(AnalyticCenter::class, 'analytic_center_id');
    }

    public function tariffs(): HasMany
    {
        return $this->hasMany(Tariff::class, 'act_id');
    }

    /**
     * Le tarif standard actif, prêt à être chargé avec `with()` pour éviter
     * une requête par ligne dans la liste des actes.
     */
    public function standardTariff(): HasOne
    {
        return $this->hasOne(Tariff::class, 'act_id')
            ->where('kind', Tariff::KIND_STANDARD)
            ->where('is_active', true);
    }

    /**
     * Le tarif actif de cet acte pour un contexte donné, ou null s'il n'a
     * pas encore été fixé. Il n'y en a jamais plus d'un (cf. `SetTariff`).
     */
    public function activeTariff(string $kind = Tariff::KIND_STANDARD): ?Tariff
    {
        return $this->tariffs()->where('kind', $kind)->where('is_active', true)->first();
    }

    /**
     * @param  Builder<Act>  $query
     * @return Builder<Act>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }
}
