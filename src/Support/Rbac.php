<?php

declare(strict_types=1);

namespace Keneya\FinanceCaisse\Support;

use Spatie\Permission\Models\Role;

/**
 * Référentiel unique des rôles et permissions du module Finance.
 *
 * Ce fichier est la seule source de vérité : la commande de synchronisation,
 * les policies et les tests le consomment. Ajouter une permission ici
 * suffit à la rendre disponible partout.
 *
 * Toutes les permissions sont préfixées `finance.` : les tables `roles` et
 * `permissions` de spatie sont partagées avec DME et l'hôte.
 *
 * Séparation des tâches : celui qui demande une remise ou un remboursement
 * n'est pas celui qui l'approuve, et la validation d'une clôture de caisse
 * n'appartient pas au caissier.
 */
final class Rbac
{
    // Rôles. « administrateur » est partagé avec DME : même nom, même rôle.
    public const ROLE_ADMIN = 'administrateur';

    public const ROLE_CASHIER = 'caissier';

    public const ROLE_ACCOUNTANT = 'comptable';

    public const ROLE_DIRECTOR = 'direction';

    /**
     * Les rôles de l'application hôte qui correspondent à ce rôle du module.
     *
     * L'hôte déclare la correspondance dans `config/finance.php` :
     *
     *     'roles' => ['caissier' => 'cashier'],
     *
     * Une valeur peut être une chaîne ou une liste. Un rôle non déclaré se
     * traduit par lui-même. Les rôles absents de la base sont écartés ici :
     * une liste vide se lit, une page en 500 non.
     *
     * @return list<string>
     */
    public static function hostRoles(string $role): array
    {
        $declares = (array) config('finance.roles', []);

        $noms = array_values(array_filter(array_map(
            static fn ($nom) => is_string($nom) ? trim($nom) : '',
            (array) ($declares[$role] ?? $role),
        )));

        if ($noms === []) {
            return [];
        }

        return Role::query()
            ->whereIn('name', $noms)
            ->pluck('name')
            ->all();
    }

    /**
     * Libellés d'affichage des rôles.
     *
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            self::ROLE_ADMIN => 'Administrateur',
            self::ROLE_CASHIER => 'Caissier',
            self::ROLE_ACCOUNTANT => 'Comptable',
            self::ROLE_DIRECTOR => 'Direction',
        ];
    }

    /**
     * Toutes les permissions granulaires, groupées par domaine.
     *
     * @return array<string, array<string, string>>
     */
    public static function permissionGroups(): array
    {
        return [
            'Accès' => [
                'finance.access' => 'Ouvrir le module Finance',
            ],
            'Catalogue des actes' => [
                'finance.catalog.view' => 'Consulter le catalogue des actes',
                'finance.catalog.manage' => 'Gérer le catalogue des actes',
                'finance.tariffs.manage' => 'Modifier les tarifs',
            ],
            'Sessions de caisse' => [
                'finance.sessions.view' => 'Consulter les sessions de caisse',
                'finance.sessions.open' => 'Ouvrir une session de caisse',
                'finance.sessions.close' => 'Clôturer sa session de caisse',
                'finance.sessions.validate' => 'Valider une clôture de caisse',
                'finance.registers.manage' => 'Gérer les caisses',
            ],
            'Encaissements et décaissements' => [
                'finance.payments.view' => 'Consulter les paiements',
                'finance.payments.create' => 'Encaisser un paiement',
                'finance.disbursements.create' => 'Enregistrer un décaissement',
                'finance.payments.cancel' => 'Annuler un encaissement',
                'finance.disbursements.cancel' => 'Annuler un décaissement',
            ],
            'Factures' => [
                'finance.invoices.view' => 'Consulter les factures',
                'finance.invoices.create' => 'Créer une facture',
                'finance.invoices.validate' => 'Valider une facture',
                'finance.invoices.cancel' => 'Annuler une facture',
            ],
            'Remises et remboursements' => [
                'finance.discounts.request' => 'Demander une remise',
                'finance.discounts.approve' => 'Approuver une remise',
                'finance.refunds.request' => 'Demander un remboursement',
                'finance.refunds.approve' => 'Approuver un remboursement',
            ],
            'Avances et comptes patients' => [
                'finance.deposits.create' => 'Enregistrer une avance',
                'finance.accounts.view' => 'Consulter le compte financier d\'un patient',
            ],
            'Créances' => [
                'finance.receivables.view' => 'Consulter les créances',
            ],
            'Pilotage' => [
                'finance.dashboard.view' => 'Consulter le tableau de bord financier',
                'finance.reports.view' => 'Consulter les rapports financiers',
            ],
            'Administration' => [
                'finance.audit.view' => 'Consulter le journal d\'audit financier',
                'finance.settings.manage' => 'Gérer les paramètres financiers',
                'finance.roles.manage' => 'Modifier les rôles et permissions financiers',
            ],
        ];
    }

    /**
     * Liste plate de toutes les permissions.
     *
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return array_merge(...array_map(
            static fn (array $group) => array_keys($group),
            array_values(self::permissionGroups())
        ));
    }

    /**
     * Permissions que l'administrateur conserve quoi qu'il arrive : les lui
     * retirer fermerait l'accès à l'écran qui permet de les rendre.
     *
     * @return list<string>
     */
    public static function lockedAdminPermissions(): array
    {
        return ['finance.settings.manage', 'finance.roles.manage'];
    }

    /**
     * Permissions attribuées à chaque rôle à sa création.
     *
     * Ce tableau ne sert qu'à peupler un rôle qui n'existe pas encore :
     * l'état réel se lit dans les rôles persistés, modifiables par
     * l'administrateur.
     *
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        return [
            self::ROLE_ADMIN => self::allPermissions(),

            // Caissier : sa session, les encaissements, les factures. Il
            // demande remises et remboursements, il ne les approuve pas.
            self::ROLE_CASHIER => [
                'finance.access',
                'finance.catalog.view',
                'finance.sessions.view', 'finance.sessions.open', 'finance.sessions.close',
                'finance.payments.view', 'finance.payments.create',
                'finance.disbursements.create',
                'finance.invoices.view', 'finance.invoices.create',
                'finance.discounts.request',
                'finance.refunds.request',
                'finance.deposits.create',
                'finance.accounts.view',
                'finance.receivables.view',
            ],

            // Comptable : contrôle. Valide les clôtures et approuve les
            // opérations sensibles.
            self::ROLE_ACCOUNTANT => [
                'finance.access',
                'finance.catalog.view',
                'finance.sessions.view', 'finance.sessions.validate',
                'finance.payments.view',
                'finance.disbursements.create',
                'finance.payments.cancel', 'finance.disbursements.cancel',
                'finance.invoices.view', 'finance.invoices.validate', 'finance.invoices.cancel',
                'finance.discounts.approve',
                'finance.refunds.approve',
                'finance.accounts.view',
                'finance.receivables.view',
                'finance.reports.view',
                'finance.audit.view',
            ],

            // Direction : lecture et pilotage, aucune opération de caisse.
            self::ROLE_DIRECTOR => [
                'finance.access',
                'finance.catalog.view',
                'finance.receivables.view',
                'finance.dashboard.view',
                'finance.reports.view',
            ],
        ];
    }
}
