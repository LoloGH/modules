<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class OpenSessionRequest extends FinanceRequest
{
    protected array $moneyFields = ['opening_float'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cash_register_id' => ['required', 'integer', 'exists:finance_cash_registers,id'],
            'opening_float' => ['required', 'integer', 'min:0'],
        ];
    }
}
