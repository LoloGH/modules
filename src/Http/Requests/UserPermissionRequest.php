<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Http\Requests;

use Illuminate\Validation\Rule;
use Keneya\Pharmacie\Access\UserPermissions;

/**
 * Régler les capacités d'une personne dans la pharmacie.
 */
final class UserPermissionRequest extends PharmacieRequest
{
    protected array $moneyFields = [];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Identifiant tel que la pharmacie le copie : aucune règle
            // `exists`, la table des utilisateurs est à l'hôte.
            'user_id' => ['required', 'string', 'max:64'],
            // Rien de coché = aucune capacité dans le module. C'est un
            // réglage valide : il retire tout sans retirer l'accès.
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
