# keneya/finance-caisse

Module **Finance, Caisse et Facturation** de Keneya. Se monte dans une
application Laravel hôte (Keneya Workflow), comme le module DME.

État : **v0.5.0, caisse + catalogue des actes** — porte d'entrée, droits, mode autonome, numérotation
sans doublon, journal d'audit non modifiable, moyens de paiement configurables, sessions de caisse (ouverture, encaissements,
décaissements, clôture avec écart, validation, annulations tracées), référentiel des
actes facturables avec centres analytiques et tarifs historisés.
Écrans : bureau du caissier, page de session, liste de contrôle, gestion des caisses,
catalogue des actes, fiche d'un acte et de ses tarifs, centres analytiques.

## Conventions (identiques à DME)

| | Valeur |
|---|---|
| Paquet | `keneya/finance-caisse` |
| Namespace | `Keneya\FinanceCaisse\` |
| Fournisseur de services | `FinanceServiceProvider` (découvert automatiquement) |
| URL | `/finance` |
| Noms de routes | `finance.` |
| Vues et traductions | `finance::` |
| Tables | `finance_` (`sequences`, `audit_logs`, `payment_methods`, `cash_registers`, `cash_sessions`, `payments`, `disbursements`, `analytic_centers`, `acts`, `tariffs`) |
| Configuration | `config/finance.php` |
| Middleware d'accès | `finance.access` |
| Capacité d'accès | `finance.access` |

## Principes

- **L'hôte décide de l'accès** (résolveur `Finance::authorizeAccessUsing()`,
  capacité `finance.access`, ou attribut `can_access_finance`). Sans décision
  explicite, le module reste fermé.
- **Droits internes** dans `Support\Rbac`, tous préfixés `finance.`. La
  commande `php artisan finance:sync-permissions` les crée sans seeder,
  sans jamais modifier un rôle existant (sauf l'administrateur, qui reçoit
  toutes les permissions).
- **Commandes** (idempotentes, sans seeder, sûres sur une base de production) :
  `finance:sync-permissions`, `finance:sync-payment-methods` et
  `finance:sync-catalog`. Elles ne modifient jamais ce que l'établissement a
  déjà réglé.
- **Numérotation** : `NumberGenerator::next('invoice')` donne `FAC-2026-000001`, attribué
  sous verrou de ligne ; la séquence repart à 1 chaque année et un numéro n'est jamais réutilisé.
- **Audit** : `Auditor::record(...)` écrit dans `finance_audit_logs`, qu'aucune ligne
  ne quitte ni ne change (le modèle refuse la modification et la suppression).
- **Caisse** (`src/Actions/`) : `OpenCashSession`, `RecordPayment`, `RecordDisbursement`,
  `CancelCashMovement`, `CloseCashSession`, `ValidateCashSession`. Chaque action est
  transactionnelle, verrouille la session, et écrit dans le journal d'audit.
  - Une session ouverte par caisse et par caissier ; fonds initial jamais négatif.
  - Théorique du tiroir = fonds initial + espèces encaissées - espèces décaissées
    (Mobile Money, carte, etc. sont totalisés par moyen mais n'entrent pas dans le tiroir).
  - Écart = compté - théorique ; tout écart doit être justifié.
  - Le caissier ne valide jamais sa propre clôture ; une session validée ne bouge plus.
  - Un encaissement ou décaissement ne se supprime pas : il s'annule (motif, auteur),
    et seulement tant que la session est ouverte.
  - Un décaissement en espèces ne peut pas dépasser ce que contient le tiroir.
  Les actions vérifient les règles métier ; les droits se contrôlent route par route
  (middleware `can:finance.…`). Une règle violée revient à l'écran comme un message,
  avec la saisie conservée.
- **Catalogue des actes** (`src/Models/AnalyticCenter.php`, `Act.php`, `Tariff.php`,
  `src/Actions/SetTariff.php`) : le référentiel de ce qui se facture, indépendant
  des factures pour que les prix évoluent sans réécrire l'historique.
  - **Centres analytiques** (`finance_analytic_centers`) : hiérarchie libre et
    auto-référencée (Pôle → Service → Activité), `kind` = produits, charges ou les
    deux. Ils répondront à « combien rapporte le laboratoire ».
  - **Actes** (`finance_acts`) : rattachés à un centre, avec un `dme_service_id`
    facultatif — lien **mou** vers `dme_services` de l'hôte, sans clé étrangère,
    utilisé seulement pour les rapprochements. Le module ne dépend pas de DME.
  - **Tarifs** (`finance_tariffs`) : plusieurs par acte selon le contexte (`kind` :
    `standard` aujourd'hui, un code d'assureur plus tard), mais **un seul actif par
    contexte**. Changer un prix ne modifie jamais la ligne existante : `SetTariff`
    la désactive et en crée une nouvelle, sous verrou et en écrivant au journal
    d'audit. Les lignes désactivées sont l'historique des prix. `Act::activeTariff($kind)`
    donne le tarif du jour.
  - Un centre ou un acte se **désactive**, il ne se supprime pas (`restrictOnDelete`
    partout : on ne cascade pas des données financières). Un centre qui porte encore
    des enfants ou des actes actifs ne se désactive pas.
- **Écrans** (`/finance`) : `/` (tableau de bord), `caisse` (bureau du caissier),
  `caisse/sessions/{id}` (encaisser, décaisser, clôturer), `sessions` (contrôle et
  validation), `caisses` (administration), `catalogue/actes` (liste et création),
  `catalogue/actes/{id}` (fiche : tarifs actifs, historique, formulaire de
  tarification), `catalogue/centres` (arborescence des centres analytiques).
  Textes en français écrits directement dans les vues
  pour l'instant. Le menu ne propose que ce que l'utilisateur a le droit de faire.
- **Tableau de bord** (`Services\DashboardMetrics`) : recettes et dépenses du jour
  avec leur variation par rapport à la veille, encaissements du jour, clôtures en
  attente, évolution des recettes sur 7 jours, répartition par moyen de paiement,
  dernières transactions, et l'état de la caisse du caissier connecté. **Lecture
  seule et uniquement sur des écritures réelles** : quand il n'y a rien, l'écran le
  dit au lieu d'afficher un chiffre inventé. Les chiffres ne s'affichent qu'avec
  `finance.dashboard.view` ou `finance.payments.view`.
- **Montants en entiers** (franc CFA : aucune décimale).
- **Écritures immuables** (tranches suivantes) : une facture validée ou un
  paiement ne se modifie ni ne se supprime ; les corrections passent par
  annulation, avoir, remboursement ou ajustement, liés à l'opération d'origine.
- **Séparation des tâches sur les prix** : `finance.catalog.view` est donné à tous
  les profils (on facture avec le catalogue, il faut le lire) ;
  `finance.catalog.manage` — créer ou désactiver un centre, un acte — reste
  **administratif** ; `finance.tariffs.manage` va au **comptable** et à
  l'administrateur : fixer un prix est une décision de gestion, jamais celle du
  caissier qui encaisse.
- Le module ne dépend pas de `keneya/dme` : les branchements sur les actes
  médicaux viendront dans un sous-dossier dédié, actif seulement si DME est
  présent. `finance_acts.dme_service_id` n'est qu'un repère, sans contrainte.
- Routes par contrôleur, jamais par closure (sinon `route:cache` échoue chez
  l'hôte).

## Interface

Keneya Finance a sa propre identité — marque « Keneya Finance », sous-titre
« Gestion financière hospitalière » — et n'affiche aucun menu du DME. La
présentation reste celle de Keneya Workflow : fond très clair, cartes blanches,
bordures discrètes, ombres légères, badges de statut sobres.

- **Aucune dépendance front** : ni Vite, ni Tailwind, ni npm, ni bibliothèque
  d'icônes ou de graphiques. Tout le design system tient dans
  `resources/views/partials/styles.blade.php`, sous forme de variables CSS
  (couleurs, rayons, ombres, espacements) : l'identité se change à un seul
  endroit. Les icônes sont du SVG en ligne
  (`resources/views/components/icon.blade.php`), les graphiques du CSS et du SVG.
- **Composants Blade anonymes**, préfixés comme les vues pour ne jamais entrer en
  collision avec ceux de l'hôte ou de DME : `<x-finance::card>`, `<x-finance::kpi>`,
  `<x-finance::page>`, `<x-finance::empty>`, `<x-finance::icon>`, `<x-finance::logo>`.
- **Ossature** : barre latérale + en-tête + contenu. Le menu est décrit une seule
  fois dans `Support\Navigation`. Une entrée dont la tranche n'est pas encore
  construite côté serveur (Factures, Paiements, Recettes, Dépenses, Assurances,
  Créances, Rapports) s'affiche grisée et marquée « bientôt » : elle montre la
  cible du module sans jamais mener à un écran vide ou à des chiffres inventés.
  Ces entrées disparaissent pour qui n'a accès à aucun écran réel.
- **Responsive** vérifié de 1920 à 390 px, sans débordement horizontal : au-dessous
  de 900 px la barre latérale devient un tiroir (une case à cocher CSS, **aucun
  JavaScript**) ; au-dessous de 720 px les tableaux se transforment en fiches
  empilées via `data-l`. Les tableaux larges défilent dans leur carte, jamais la
  page.

## Développer et tester

### En local

```bash
composer install
composer test
composer serve        # http://127.0.0.1:8000/dev : choisir un profil de démonstration
```

### Sous Docker (PHP 8.4)

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer test
docker compose run --rm --service-ports app composer serve:docker
```

