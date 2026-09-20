<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class CloseSessionRequest extends FinanceRequest
{
    protected array $moneyFields = ['counted_cash'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'counted_cash' => ['required', 'integer', 'min:0'],
            'variance_reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
