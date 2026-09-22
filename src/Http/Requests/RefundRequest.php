<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Illuminate\Validation\Rule;
use Keneya\FinanceCaisse\Models\Refund;

final class RefundRequest extends FinanceRequest
{
    protected array $moneyFields = ['amount'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'source' => ['required', Rule::in(array_keys(Refund::sourceLabels()))],
            'amount' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
            // Ce à quoi le remboursement se rattache : l'action vérifie que
            // l'origine choisie est bien renseignée et qu'elle en a la place.
            'payment_id' => ['nullable', 'integer', 'exists:finance_payments,id'],
            'invoice_id' => ['nullable', 'integer', 'exists:finance_invoices,id'],
            'patient_id' => ['nullable', 'string', 'max:64'],
            'patient_name' => ['nullable', 'string', 'max:191'],
        ];
    }
}
