<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class DisbursementRequest extends FinanceRequest
{
    protected array $moneyFields = ['amount'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'integer', 'exists:finance_payment_methods,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            'beneficiary' => ['nullable', 'string', 'max:191'],
            'reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}
