<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class CashierAccessRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Identifiant tel que le module le copie dans les sessions :
            // aucune règle `exists`, la table des utilisateurs est à l'hôte.
            'cashier_id' => ['required', 'string', 'max:64'],
            // Vide = on revient au défaut de l'établissement.
            'max_open_sessions' => ['nullable', 'integer', 'min:1', 'max:10'],
            // Aucune caisse cochée = aucune restriction, donc toutes.
            'registers' => ['nullable', 'array'],
            'registers.*' => ['integer', 'exists:finance_cash_registers,id'],
        ];
    }
}
