<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

final class ActRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:32', 'unique:finance_acts,code'],
            'name' => ['required', 'string', 'max:191'],
            'analytic_center_id' => ['nullable', 'integer', 'exists:finance_analytic_centers,id'],
            // Lien mou vers `dme_services` de l'hôte : aucune règle `exists`,
            // le module ne suppose pas que DME soit installé.
            'dme_service_id' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:191'],
        ];
    }
}
