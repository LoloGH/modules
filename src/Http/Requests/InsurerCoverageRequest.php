<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

/**
 * La couverture d'un organisme : portée, taux par défaut, et pour chaque acte
 * « couvert » et un taux facultatif (`acts[<id>][covered]`, `acts[<id>][rate]`).
 */
final class InsurerCoverageRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'coverage_scope' => ['required', 'in:all,selected'],
            'default_rate' => ['required', 'integer', 'min:1', 'max:100'],
            'acts' => ['array'],
            'acts.*.covered' => ['nullable'],
            'acts.*.rate' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<int, array{covered: bool, rate: ?int}>
     */
    public function actRules(): array
    {
        $rules = [];

        foreach ((array) $this->validated('acts', []) as $actId => $rule) {
            $rate = $rule['rate'] ?? null;
            $rules[(int) $actId] = [
                'covered' => ! empty($rule['covered']),
                'rate' => $rate === null || $rate === '' ? null : (int) $rate,
            ];
        }

        return $rules;
    }
}
