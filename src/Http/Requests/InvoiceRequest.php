<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Keneya\FinanceCaisse\Actions\CreateInvoice;

/**
 * Nouvelle facture : le patient, puis des lignes « acte, quantité ». Les
 * lignes laissées sans acte sont ignorées.
 */
final class InvoiceRequest extends FinanceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'patient_id' => ['nullable', 'string', 'max:64'],
            'patient_name' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:191'],
            // Prise en charge : un assureur, un taux, et le n° de prise en charge.
            'insurer_id' => ['nullable', 'integer', 'exists:finance_insurers,id'],
            'coverage_rate' => ['nullable', 'required_with:insurer_id', 'integer', 'min:1', 'max:100'],
            'policy_number' => ['nullable', 'string', 'max:64'],
            'lines' => ['required', 'array'],
            'lines.*.act_id' => ['nullable', 'integer', 'exists:finance_acts,id'],
            'lines.*.quantity' => ['nullable', 'integer', 'min:1', 'max:'.CreateInvoice::MAX_QUANTITY],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function messages(): array
    {
        return parent::messages() + [
            'lines.*.quantity.min' => 'La quantité doit être au moins 1.',
            'lines.*.quantity.max' => 'La quantité ne doit pas dépasser '.CreateInvoice::MAX_QUANTITY.'.',
        ];
    }

    /**
     * @return array{insurer_id: int, rate: int, policy_number: ?string}|null
     */
    public function coverage(): ?array
    {
        $insurer = $this->validated('insurer_id');

        return $insurer === null || $insurer === '' ? null : [
            'insurer_id' => (int) $insurer,
            'rate' => (int) $this->validated('coverage_rate'),
            'policy_number' => $this->validated('policy_number'),
        ];
    }

    /**
     * @return list<array{act_id: int, quantity: int}>
     */
    public function invoiceLines(): array
    {
        $lines = [];

        foreach ((array) $this->validated('lines', []) as $line) {
            if (empty($line['act_id'])) {
                continue;
            }

            $lines[] = ['act_id' => (int) $line['act_id'], 'quantity' => (int) ($line['quantity'] ?? 1) ?: 1];
        }

        return $lines;
    }
}
