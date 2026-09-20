# keneya/finance-caisse

Module **Finance, Caisse et Facturation** de Keneya. Se monte dans une
application Laravel hôte (Keneya Workflow), comme le module DME.

État : **v0.1.0, squelette** — porte d'entrée, droits, mode autonome. Aucune
table ni écran métier pour l'instant : ils arrivent par tranches.

## Conventions (identiques à DME)

| | Valeur |
|---|---|
| Paquet | `keneya/finance-caisse` |
| Namespace | `Keneya\FinanceCaisse\` |
| Fournisseur de services | `FinanceServiceProvider` (découvert automatiquement) |
| URL | `/finance` |
| Noms de routes | `finance.` |
| Vues et traductions | `finance::` |
| Tables (à venir) | `finance_` |
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
3. `composer update keneya/finance-caisse`, puis
   `php8.4 artisan finance:sync-permissions`.
4. Déclarer la décision d'accès de l'hôte (résolveur ou capacité) et, si
   besoin, la correspondance des rôles dans `config/finance.php`
   (`'roles' => ['caissier' => 'cashier']`).

Versions : le module demande les mêmes plages que DME pour les paquets
partagés (`spatie/laravel-permission`), et ne doit pas en ajouter d'autres
sans raison.
