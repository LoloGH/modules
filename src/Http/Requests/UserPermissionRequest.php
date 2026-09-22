<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Http\Requests;

use Illuminate\Validation\Rule;
use Keneya\FinanceCaisse\Access\UserPermissions;

final class UserPermissionRequest extends FinanceRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Identifiant tel que Finance le copie : aucune règle `exists`,
            // la table des utilisateurs est à l'hôte.
            'user_id' => ['required', 'string', 'max:64'],
            // Rien de coché = aucune capacité dans le module.
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(UserPermissions::grantable())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages() + ['permissions.*.in' => 'Cette capacité ne se règle pas ici.'];
    }
}
