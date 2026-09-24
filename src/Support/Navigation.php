<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

/**
 * Navigation du module : la seule source de vérité du menu latéral.
 *
 * Chaque entrée déclare son libellé, son icône, la permission qui la rend
 * visible, et la route qui la sert. Une entrée SANS route correspond à une
 * tranche qui n'est pas encore construite : elle s'affiche grisée et marquée
 * « bientôt », jamais comme un lien qui mènerait à une page vide.
 *
 * C'est de la structure d'écran, pas de la règle métier : les droits restent
 * contrôlés route par route par le middleware `can:`.
 */
final class Navigation
{
    /**
     * @return list<array{
     *     section: string,
     *     items: list<array{label: string, icon: string, route: ?string, can: ?string, match: list<string>}>
     * }>
     */
    public static function sections(): array
    {
        return [
            [
                'section' => '',
                'items' => [
                    self::item('Tableau de bord', 'dashboard', 'pharmacie.home'),
                    self::item('Alertes', 'bell', 'pharmacie.alerts.index'),
                    self::item('File d\'attente', 'horloge', 'pharmacie.queue.index', 'pharmacie.queue.view', ['pharmacie.queue.*']),
                    self::item('Comptoir', 'dispensation', 'pharmacie.dispensing.create', 'pharmacie.dispensing.create'),
                    self::item('Dispensations', 'dispensation', 'pharmacie.dispensing.index', 'pharmacie.dispensing.view', ['pharmacie.dispensing.*']),
                    self::item('Ordonnances', 'ordonnance', 'pharmacie.prescriptions.index', 'pharmacie.dispensing.view', ['pharmacie.prescriptions.*']),
                ],
            ],
            [
                'section' => 'Stock',
                'items' => [
                    self::item('Produits', 'produit', 'pharmacie.catalog.products.index', 'pharmacie.products.view', ['pharmacie.catalog.products.*']),
                    self::item('Catégories', 'inventaire', 'pharmacie.catalog.categories.index', 'pharmacie.products.view', ['pharmacie.catalog.categories.*']),
                    self::item('Stock', 'lot', 'pharmacie.stock.index', 'pharmacie.stock.view', ['pharmacie.stock.index', 'pharmacie.stock.products.*', 'pharmacie.stock.batches.*']),
                    self::item('Péremptions', 'alert', 'pharmacie.stock.expiring', 'pharmacie.stock.view'),
                    self::item('Emplacements', 'reception', 'pharmacie.stock.locations.index', 'pharmacie.stock.view', ['pharmacie.stock.locations.*']),
                    self::item('Transferts', 'fleche', 'pharmacie.transfers.index', 'pharmacie.stock.view', ['pharmacie.transfers.*']),
                    self::item('Réceptions', 'reception', 'pharmacie.supply.receptions.index', 'pharmacie.stock.view', ['pharmacie.supply.receptions.*']),
                    self::item('Commandes', 'ordonnance', 'pharmacie.supply.orders.index', 'pharmacie.stock.view', ['pharmacie.supply.orders.*']),
                    self::item('Fournisseurs', 'utilisateur', 'pharmacie.supply.suppliers.index', 'pharmacie.stock.view', ['pharmacie.supply.suppliers.*']),
                    self::item('Inventaires', 'inventaire', 'pharmacie.inventory.index', 'pharmacie.stock.view', ['pharmacie.inventory.index', 'pharmacie.inventory.show']),
                    self::item('Pertes', 'alert', 'pharmacie.inventory.losses', 'pharmacie.stock.view', ['pharmacie.inventory.losses*']),
                ],
            ],
            [
                'section' => 'Pilotage',
                'items' => [
                    self::item('Rapports', 'rapport', 'pharmacie.reports.index', 'pharmacie.reports.view'),
                    self::item('Prévision', 'calendrier', 'pharmacie.reports.forecast', 'pharmacie.reports.view'),
                ],
            ],
            [
                'section' => 'Surveillance',
                'items' => [
                    self::item('Registre sous contrôle', 'verrou', 'pharmacie.vigilance.register', 'pharmacie.vigilance.view'),
                    self::item('Rappels de lots', 'alert', 'pharmacie.vigilance.recalls.index', 'pharmacie.vigilance.view', ['pharmacie.vigilance.recalls.*']),
                    self::item('Pharmacovigilance', 'controle', 'pharmacie.vigilance.events.index', 'pharmacie.vigilance.view', ['pharmacie.vigilance.events.*']),
                ],
            ],
            [
                'section' => 'Paramètres',
                'items' => [
                    self::item('Paramètres de la pharmacie', 'reglage', 'pharmacie.settings.index', 'pharmacie.settings.manage'),
                    self::item('Utilisateurs', 'utilisateur', 'pharmacie.users.index', 'pharmacie.roles.manage', ['pharmacie.users.*']),
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $match
     * @return array{label: string, icon: string, route: ?string, can: ?string, match: list<string>}
     */
    private static function item(string $label, string $icon, string $route, ?string $can = null, array $match = []): array
    {
        return ['label' => $label, 'icon' => $icon, 'route' => $route, 'can' => $can, 'match' => array_merge([$route], $match)];
    }

    /**
     * Une tranche à venir : visible pour que la cible du module se lise, mais
     * jamais cliquable tant que le serveur ne sait pas la servir.
     *
     * @return array{label: string, icon: string, route: null, can: null, match: list<string>}
     */
    private static function soon(string $label, string $icon): array
    {
        return ['label' => $label, 'icon' => $icon, 'route' => null, 'can' => null, 'match' => []];
    }
}
