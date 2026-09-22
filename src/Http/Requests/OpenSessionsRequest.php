<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

/**
 * Plusieurs caisses cochées, un fonds initial par caisse (`floats[<id>]`).
 */
final class OpenSessionsRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'registers' => ['required', 'array', 'min:1'],
            'registers.*' => ['integer', 'distinct', 'exists:finance_cash_registers,id'],
            'floats' => ['array'],
            'floats.*' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'registers.required' => 'Cochez au moins une caisse à ouvrir.',
            'floats.*.integer' => 'Le fonds initial de chaque caisse doit être un nombre entier, sans décimale.',
            'floats.*.min' => 'Le fonds initial ne peut pas être négatif.',
        ];
    }

    /**
     * Les fonds acceptent les espaces (« 15 000 »), comme partout.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $floats = $this->input('floats');

        if (is_array($floats)) {
            $this->merge(['floats' => array_map(
                static fn ($value) => is_string($value) ? preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value) : $value,
                $floats,
            )]);
        }
    }

    /**
     * Le fonds de chaque caisse cochée (vide = 0), dans l'ordre coché.
     *
     * @return array<int, int>
     */
    public function floatsByRegister(): array
    {
        $floats = (array) $this->validated('floats', []);
        $result = [];

        foreach ((array) $this->validated('registers') as $id) {
            $value = $floats[$id] ?? $floats[(string) $id] ?? null;
            $result[(int) $id] = $value === null || $value === '' ? 0 : (int) $value;
        }

        return $result;
    }
}
