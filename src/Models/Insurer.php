<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un organisme de prise en charge : assurance ou aide sociale.
 *
 * Il couvre des actes, à un taux :
 *  - portée « tous les actes » (`all`) : chaque acte au taux par défaut, sauf
 *    les règles de `finance_insurer_acts` (un autre taux, ou 0 = exclu) ;
 *  - portée « actes choisis » (`selected`) : seuls les actes listés, à leur
 *    taux (nul = taux par défaut).
 *
 * Il se désactive, il ne se supprime pas.
 */
class Insurer extends Model
{
    public const KIND_INSURANCE = 'insurance';

    public const KIND_SOCIAL_AID = 'social_aid';

    public const SCOPE_ALL = 'all';

    public const SCOPE_SELECTED = 'selected';

    protected $table = 'finance_insurers';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_rate' => 'integer', 'is_active' => 'boolean'];
    }

    /**
     * @return array<string, string>
     */
    public static function kindLabels(): array
    {
        return [self::KIND_INSURANCE => 'Assurance', self::KIND_SOCIAL_AID => 'Aide sociale'];
    }

    public function kindLabel(): string
    {
        return self::kindLabels()[$this->kind] ?? (string) $this->kind;
    }

    public function scopeLabel(): string
    {
        return $this->coverage_scope === self::SCOPE_SELECTED ? 'Actes choisis' : 'Tous les actes';
    }

    /**
     * Les règles de couverture par acte (`pivot->rate` : nul = taux par
     * défaut, 0 = acte exclu).
     */
    public function acts(): BelongsToMany
    {
        return $this->belongsToMany(Act::class, 'finance_insurer_acts', 'insurer_id', 'act_id')
            ->withPivot('rate')
            ->withTimestamps();
    }

    /**
     * Le taux auquel cet organisme prend en charge cet acte ; 0 s'il ne le
     * couvre pas.
     */
    public function rateFor(int $actId): int
    {
        $rule = $this->relationLoaded('acts')
            ? $this->acts->firstWhere('id', $actId)
            : $this->acts()->where('finance_acts.id', $actId)->first();

        if ($rule !== null) {
            return $rule->pivot->rate === null ? (int) $this->default_rate : (int) $rule->pivot->rate;
        }

        return $this->coverage_scope === self::SCOPE_ALL ? (int) $this->default_rate : 0;
    }

    /**
     * Pour les écrans : chaque organisme actif, sa nature et son taux pour
     * chacun des actes donnés (0 = non couvert). Sert au calcul affiché en
     * direct ; le serveur recalcule toujours.
     *
     * @param  iterable<int>  $actIds
     * @return array<int, array{name: string, kind: string, rates: array<int, int>}>
     */
    public static function coverageMap(iterable $actIds): array
    {
        $map = [];

        foreach (self::query()->active()->with('acts')->get() as $insurer) {
            $rates = [];

            foreach ($actIds as $actId) {
                $rates[(int) $actId] = $insurer->rateFor((int) $actId);
            }

            $map[$insurer->id] = ['name' => $insurer->name, 'kind' => $insurer->kindLabel(), 'rates' => $rates];
        }

        return $map;
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
