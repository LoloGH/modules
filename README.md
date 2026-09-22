# keneya/finance-caisse

Module **Finance, Caisse et Facturation** de Keneya. Se monte dans une
application Laravel hôte (Keneya Workflow), comme le module DME.

État : **v0.9.0, caisse + catalogue exposé à l'hôte + file de caisse fournie par l'hôte + avancement de la visite après encaissement** — porte d'entrée, droits, mode autonome, numérotation
sans doublon, journal d'audit non modifiable, moyens de paiement configurables, sessions de caisse (ouverture, encaissements,
décaissements, clôture avec écart, validation, annulations tracées), référentiel des
actes facturables avec centres analytiques et tarifs historisés, lisible par l'hôte
via le contrat `CatalogProvider` (`Finance::catalog()`), ticket de consultation,
file de caisse de l'hôte lue par le contrat `CashQueueProvider` (`Finance::cashQueue()`).
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
| Tables | `finance_` (`sequences`, `audit_logs`, `payment_methods`, `cash_registers`, `cash_sessions`, `payments`, `disbursements`, `analytic_centers`, `acts`, `tariffs`, `cashier_settings`, `cashier_registers`) |
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
  - **Une seule session ouverte par caisse**, toujours : deux personnes ne
    tiennent jamais le même tiroir. Fonds initial jamais négatif.
  - **Plusieurs caisses pour un même caissier**, si l'établissement le règle
    ainsi. `finance.cash.max_open_sessions_per_cashier` (défaut **1**) fixe
    combien de sessions un caissier peut tenir ouvertes en même temps. Avec 1,
    le fonctionnement est celui d'avant : un caissier, un tiroir.
    - Ce nombre se surcharge caissier par caissier dans
      `finance_cashier_settings` (`cashier_id` unique, `max_open_sessions`
      nullable). Pas de ligne, ou valeur nulle, veut dire « le défaut de
      l'établissement ». Le choix d'une table plutôt qu'un champ sur
      l'utilisateur tient à ce que le module ne possède pas la table des
      utilisateurs de l'hôte : il n'en connaît qu'un identifiant en chaîne.
    - `CashierSetting::limitFor($cashierId)` donne la limite effective.
  - **Quelles caisses un caissier peut ouvrir** : `finance_cashier_registers`
    affecte un caissier à des caisses précises. **Aucune ligne pour un
    caissier vaut « toutes les caisses »** : l'affectation est une restriction
    volontaire, pas un passage obligé, et personne ne se retrouve enfermé
    dehors parce qu'une case n'a pas été cochée.
    `CashierRegister::allows($cashierId, $registerId)` tranche.
    L'affectation se contrôle **à l'ouverture** : une session déjà ouverte
    n'est pas interrompue si le réglage change ensuite.
    - Les deux réglages se font sur l'écran **Caisses**, sous
      `can:finance.registers.manage`, dans un seul formulaire par caissier.
      Chaque écriture est tracée (`cashier_limit_set`,
      `cashier_registers_set`). Un caissier n'y apparaît qu'après avoir ouvert
      une première session… sauf si l'hôte les déclare : l'annuaire
      `Contracts\CashierDirectory` (`Finance::cashiers()`, liste de
      `Cashiers\HostCashier` : `id` = `Actor::id()`, `name`, `function`) fait
      apparaître d'avance le personnel habilité à encaisser, pour régler ses
      caisses avant sa première ouverture. Par défaut `NoCashierDirectory` (vide).
  - Théorique du tiroir = fonds initial + espèces encaissées - espèces décaissées
    (Mobile Money, carte, etc. sont totalisés par moyen mais n'entrent pas dans le tiroir).
  - Écart = compté - théorique ; tout écart doit être justifié.
  - Le caissier ne valide jamais sa propre clôture ; une session validée ne bouge plus.
  - Un encaissement ou décaissement ne se supprime pas : il s'annule (motif, auteur),
    et seulement tant que la session est ouverte.
  - Un décaissement en espèces ne peut pas dépasser ce que contient le tiroir.
  - **Un encaissement désigne l'acte qu'il paie** (`finance_payments.act_id`,
    nullable, `restrictOnDelete`) : consultation, analyse, imagerie… Le
    formulaire propose le catalogue groupé par centre analytique, avec le tarif
    standard du jour, qui **remplit automatiquement le champ « Montant »**. Le
    montant reste malgré tout la décision du caissier : il peut l'écraser, et
    un acompte sur un acte tarifé est possible. Sans libellé saisi,
    le nom de l'acte fait office de motif ; un acte désactivé ne s'encaisse
    plus, et un encaissement hors catalogue (avance, reliquat) reste permis.
    C'est ce lien qui permettra de dire ce que rapporte chaque service.
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
  - **Ticket de consultation** (`finance_acts.is_consultation_ticket`) : l'acte
    payé à l'accueil. **Un seul acte le porte** : `SetConsultationTicket` retire la
    marque de l'ancien dans la même transaction, sous verrou, et trace
    `consultation_ticket_set` / `consultation_ticket_unset`. Un acte désactivé ne
    peut pas le devenir. Case sur la fiche de l'acte, sous
    `can:finance.catalog.manage`.
- **Catalogue exposé à l'hôte** — Finance est la source unique des actes et des
  prix ; l'hôte les **lit** par un contrat, jamais par les modèles :
  - `Contracts\CatalogProvider`, résolu par `Finance::catalog()` (singleton,
    implémentation `Catalog\EloquentCatalogProvider`, remplaçable par l'hôte) :
    - `actsForService(?int $hostServiceId)` : actes actifs rattachés au service
      (`dme_service_id`) puis actes génériques (sans service), chaque groupe par
      nom ; sans service, les génériques seuls ;
    - `findAct(int $actId)` : l'acte actif, ou null ;
    - `activeTariffFor(int $actId, string $kind = 'standard')` : montant entier du
      tarif actif, ou null (pas de tarif, acte inconnu ou désactivé) ;
    - `ticketAct()` : l'acte « ticket de consultation » s'il est actif, ou null.
  - `Catalog\CatalogAct` : objet de valeur `final readonly`, uniquement des
    scalaires — `id`, `code`, `name`, `hostServiceId`, `analyticCenterId`,
    `activeAmount` (tarif **standard** actif, null s'il n'est pas fixé). Le modèle
    Eloquent ne sort jamais du module.
  - Un acte désactivé est invisible pour l'hôte, par toutes les méthodes.

  ```php
  use Keneya\FinanceCaisse\Finance;

  foreach (Finance::catalog()->actsForService($service->id) as $act) {
      echo $act->name, ' — ', $act->activeAmount ?? 'prix non fixé';
  }

  $ticket = Finance::catalog()->ticketAct(); // ?CatalogAct
  ```
- **File de caisse fournie par l'hôte** — le caissier appelle et encaisse dans
  Finance ; la file (visites, tickets) appartient à l'hôte, qui l'**implémente** :
  - `Contracts\CashQueueProvider`, lu par `Finance::cashQueue()` :
    `queues()` (les caisses), `pendingVisits($queueRef)` (appelés d'abord, puis
    par ticket), `callNext($queueRef, $cashier)`, `findVisit($queueRef, $visitRef)`.
    Par défaut `Queue\NoCashQueue` (aucune file, lié par `singletonIf`) ; l'hôte
    lie sa propre implémentation dans son fournisseur de services.
  - Objets de valeur `Queue\CashQueue` (`ref`, `name`) et `Queue\QueuedVisit`
    (`ref`, `token`, `status`, `patientRef`, `patientName`, `originService`,
    `destinationService`, `act` : `?CatalogAct` avec son tarif). Les `ref` sont
    opaques : Finance les transporte sans les interpréter. Celle d'une visite
    désigne un **passage à une caisse**, pas l'épisode du patient : ticket puis
    caisse des services = deux références, deux encaissements.
  - Écran `file` (`finance.queue.index`, `can:finance.sessions.view`) : la file
    du jour d'une caisse ; « Appeler le suivant » (`finance.queue.call`,
    `can:finance.payments.create`) ; pour un patient appelé, « Encaisser » ouvre
    la session avec patient, acte et montant **pré-remplis** — le caissier relit
    et valide, rien n'est enregistré sans lui. Sans session ouverte, l'écran
    invite d'abord à l'ouvrir et l'appel est refusé.
- **Après encaissement, la visite avance chez l'hôte** — `Contracts\VisitAdvancer`
  (`advanceAfterPayment($visitRef, SettledPayment $payment)`), lu par
  `Finance::visitAdvancer()`, implémenté par l'hôte (par défaut
  `Queue\NoVisitAdvancer`, qui ne fait rien).
  - Le formulaire pré-rempli depuis la file porte `queue_ref` et `visit_ref` ;
    l'encaissement passe alors par `Actions\CollectQueuedVisit` :
    1. la visite doit encore attendre à cette caisse (`findVisit`), et ne jamais
       avoir été encaissée (`finance_payments.host_visit_ref`, référence opaque
       de la visite) — pas de double paiement ;
    2. **dans une seule transaction** : `RecordPayment` (numéro, session, audit),
       puis `advanceAfterPayment` chez l'hôte ;
    3. si l'hôte lève une exception, tout est annulé (aucun encaissement
       orphelin), l'échec est journalisé (`Log::error` + audit
       `queued_payment_rolled_back` sur la session) et le caissier reçoit un
       message ; seuls les messages d'`InvalidArgumentException` /
       `DomainException` lui sont montrés tels quels.
  - Finance est la source de vérité du paiement : l'hôte ne crée pas de
    paiement de son côté, il ne fait qu'avancer la visite. Il ne doit rien
    envoyer au-dehors avant la validation de la transaction (`afterCommit`).
