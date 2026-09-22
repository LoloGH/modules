<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un assureur (ou tiers payant). Son taux par défaut est proposé à la prise
 * en charge ; il se désactive, il ne se supprime pas.
 */
class Insurer extends Model
{
    protected $table = 'finance_insurers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_rate' => 'integer', 'is_active' => 'boolean'];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'insurer_id');
    }

    /**
     * @param  Builder<Insurer>  $query
     * @return Builder<Insurer>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('name');
    }
}
