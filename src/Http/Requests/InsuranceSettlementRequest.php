<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class InsuranceSettlementRequest extends FinanceRequest
{
    protected array $moneyFields = ['amount'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:191'],
            'received_on' => ['nullable', 'date'],
        ];
    }
}
