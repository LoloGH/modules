<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class PaymentRequest extends FinanceRequest
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
            'reference' => ['nullable', 'string', 'max:191'],
            'patient_id' => ['nullable', 'string', 'max:64'],
            'patient_name' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:191'],
        ];
    }
}
