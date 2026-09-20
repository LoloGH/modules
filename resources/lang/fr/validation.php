<?php

declare(strict_types=1);

return [

    'messages' => [
        'required' => 'Le champ « :attribute » est obligatoire.',
        'integer' => 'Le champ « :attribute » doit être un nombre entier, sans décimale.',
        'string' => 'Le champ « :attribute » doit être un texte.',
        'date' => 'Le champ « :attribute » doit être une date.',
        'in' => 'La valeur choisie pour « :attribute » n\'est pas autorisée.',
        'exists' => 'La valeur choisie pour « :attribute » n\'existe pas.',
        'unique' => 'La valeur de « :attribute » existe déjà.',
        'min' => [
            'numeric' => 'Le champ « :attribute » doit être au moins :min.',
            'string' => 'Le champ « :attribute » doit avoir au moins :min caractères.',
        ],
        'max' => [
            'numeric' => 'Le champ « :attribute » ne doit pas dépasser :max.',
            'string' => 'Le champ « :attribute » ne doit pas dépasser :max caractères.',
        ],
    ],

    'attributes' => [
        'cash_register_id' => 'caisse',
        'opening_float' => 'fonds initial',
        'payment_method_id' => 'moyen de paiement',
        'amount' => 'montant',
        'reference' => 'référence',
        'patient_id' => 'identifiant du patient',
        'patient_name' => 'nom du patient',
        'description' => 'libellé',
        'reason' => 'motif',
        'beneficiary' => 'bénéficiaire',
        'counted_cash' => 'montant compté',
        'variance_reason' => 'justification de l\'écart',
        'note' => 'note',
        'code' => 'code',
        'name' => 'nom',
        'parent_id' => 'centre parent',
        'kind' => 'type',
        'analytic_center_id' => 'centre analytique',
        'dme_service_id' => 'service DME',
        'label' => 'libellé',
        'effective_from' => 'date d\'application',
    ],

];
