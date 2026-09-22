<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

/**
 * Payer un remboursement approuvé, à la caisse : seul le moyen de sortie
 * reste à choisir, le montant est celui qui a été approuvé.
 */
final class RefundPaymentRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'payment_method_id' => ['required', 'integer', 'exists:finance_payment_methods,id'],
        ];
    }
}
