<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * Plusieurs caisses cochées, ouvertes groupées (un seul fonds,
 * `opening_float`) ou séparées (un fonds par caisse, `floats[<id>]`).
 */
final class OpenSessionsRequest extends FinanceRequest
{
    public const MODE_GROUPED = 'groupees';

    public const MODE_SEPARATE = 'separees';

    protected array $moneyFields = ['opening_float'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'mode' => ['required', Rule::in([self::MODE_GROUPED, self::MODE_SEPARATE])],
            'registers' => ['required', 'array', 'min:1'],
            'registers.*' => ['integer', 'distinct', 'exists:finance_cash_registers,id'],
            'opening_float' => [Rule::requiredIf($this->input('mode') === self::MODE_GROUPED), 'nullable', 'integer', 'min:0'],
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

    public function isGrouped(): bool
    {
        return $this->validated('mode') === self::MODE_GROUPED;
    }

    /**
     * Les caisses cochées, dans l'ordre de l'écran.
     *
     * @return list<int>
     */
    public function registerIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('registers')));
    }

    /**
     * Le fonds de chaque caisse cochée (vide = 0), pour l'ouverture séparée.
     *
     * @return array<int, int>
     */
    public function floatsByRegister(): array
    {
        $floats = (array) $this->validated('floats', []);
        $result = [];

        foreach ($this->registerIds() as $id) {
            $value = $floats[$id] ?? $floats[(string) $id] ?? null;
            $result[$id] = $value === null || $value === '' ? 0 : (int) $value;
        }

        return $result;
    }
}
