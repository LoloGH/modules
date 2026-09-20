<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class CancelMovementRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
