<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

/**
 * Navigation du module : la seule source de vérité du menu latéral.
 *
 * Chaque entrée déclare son libellé, son icône, la permission qui la rend
 * visible, et la route qui la sert. Une entrée SANS route correspond à une
 * tranche qui n'est pas encore construite côté serveur : elle s'affiche
 * grisée et marquée « bientôt », jamais comme un lien qui mènerait à une page
 * vide ou à des chiffres inventés.
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
                    self::item('Tableau de bord', 'dashboard', 'finance.home'),
                    self::item('Caisse', 'caisse', 'finance.cash.index', 'finance.sessions.view', ['finance.cash.*']),
                    self::item('File de caisse', 'horloge', 'finance.queue.index', 'finance.sessions.view', ['finance.queue.*']),
                    self::item('Sessions à valider', 'controle', 'finance.review.index', 'finance.sessions.validate'),
                    self::item('Factures', 'facture', 'finance.invoices.index', 'finance.invoices.view', ['finance.invoices.*']),
                    self::item('Paiements', 'paiement', 'finance.ledger.payments', 'finance.payments.view'),
                    self::item('Recettes', 'recette', 'finance.ledger.revenue', 'finance.payments.view'),
                    self::item('Dépenses', 'depense', 'finance.ledger.expenses', 'finance.payments.view'),
                    self::item('Assurances', 'assurance', 'finance.insurance.index', 'finance.insurance.view', ['finance.insurance.*', 'finance.insurers.*']),
                    self::item('Créances', 'creance', 'finance.receivables.index', 'finance.receivables.view'),
                    self::item('Rapports', 'rapport', 'finance.reports.index', 'finance.reports.view'),
                ],
            ],
            [
                'section' => 'Paramètres',
                'items' => [
                    self::item('Actes et prestations', 'acte', 'finance.catalog.acts.index', 'finance.catalog.view', ['finance.catalog.acts.*']),
                    self::item('Centres analytiques', 'centre', 'finance.catalog.centers.index', 'finance.catalog.view'),
                    self::item('Caisses', 'reglage', 'finance.registers.index', 'finance.registers.manage'),
                    self::item('Utilisateurs', 'utilisateur', 'finance.users.index', 'finance.roles.manage', ['finance.users.*']),
                ],
            ],
        ];
    }

    /**
     * @param  list<string>  $match  motifs de noms de route qui allument aussi l'entrée
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
