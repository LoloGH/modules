<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class DepositRequest extends FinanceRequest
{
    protected array $moneyFields = ['amount'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'integer', 'exists:finance_payment_methods,id'],
            'amount' => ['required', 'integer', 'min:1'],
            // Identifiant du patient chez l'hôte : aucune règle `exists`, le
            // module ne possède pas le dossier du patient.
            'patient_id' => ['required', 'string', 'max:64'],
            'patient_name' => ['nullable', 'string', 'max:191'],
            'reference' => ['nullable', 'string', 'max:191'],
            'note' => ['nullable', 'string', 'max:191'],
        ];
    }
}
