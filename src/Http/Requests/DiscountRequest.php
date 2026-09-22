<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class DiscountRequest extends FinanceRequest
{
    protected array $moneyFields = ['amount'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'invoice_id' => ['required', 'integer', 'exists:finance_invoices,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
