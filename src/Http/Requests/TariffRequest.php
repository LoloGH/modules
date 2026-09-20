<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class TariffRequest extends FinanceRequest
{
    protected array $moneyFields = ['amount'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'max:32'],
            'label' => ['nullable', 'string', 'max:191'],
            'amount' => ['required', 'integer', 'min:0'],
            'effective_from' => ['nullable', 'date'],
        ];
    }
}
