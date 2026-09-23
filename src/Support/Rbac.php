<?php

declare(strict_types=1);

namespace Keneya\Pharmacie\Support;

use Spatie\Permission\Models\Role;

/**
 * Référentiel unique des rôles et permissions du module Pharmacie.
 *
 * Ce fichier est la seule source de vérité : la commande de synchronisation,
 * les routes et les tests le consomment. Ajouter une permission ici suffit à
 * la rendre disponible partout.
 *
 * Toutes les permissions sont préfixées `pharmacie.` : les tables `roles` et
 * `permissions` de spatie sont partagées avec DME, Finance et l'hôte.
 *
 * Séparation des tâches, comme en caisse : celui qui délivre n'est pas celui
 * qui valide un inventaire ou qui sort une perte du stock.
 */
final class Rbac
{
    // Rôles. « administrateur » est partagé avec les autres modules.
    public const ROLE_ADMIN = 'administrateur';

    public const ROLE_PHARMACIST = 'pharmacien';

    public const ROLE_DISPENSER = 'preparateur';

    public const ROLE_STOREKEEPER = 'magasinier';

    /**
     * Les rôles de l'application hôte qui correspondent à ce rôle du module.
     *
     * L'hôte déclare la correspondance dans `config/pharmacie.php` :
     *
     *     'roles' => ['pharmacien' => 'pharmacist'],
     *
     * Les rôles absents de la base sont écartés : une liste vide se lit, une
     * page en 500 non.
     *
     * @return list<string>
     */
    public static function hostRoles(string $role): array
    {
        $declares = (array) config('pharmacie.roles', []);

        $noms = array_values(array_filter(array_map(
            static fn ($nom) => is_string($nom) ? trim($nom) : '',
            (array) ($declares[$role] ?? $role),
        )));

        if ($noms === []) {
            return [];
        }

        return Role::query()->whereIn('name', $noms)->pluck('name')->all();
    }

    /**
     * @return array<string, string>
     */
    public static function roleLabels(): array
    {
        return [
            self::ROLE_ADMIN => 'Administrateur',
            self::ROLE_PHARMACIST => 'Pharmacien',
            self::ROLE_DISPENSER => 'Préparateur',
            self::ROLE_STOREKEEPER => 'Magasinier',
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
                'pharmacie.access' => 'Ouvrir le module Pharmacie',
            ],
            'File d\'attente' => [
                'pharmacie.queue.view' => 'Consulter la file de la pharmacie',
                'pharmacie.queue.call' => 'Appeler le patient suivant',
            ],
            'Catalogue des produits' => [
                'pharmacie.products.view' => 'Consulter le catalogue des produits',
                'pharmacie.products.manage' => 'Gérer le catalogue des produits',
                'pharmacie.prices.manage' => 'Modifier les prix de vente',
            ],
            'Stock' => [
                'pharmacie.stock.view' => 'Consulter le stock et les lots',
                'pharmacie.stock.receive' => 'Enregistrer une réception',
                'pharmacie.stock.adjust' => 'Ajuster le stock (casse, perte, péremption)',
                'pharmacie.inventory.count' => 'Saisir un inventaire',
                'pharmacie.inventory.validate' => 'Valider un inventaire',
            ],
            'Dispensation' => [
                'pharmacie.dispensing.view' => 'Consulter les dispensations',
                'pharmacie.dispensing.create' => 'Délivrer des produits',
                'pharmacie.dispensing.cancel' => 'Annuler une dispensation',
            ],
            'Pilotage' => [
                'pharmacie.dashboard.view' => 'Consulter le tableau de bord de la pharmacie',
                'pharmacie.reports.view' => 'Consulter les rapports de la pharmacie',
            ],
            'Administration' => [
                'pharmacie.audit.view' => 'Consulter le journal d\'audit de la pharmacie',
                'pharmacie.settings.manage' => 'Gérer les paramètres de la pharmacie',
                'pharmacie.roles.manage' => 'Modifier les rôles et permissions de la pharmacie',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allPermissions(): array
    {
        return array_merge(...array_map(
            static fn (array $group) => array_keys($group),
            array_values(self::permissionGroups()),
        ));
    }

    /**
     * Permissions que l'administrateur conserve quoi qu'il arrive : les lui
     * retirer fermerait l'écran qui permet de les rendre.
     *
     * @return list<string>
     */
    public static function lockedAdminPermissions(): array
    {
        return ['pharmacie.settings.manage', 'pharmacie.roles.manage'];
    }

    /**
     * Permissions attribuées à chaque rôle à sa création.
     *
     * Ce tableau ne sert qu'à peupler un rôle qui n'existe pas encore :
     * l'état réel se lit dans les rôles persistés.
     *
     * @return array<string, list<string>>
     */
    public static function rolePermissions(): array
    {
        return [
            self::ROLE_ADMIN => self::allPermissions(),

            // Le pharmacien : il répond du stock et de ce qui est délivré.
            // Il valide les inventaires et fixe les prix.
            self::ROLE_PHARMACIST => [
                'pharmacie.access',
                'pharmacie.queue.view', 'pharmacie.queue.call',
                'pharmacie.products.view', 'pharmacie.products.manage', 'pharmacie.prices.manage',
                'pharmacie.stock.view', 'pharmacie.stock.receive', 'pharmacie.stock.adjust',
                'pharmacie.inventory.count', 'pharmacie.inventory.validate',
                'pharmacie.dispensing.view', 'pharmacie.dispensing.create', 'pharmacie.dispensing.cancel',
                'pharmacie.dashboard.view', 'pharmacie.reports.view',
                'pharmacie.audit.view',
            ],

            // Le préparateur sert au comptoir : il délivre, il ne corrige pas
            // le stock et ne valide pas d'inventaire.
            self::ROLE_DISPENSER => [
                'pharmacie.access',
                'pharmacie.queue.view', 'pharmacie.queue.call',
                'pharmacie.products.view',
                'pharmacie.stock.view',
                'pharmacie.dispensing.view', 'pharmacie.dispensing.create',
            ],

            // Le magasinier tient les entrées et compte : il ne délivre pas.
            self::ROLE_STOREKEEPER => [
                'pharmacie.access',
                'pharmacie.products.view',
                'pharmacie.stock.view', 'pharmacie.stock.receive', 'pharmacie.stock.adjust',
                'pharmacie.inventory.count',
                'pharmacie.reports.view',
            ],
        ];
    }
}
