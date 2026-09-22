<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class PaymentRequest extends FinanceRequest
{
    protected array $moneyFields = ['amount'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'integer', 'exists:finance_payment_methods,id'],
            // Motif de l'encaissement : l'acte du catalogue. Facultatif, tout
            // encaissement ne correspond pas à une prestation tarifée.
            'act_id' => ['nullable', 'integer', 'exists:finance_acts,id'],
            'amount' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:191'],
            'patient_id' => ['nullable', 'string', 'max:64'],
            'patient_name' => ['nullable', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:191'],
            // Venu de la file : la caisse et la visite de l'hôte, références
            // opaques. Les deux ensemble, ou aucune.
            'queue_ref' => ['nullable', 'string', 'max:64', 'required_with:visit_ref'],
            'visit_ref' => ['nullable', 'string', 'max:64', 'required_with:queue_ref'],
        ];
    }
}
