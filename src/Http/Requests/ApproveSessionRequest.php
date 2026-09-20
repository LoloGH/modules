<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class ApproveSessionRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
