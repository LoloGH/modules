<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class RegisterRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'unique:finance_cash_registers,code'],
            'name' => ['required', 'string', 'max:191'],
        ];
    }
}
