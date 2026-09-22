<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Actions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Keneya\FinanceCaisse\Audit\Auditor;
use Keneya\FinanceCaisse\Exceptions\FinanceRuleViolation;
use Keneya\FinanceCaisse\Models\Insurer;

/**
 * Règle ce qu'un organisme prend en charge, acte par acte.
 *
 * `$acts` : act_id => ['covered' => bool, 'rate' => ?int]. Stockage :
 *  - portée « tous les actes » : une règle seulement pour un acte exclu
 *    (taux 0) ou à un autre taux que le défaut ;
 *  - portée « actes choisis » : une règle par acte couvert (taux nul = défaut).
 *
 * Les factures déjà émises ne changent pas : leur prise en charge est figée
 * ligne par ligne.
 */
final class SetInsurerCoverage
{
    public function __construct(private readonly Auditor $auditor) {}

    /**
     * @param  array<int, array{covered: bool, rate: ?int}>  $acts
     */
    public function handle(Insurer $insurer, string $scope, int $defaultRate, array $acts, Authenticatable $actor): Insurer
    {
        if (! in_array($scope, [Insurer::SCOPE_ALL, Insurer::SCOPE_SELECTED], true)) {
            throw new FinanceRuleViolation('Portée de couverture inconnue.');
        }

        if ($defaultRate < 1 || $defaultRate > 100) {
            throw new FinanceRuleViolation('Le taux par défaut doit être compris entre 1 et 100 %.');
        }

        $rules = [];

        foreach ($acts as $actId => $rule) {
            $rate = $rule['rate'];

            if ($rate !== null && ($rate < 1 || $rate > 100)) {
                throw new FinanceRuleViolation('Un taux de prise en charge doit être compris entre 1 et 100 %.');
            }

            if ($scope === Insurer::SCOPE_ALL) {
                if (! $rule['covered']) {
                    $rules[$actId] = ['rate' => 0];
                } elseif ($rate !== null && $rate !== $defaultRate) {
                    $rules[$actId] = ['rate' => $rate];
                }
            } elseif ($rule['covered']) {
                $rules[$actId] = ['rate' => $rate === $defaultRate ? null : $rate];
            }
        }

        if ($scope === Insurer::SCOPE_SELECTED && $rules === []) {
            throw new FinanceRuleViolation('Cochez au moins un acte couvert, ou choisissez « tous les actes ».');
        }

        return DB::transaction(function () use ($insurer, $scope, $defaultRate, $rules, $actor): Insurer {
            $before = ['scope' => $insurer->coverage_scope, 'default_rate' => $insurer->default_rate, 'rules' => $insurer->acts()->count()];

            $insurer->update(['coverage_scope' => $scope, 'default_rate' => $defaultRate]);
            $insurer->acts()->sync($rules);

            $this->auditor->record(
                'insurer_coverage_set',
                $insurer,
                sprintf('Couverture de %s : %s, %d %% par défaut, %d règle(s) par acte', $insurer->name, $insurer->scopeLabel(), $defaultRate, count($rules)),
                $before,
                ['scope' => $scope, 'default_rate' => $defaultRate, 'rules' => count($rules)],
                $actor,
            );

            return $insurer->fresh('acts');
        });
    }
}
