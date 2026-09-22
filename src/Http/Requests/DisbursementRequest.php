<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Illuminate\Validation\Rule;
use Keneya\FinanceCaisse\Models\Disbursement;

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
            'category' => ['nullable', 'string', Rule::in(array_keys(Disbursement::categories()))],
            'beneficiary' => ['nullable', 'string', 'max:191'],
            'reference' => ['nullable', 'string', 'max:191'],
        ];
    }
}
