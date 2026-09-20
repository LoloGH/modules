<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

use Keneya\FinanceCaisse\Models\PaymentMethod;

/**
 * Moyens de paiement créés par `finance:sync-payment-methods`. Simple point
 * de départ : l'établissement les renomme, les désactive ou en ajoute.
 */
final class PaymentMethodDefaults
{
    /**
     * @return list<array{code: string, name: string, kind: string, requires_reference: bool}>
     */
    public static function all(): array
    {
        return [
            ['code' => 'especes', 'name' => 'Espèces', 'kind' => PaymentMethod::KIND_CASH, 'requires_reference' => false],
            ['code' => 'mobile_money', 'name' => 'Mobile Money', 'kind' => PaymentMethod::KIND_MOBILE_MONEY, 'requires_reference' => true],
            ['code' => 'carte', 'name' => 'Carte bancaire', 'kind' => PaymentMethod::KIND_CARD, 'requires_reference' => true],
            ['code' => 'virement', 'name' => 'Virement', 'kind' => PaymentMethod::KIND_TRANSFER, 'requires_reference' => true],
            ['code' => 'cheque', 'name' => 'Chèque', 'kind' => PaymentMethod::KIND_CHEQUE, 'requires_reference' => true],
            ['code' => 'paiement_electronique', 'name' => 'Paiement électronique', 'kind' => PaymentMethod::KIND_ELECTRONIC, 'requires_reference' => true],
            ['code' => 'paiement_en_ligne', 'name' => 'Paiement en ligne', 'kind' => PaymentMethod::KIND_ONLINE, 'requires_reference' => true],
            ['code' => 'compte_patient', 'name' => 'Compte patient', 'kind' => PaymentMethod::KIND_PATIENT_ACCOUNT, 'requires_reference' => false],
            ['code' => 'assurance', 'name' => 'Assurance / tiers payant', 'kind' => PaymentMethod::KIND_INSURANCE, 'requires_reference' => true],
        ];
    }
}
