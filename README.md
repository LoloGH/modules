# keneya/finance-caisse

Module **Finance, Caisse et Facturation** de Keneya. Se monte dans une
application Laravel hôte (Keneya Workflow), comme le module DME.

État : **v0.3.0, caisse (règles métier)** — porte d'entrée, droits, mode autonome, numérotation
sans doublon, journal d'audit non modifiable, moyens de paiement configurables, sessions de caisse (ouverture, encaissements,
décaissements, clôture avec écart, validation, annulations tracées).
Les règles sont codées et testées ; les écrans arrivent avec la tranche suivante.

## Conventions (identiques à DME)

| | Valeur |
|---|---|
| Paquet | `keneya/finance-caisse` |
| Namespace | `Keneya\FinanceCaisse\` |
| Fournisseur de services | `FinanceServiceProvider` (découvert automatiquement) |
| URL | `/finance` |
| Noms de routes | `finance.` |
| Vues et traductions | `finance::` |
| Tables | `finance_` (`sequences`, `audit_logs`, `payment_methods`, `cash_registers`, `cash_sessions`, `payments`, `disbursements`) |
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
  `finance:sync-permissions` et `finance:sync-payment-methods`. Elles ne modifient
  jamais ce que l'établissement a déjà réglé.
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
  Ces actions vérifient les règles métier ; les droits (qui peut appeler quoi) se
  contrôlent dans les contrôleurs et policies de la tranche suivante.
- **Montants en entiers** (franc CFA : aucune décimale).
- **Écritures immuables** (tranches suivantes) : une facture validée ou un
  paiement ne se modifie ni ne se supprime ; les corrections passent par
  annulation, avoir, remboursement ou ajustement, liés à l'opération d'origine.
- Le module ne dépend pas de `keneya/dme` : les branchements sur les actes
  médicaux viendront dans un sous-dossier dédié, actif seulement si DME est
  présent.
- Routes par contrôleur, jamais par closure (sinon `route:cache` échoue chez
  l'hôte).

## Développer et tester

### En local

```bash
composer install
composer test
composer serve        # http://127.0.0.1:8000/finance (mode autonome)
```

### Sous Docker (PHP 8.4)

```bash
docker compose build
docker compose run --rm app composer install
docker compose run --rm app composer test
docker compose run --rm --service-ports app composer serve:docker
```

## Intégration dans l'application hôte

1. Copier le dossier dans `keneya_wf-mod/modules/finance-caisse`.
2. Dans le `composer.json` de l'hôte, ajouter un dépôt de type `path`
   (`./modules/finance-caisse`, `symlink: true`) et `"keneya/finance-caisse": "@dev"`
   dans `require`.
3. `composer update keneya/finance-caisse`, `php8.4 artisan migrate --force`, puis
   `php8.4 artisan finance:sync-permissions` et `php8.4 artisan finance:sync-payment-methods`.
4. Déclarer la décision d'accès de l'hôte (résolveur ou capacité) et, si
   besoin, la correspondance des rôles dans `config/finance.php`
   (`'roles' => ['caissier' => 'cashier']`).

Versions : le module demande les mêmes plages que DME pour les paquets
partagés (`spatie/laravel-permission`), et ne doit pas en ajouter d'autres
sans raison.
