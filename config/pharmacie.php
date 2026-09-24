<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Configuration du module Keneya-Pharmacie
|--------------------------------------------------------------------------
|
| Fichier publiable (php artisan vendor:publish --tag=pharmacie-config).
| Il regroupe ce que l'application hôte peut avoir besoin d'ajuster :
| montage des routes, autorisation d'accès accordée par l'hôte, identité de
| l'établissement, identifiants métier.
|
| Aucune valeur métier ne doit être codée en dur ailleurs dans le module.
|
*/

return [

    'version' => '0.1.0',

    /*
    | Établissement exploitant l'application. Mêmes variables d'environnement
    | que les modules DME et Finance : un seul réglage pour tous.
    */
    'facility' => [
        // L'etablissement regarde par defaut. Multi-etablissement : l'hote
        // designera le sien par Support\Facility::use().
        'id' => (int) env('PHARMACIE_FACILITY_ID', 1),
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
    | Attribués sous verrou : deux préparateurs ne doivent jamais obtenir le
    | même numéro de dispensation.
    */
    'identifiers' => [
        'padding' => 6,
        'prefixes' => [
            'dispensation' => 'DIS',
            'order' => 'CMD',
            'reception' => 'REC',
            'transfer' => 'TRF',
            'inventory' => 'INV',
            'loss' => 'PER',
            'recall' => 'RAP',
            'adverse_event' => 'EIV',
        ],
    ],

    /*
    | Contrôle renforcé : ce qu'un produit sous surveillance (stupéfiant,
    | psychotrope, produit sous contrôle) exige de plus au moment d'être
    | délivré. Le drapeau `is_controlled` du produit décide si ces règles
    | s'appliquent ; ces réglages disent ce qu'elles exigent.
    |
    | Rien n'est figé dans le code : la réglementation n'est pas la même d'un
    | pays à l'autre, et elle change.
    */
    'controlled' => [
        // La capacité exigée pour servir un tel produit. Vide : aucune.
        'ability' => env('PHARMACIE_CONTROLLED_ABILITY', 'pharmacie.controlled.dispense'),
        'require_prescription' => (bool) env('PHARMACIE_CONTROLLED_REQUIRE_PRESCRIPTION', true),
        'require_patient' => (bool) env('PHARMACIE_CONTROLLED_REQUIRE_PATIENT', true),
    ],

    /*
    | Stock.
    |
    | `expiry_warning_days` : à partir de combien de jours avant la date de
    | péremption un lot est signalé. `allow_expired_dispensing` reste faux :
    | un lot périmé ne se délivre pas, et ce n'est pas une préférence.
    */
    'stock' => [
        'expiry_warning_days' => (int) env('PHARMACIE_EXPIRY_WARNING_DAYS', 90),
        'allow_expired_dispensing' => false,
    ],

    /*
    | Montage dans l'application hôte : préfixe d'URL et de nom de route
    | propres au module, pour ne jamais entrer en collision avec l'hôte.
    */
    'route' => [
        'prefix' => env('PHARMACIE_ROUTE_PREFIX', 'pharmacie'),
        'name' => 'pharmacie.',
        'middleware' => ['web', 'pharmacie.access'],
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
    |   'roles' => ['pharmacien' => 'pharmacist'],
    |
    | Une valeur peut être une chaîne ou une liste. Un rôle non déclaré se
    | traduit par lui-même.
    */
    'roles' => [
        //
    ],

    /*
    | Autorisation d'accès de haut niveau, décidée par l'hôte. Trois formes,
    | dans cet ordre : un résolveur (Pharmacie::authorizeAccessUsing), une
    | capacité (Gate ou permission), un attribut booléen de l'utilisateur.
    | Sans accord explicite de l'hôte, l'accès est refusé.
    */
    'access' => [
        'ability' => env('PHARMACIE_ACCESS_ABILITY', 'pharmacie.access'),
        'attribute' => env('PHARMACIE_ACCESS_ATTRIBUTE', 'can_access_pharmacie'),
        'guard' => env('PHARMACIE_AUTH_GUARD'),
    ],

    /*
    | Mode autonome de développement : considère l'autorisation de l'hôte
    | comme accordée pour que le module reste navigable avant son
    | intégration. Inactif par défaut, et refusé en production quelle que
    | soit la valeur de la variable d'environnement.
    */
    'standalone' => [
        'enabled' => (bool) env('PHARMACIE_STANDALONE_DEV', false),
    ],

];
