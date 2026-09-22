<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

/**
 * Plusieurs caisses cochées, un seul fonds initial.
 */
final class OpenSessionsRequest extends FinanceRequest
{
    protected array $moneyFields = ['opening_float'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registers' => ['required', 'array', 'min:1'],
            'registers.*' => ['integer', 'distinct', 'exists:finance_cash_registers,id'],
            'opening_float' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'registers.required' => 'Cochez au moins une caisse à ouvrir.',
        ];
    }

    /**
     * Les caisses cochées, dans l'ordre de l'écran : la première porte le fonds.
     *
     * @return list<int>
     */
    public function registerIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('registers')));
    }
}