- **Bureau du caissier** : il liste **toutes** ses sessions ouvertes et ne
  propose à l'ouverture que les caisses actives **libres** et auxquelles il est
  affecté. Quand la limite est atteinte, le formulaire cède la place à un
  message. Depuis une session, un bandeau de boutons liste **toutes** ses
  caisses ouvertes, celle qu'il regarde comprise et marquée comme active ; il
  n'apparaît que s'il en tient plus d'une. Avec la limite à 1 — le cas
  courant — le bureau redirige directement vers l'unique session, exactement
  comme avant.
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
- **Un seul script**, dans `resources/views/partials/tariff-fill.blade.php` : le
  report du tarif de l'acte choisi dans le champ « Montant » de l'encaissement.
  Une vingtaine de lignes sans bibliothèque, et la page reste entièrement
  utilisable sans lui — le tarif figure aussi dans l'intitulé de chaque option.
  Il ne piétine jamais une saisie manuelle : il ne remplit que si le champ est
  vide ou porte encore une valeur qu'il avait lui-même posée, et il ne vide
  jamais le champ.
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

> **Sur Windows, `vendor` vit dans un volume Docker, pas dans le dossier du
> projet.** Le projet est monté depuis Windows (`.:/app`) et, sur Docker
> Desktop, chaque accès fichier y coûte ~8 ms contre 0,01 ms sur le disque du
> conteneur. Comme `vendor` contient des dizaines de milliers de fichiers, le
> démarrage de Laravel y passait plus de deux secondes **par page**.
>
> Le volume `vendor` et `docker/php.ini` (qui active OPcache pour le CLI,
> indispensable puisque `artisan serve` est un processus CLI) ramènent une
> page de ~2 200 ms à ~180 ms, et la suite de tests de ~4 min à ~35 s.
>
> Conséquence pratique : après un changement de dépendances, ou si vous
> supprimez le volume, relancez `docker compose run --rm app composer install`
> pour le repeupler. Le `vendor` de l'hôte reste intact pour votre IDE, mais
> le conteneur ne le voit plus. La base SQLite de démonstration vit elle aussi
> dans ce volume : `composer serve:docker` la reconstruit à chaque démarrage.

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