La démonstration prépare d'abord une base SQLite (caisses, moyens de paiement,
centres analytiques, trois actes tarifés, profils), puis ouvre `/dev`. Profils,
sans mot de passe : caissier (Salif Konaté, Awa Traoré), comptable (Moussa Diarra),
direction, administrateur. Pour voir la séparation des tâches : un caissier
clôture, puis le comptable valide ; le caissier lit le catalogue, le comptable
change un tarif, l'administrateur crée un acte.

## Intégration dans l'application hôte

1. Copier le dossier dans `keneya_wf-mod/modules/finance-caisse`.
2. Dans le `composer.json` de l'hôte, ajouter un dépôt de type `path`
   (`./modules/finance-caisse`, `symlink: true`) et `"keneya/finance-caisse": "@dev"`
   dans `require`.
3. `composer update keneya/finance-caisse`, `php8.4 artisan migrate --force`, puis
   `php8.4 artisan finance:sync-permissions`, `php8.4 artisan finance:sync-payment-methods`
   et `php8.4 artisan finance:sync-catalog`.
4. Déclarer la décision d'accès de l'hôte (résolveur ou capacité) et, si
   besoin, la correspondance des rôles dans `config/finance.php`
   (`'roles' => ['caissier' => 'cashier']`).

Versions : le module demande les mêmes plages que DME pour les paquets
partagés (`spatie/laravel-permission`), et ne doit pas en ajouter d'autres
sans raison.
