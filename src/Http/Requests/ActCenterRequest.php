<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

/**
 * Rattacher un acte à un centre analytique, ou l'en détacher.
 */
final class ActCenterRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'analytic_center_id' => ['nullable', 'integer', 'exists:finance_analytic_centers,id'],
        ];
    }
}
