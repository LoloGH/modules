<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Socle des formulaires du module : les droits sont contrôlés par les routes
 * (middleware `can:`), les messages sont en français, et les montants
 * acceptent les espaces (« 25 000 »).
 */
abstract class FinanceRequest extends FormRequest
{
    /**
     * Champs de montant : les espaces (y compris insécables) sont retirés.
     *
     * @var list<string>
     */
    protected array $moneyFields = [];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ($this->moneyFields as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $clean[$field] = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value);
            }
        }

        if ($clean !== []) {
            $this->merge($clean);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return (array) trans('finance::validation.messages');
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return (array) trans('finance::validation.attributes');
    }
}
