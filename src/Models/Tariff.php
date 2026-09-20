<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tarif d'un acte dans un contexte (`kind`). Une ligne est un fait daté :
 * on ne corrige jamais son montant, on en crée une nouvelle et on désactive
 * l'ancienne. Les lignes désactivées sont l'historique des prix.
 *
 * `kind` est une chaîne libre : `standard` aujourd'hui, un code d'assureur
 * quand la tranche « assurance » arrivera.
 */
class Tariff extends Model
{
    public const KIND_STANDARD = 'standard';

    public const KIND_AGREEMENT = 'conventionne';

    protected $table = 'finance_tariffs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'effective_from' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function act(): BelongsTo
    {
        return $this->belongsTo(Act::class, 'act_id');
    }

    /**
     * @param  Builder<Tariff>  $query
     * @return Builder<Tariff>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * « Tarif conventionné » si un libellé a été saisi, sinon le contexte.
     */
    public function displayLabel(): string
    {
        return $this->label !== null && $this->label !== '' ? (string) $this->label : (string) $this->kind;
    }
}
