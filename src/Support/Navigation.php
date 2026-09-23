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
                    self::item('File d\'attente', 'horloge', 'pharmacie.queue.index', 'pharmacie.queue.view', ['pharmacie.queue.*']),
                    self::soon('Dispensation', 'dispensation'),
                    self::soon('Ordonnances', 'ordonnance'),
                ],
            ],
            [
                'section' => 'Stock',
                'items' => [
                    self::soon('Produits', 'produit'),
                    self::soon('Lots et péremptions', 'lot'),
                    self::soon('Réceptions', 'reception'),
                    self::soon('Inventaires', 'inventaire'),
                ],
            ],
            [
                'section' => 'Paramètres',
                'items' => [
                    self::soon('Paramètres de la pharmacie', 'reglage'),
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
