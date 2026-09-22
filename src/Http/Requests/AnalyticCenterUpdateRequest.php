<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Illuminate\Validation\Rule;
use Keneya\FinanceCaisse\Models\AnalyticCenter;

/**
 * Modifier un centre analytique : son nom, son rattachement et sa nature. Le
 * code ne change pas — les rapports et les exports passés s'y réfèrent.
 */
final class AnalyticCenterUpdateRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer', 'exists:finance_analytic_centers,id'],
            'kind' => ['required', Rule::in(array_keys(AnalyticCenter::kindLabels()))],
        ];
    }
}
