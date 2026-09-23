# Keneya Pharmacie

Module **Pharmacie** de Keneya : stock par lot, dispensation et file d'attente.
Il se monte dans une application Laravel hôte (Keneya Workflow), comme le
module `keneya/finance-caisse`, et se développe d'abord **seul**, sur Docker.

État : **v0.1.0, le terrain** — montage, porte d'entrée, droits, file d'attente
fournie par l'hôte, journal d'audit, hôte de démonstration. Le stock et la
dispensation viennent ensuite.

## Conventions (identiques à Finance et DME)

- Français partout : écrans, messages, commentaires, noms de tables.
- Montants en **entiers** (franc CFA, aucune décimale).
- Aucune dépendance front : ni Vite, ni Tailwind, ni npm. Le design system tient
  dans `resources/views/partials/styles.blade.php`, les icônes sont du SVG en
  ligne.
- Rien d'inventé à l'écran : un écran vide vaut mieux qu'un chiffre faux.

## Ce que l'hôte fournit, et rien d'autre

Le module ne possède ni le patient, ni son parcours, ni la caisse. Il demande
trois choses à l'application hôte, qu'elle peut déclarer une par une :

1. **Qui entre** — `Pharmacie::authorizeAccessUsing(fn ($user) => …)`.
   Sans accord explicite, le module reste fermé (403). Deux autres formes sont
   acceptées : une capacité (`pharmacie.access`) ou un attribut booléen du
   modèle utilisateur (voir `config/pharmacie.php`).
2. **Qui attend** — une implémentation de `Contracts\PharmacyQueueProvider`,
   liée dans le conteneur. Même mécanique que la file de caisse de Finance : le
   module affiche et appelle, l'hôte range. Par défaut `Queue\NoPharmacyQueue`
   rend une file vide, et les écrans le disent.
3. **Où part ce qui doit être payé** — une implémentation de
   `Contracts\SaleSink`. Elle reste volontairement neutre : on y branchera soit
   le module Finance (la caisse « Pharmacie » y existe déjà, avec ses sessions,
   ses écarts et son audit), soit une caisse propre. Par défaut
   `Sales\NoSaleSink` ne fait rien.

**Recommandation** : garder Finance comme source unique des paiements. Deux
caisses, ce sont deux vérités sur l'argent, deux clôtures et deux journaux.

## Droits

Tout est déclaré dans `Support\Rbac`, préfixé `pharmacie.`, et créé par
`php artisan pharmacie:sync-permissions` (idempotente, sans seeder). Quatre
rôles de départ, avec la séparation des tâches :

| Rôle | Ce qu'il fait |
| --- | --- |
| `pharmacien` | Répond du stock : prix, réceptions, ajustements, inventaires validés, dispensation |
| `preparateur` | Sert au comptoir : file d'attente et dispensation, sans toucher au stock |
| `magasinier` | Reçoit et compte : entrées, ajustements, inventaires saisis, sans délivrer |
| `administrateur` | Tout, et lui seul garde les droits qui ouvrent l'écran des droits |

## Développer seul, sur Docker

```bash
docker compose run --rm app composer install   # peuple le volume vendor
docker compose up                               # http://127.0.0.1:8001/dev
```

`/dev` est l'hôte de démonstration (dossier `workbench/`) : il fournit ses
utilisateurs, sa page de connexion et **sa file d'attente** (`DemoQueue`), comme
le fera Keneya Workflow. Choisissez un profil, puis ouvrez `/pharmacie`.

Tests :

```bash
docker compose run --rm app vendor/bin/phpunit
docker compose run --rm app vendor/bin/pint src tests
```

Le port 8001 laisse le 8000 à Keneya Finance : les deux modules peuvent tourner
en même temps sur le poste de développement.

## Intégration dans Keneya Workflow (à venir)

Le jour de l'intégration, l'hôte ajoutera un fournisseur de services qui :

- déclare la décision d'accès (`Pharmacie::authorizeAccessUsing`), comme
  `FinanceIntegrationServiceProvider` le fait pour Finance ;
- lie sa file d'attente de pharmacie au contrat `PharmacyQueueProvider` ;
- lie, si on le décide, un adaptateur `SaleSink` vers Finance ;
- fait apparaître l'entrée « Pharmacie » dans ses menus, sous la même capacité
  que celle qui ouvre le module.

Le module n'impose rien d'autre : ni modèle utilisateur, ni page de connexion,
ni table de patients.

## Feuille de route

1. **Le terrain** (fait) : montage, accès, droits, file d'attente, audit.
2. **Catalogue des produits** : médicaments et consommables, prix de vente.
3. **Stock par lot** : réceptions, lots, péremptions, mouvements, inventaires.
4. **Dispensation** : ce qui est délivré, à qui, sur quelle ordonnance, avec
   sortie de stock par lot (premier périmé, premier sorti).
5. **Encaissement** : branchement du `SaleSink` sur la décision retenue.
6. **Pilotage** : tableau de bord, ruptures, péremptions, rapports.
