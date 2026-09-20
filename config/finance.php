<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration du module Keneya-Finance (Finance, Caisse, Facturation)
|--------------------------------------------------------------------------
|
| Fichier publiable (php artisan vendor:publish --tag=finance-config).
| Il regroupe ce que l'application hôte peut avoir besoin d'ajuster :
| montage des routes, autorisation d'accès accordée par l'hôte, devise,
| identifiants métier.
|
| Aucune valeur métier ne doit être codée en dur ailleurs dans le module.
|
*/

return [

    'version' => '0.3.0',

    /*
    | Établissement exploitant l'application. Mêmes variables d'environnement
    | que le module DME : un seul réglage pour les deux.
    */
    'facility' => [
        'name' => env('KENEYA_FACILITY_NAME', 'Centre Hospitalier Keneya'),
        'address' => env('KENEYA_FACILITY_ADDRESS', 'Bamako, Mali'),
        'phone' => env('KENEYA_FACILITY_PHONE', '+223 20 00 00 00'),
        'email' => env('KENEYA_FACILITY_EMAIL', 'contact@keneya.test'),
    ],

    /*
    | Devise. Le franc CFA n'a pas de décimales : les montants sont stockés
    | en entiers, jamais en flottants.
    */
    'currency' => [
        'code' => 'XOF',
        'symbol' => 'FCFA',
        'decimals' => 0,
    ],

    /*
    | Identifiants métier lisibles. Format : <PREFIXE>-<ANNEE>-<SEQUENCE>.
    | Ils seront attribués sous verrou : deux caissiers ne doivent jamais
    | obtenir le même numéro de facture.
    */
    'identifiers' => [
        'padding' => 6,
        'prefixes' => [
            'invoice' => 'FAC',
            'payment' => 'PAI',
            'disbursement' => 'DEC',
            'cash_session' => 'SES',
            'credit_note' => 'AVO',
            'refund' => 'RBT',
            'deposit' => 'AVA',
        ],
    ],

    /*
    | Montage dans l'application hôte : préfixe d'URL et de nom de route
    | propres au module, pour ne jamais entrer en collision avec l'hôte
    | (qui a déjà sa propre route /caisse).
    */
    'route' => [
        'prefix' => env('FINANCE_ROUTE_PREFIX', 'finance'),
        'name' => 'finance.',
        'middleware' => ['web', 'finance.access'],
    ],

    /*
    | Modèle utilisateur de l'hôte (celui que Auth::user() renvoie).
    | Laissé nul : le module utilise auth.providers.users.model.
    */
    'models' => [
        'user' => null,
    ],

    /*
    | Correspondance des rôles du module avec ceux de l'hôte :
    |
    |   'roles' => ['caissier' => 'cashier'],
    |
    | Une valeur peut être une chaîne ou une liste. Un rôle non déclaré se
    | traduit par lui-même. Un rôle absent de la base est écarté : une liste
    | vide se lit, une page en 500 non.
    */
    'roles' => [
        //
    ],

    /*
    | Autorisation d'accès de haut niveau, décidée par l'hôte. Trois formes,
    | dans cet ordre : un résolveur (Finance::authorizeAccessUsing), une
    | capacité (Gate ou permission), un attribut booléen de l'utilisateur.
    | Sans accord explicite de l'hôte, l'accès est refusé.
    */
    'access' => [
        'ability' => env('FINANCE_ACCESS_ABILITY', 'finance.access'),
        'attribute' => env('FINANCE_ACCESS_ATTRIBUTE', 'can_access_finance'),
        'guard' => env('FINANCE_AUTH_GUARD'),
    ],

    /*
    | Mode autonome de développement : considère l'autorisation de l'hôte
    | comme accordée pour que le module reste navigable avant son
    | intégration. Inactif par défaut, et refusé en production quelle que
    | soit la valeur de la variable d'environnement.
    */
    'standalone' => [
        'enabled' => (bool) env('FINANCE_STANDALONE_DEV', false),
    ],

];
