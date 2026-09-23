<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Illuminate\Validation\Rule;
use Keneya\FinanceCaisse\Models\AnalyticCenter;

final class AnalyticCenterRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'unique:finance_analytic_centers,code'],
            'name' => ['required', 'string', 'max:191'],
            'parent_id' => ['nullable', 'integer', 'exists:finance_analytic_centers,id'],
            'kind' => ['required', Rule::in(array_keys(AnalyticCenter::kindLabels()))],
            'account_code' => ['nullable', 'string', 'max:16'],
        ];
    }
}
