<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class InsurerRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'unique:finance_insurers,code'],
            'name' => ['required', 'string', 'max:191'],
            'kind' => ['required', 'in:insurance,social_aid'],
            'coverage_scope' => ['required', 'in:all,selected'],
            'default_rate' => ['required', 'integer', 'min:1', 'max:100'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'max:191'],
        ];
    }
}
