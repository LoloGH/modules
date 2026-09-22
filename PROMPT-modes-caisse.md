# Tâche : module Finance de Keneya — « Retrait HFD + sessions de caisse multiples par caissier »

Tu travailles dans le paquet Laravel `keneya/finance-caisse`
(`C:\Projects\modules\finance-caisse`). Module autonome monté dans une
application hôte, déjà à la version 0.5.0 (caisse complète + catalogue des
actes), 159 tests au vert. Tu fais UNE tranche courte, en deux volets. Tu ne
touches à RIEN d'autre.

## Périmètre — à lire absolument

- **N'ajoute aucune fonction non demandée ici.** Pas de tableau de bord, pas
  de nouvelle page, pas de refonte visuelle, pas de composant décoratif.
- **Ne retouche pas l'habillage** (mise en page, CSS, composants de vue
  existants) au-delà du strict nécessaire pour afficher plusieurs sessions.
- **Ne touche pas au patient** (le champ patient reste tel quel pour
  l'instant ; il sera repris dans une tranche ultérieure avec le module DME).
- **N'ajoute aucune dépendance Composer.**
- Ne committe pas : laisse-moi relire.

## Avant d'écrire, lis (pour imiter les conventions exactes)

- `README.md`
- `src/Actions/OpenCashSession.php` — la règle actuelle à faire évoluer.
- `src/Models/CashSession.php`, `src/Models/CashRegister.php`
- `src/Http/Controllers/CashDeskController.php` + `resources/views/cash/index.blade.php`
  + `resources/views/cash/session.blade.php`
- `src/Support/Rbac.php`
- `tests/Feature/CashSessionOpeningTest.php` + `tests/Feature/Http/CashDeskHttpTest.php`
- `config/finance.php`

## Conventions non négociables (déjà en place)

- Namespace `Keneya\FinanceCaisse\`, tables `finance_`, routes `finance` /
  nommées `finance.`, vues `finance::`, textes en **français**.
- Montants en **entiers** (franc CFA). Écritures immuables. Actions
  transactionnelles qui tracent via `Auditor`. Droits **par route**
  (`can:`), règles métier **dans l'action** (`FinanceRuleViolation`, se rend
  seule à l'écran). Routes par contrôleur, jamais de closure. Données de
  départ par commande idempotente `finance:sync-…`, jamais de seeder sur la
  prod. FK actives en test, `restrictOnDelete` (pas de cascade sur des
  données financières). SQLite en test : pas de SQL spécifique MySQL.

---

## VOLET 1 — Retirer toute mention « HFD »

Le projet est un produit générique : plus aucune référence à un
établissement précis. Cherche « HFD » (insensible à la casse) dans TOUT le
module — code, commentaires, textes d'interface, migrations, seeders/commandes,
données de démonstration, README, tests — et retire-le ou remplace-le par une
formulation neutre (« l'établissement », « Centre de démonstration », etc.).

- Vérifie en particulier les préfixes d'identifiants dans `config/finance.php`
  et les données de `workbench/app/Console/Commands/DemoSetup.php`.
- Ne casse aucun test : si un test attend une chaîne contenant « HFD »,
  corrige aussi le test avec la nouvelle valeur neutre.
- À la fin, `grep -ri "hfd"` sur le module (hors `vendor/`) ne doit plus rien
  renvoyer.

---

## VOLET 2 — Sessions de caisse multiples par caissier

### Le besoin
Aujourd'hui `OpenCashSession` interdit à un caissier d'avoir plus d'une
session ouverte. Résultat : une caissière qui tient Caisse Ticket ET Caisse
Services ne peut pas travailler sur les deux à la fois. On veut rendre cela
configurable, sans jamais permettre à deux personnes de tenir la même caisse.

### Le réglage — un défaut d'établissement, surchargeable par caissier
- Ajoute dans `config/finance.php` un réglage
  `cash.max_open_sessions_per_cashier` (entier, défaut **1**). C'est le
  nombre de sessions qu'un caissier peut avoir ouvertes en même temps par
  défaut dans cet établissement.
- Permets de **surcharger ce nombre pour un caissier donné**. Choisis le
  support le plus simple et cohérent avec l'architecture existante : une
  petite table `finance_cashier_settings` (`cashier_id` chaîne unique,
  `max_open_sessions` entier nullable, timestamps) lue au moment de
  l'ouverture. `null` ou absence de ligne = on applique le défaut de config.
  Documente ce choix dans le README.
- Prévois une méthode claire « limite effective pour ce caissier » (surcharge
  si présente, sinon défaut de config).

### La règle d'ouverture (dans `OpenCashSession`)
Remplace la règle « une seule session par caissier » par :
1. **Une seule session ouverte par caisse** — CONSERVÉE telle quelle (deux
   personnes ne tiennent jamais le même tiroir).
2. Le caissier ne peut pas dépasser **sa limite effective** de sessions
   ouvertes simultanées. À la limite atteinte : `FinanceRuleViolation` avec un
   message clair (« Vous avez déjà N session(s) ouverte(s), limite atteinte. »).
3. Un même caissier ne peut pas ouvrir **deux fois la même caisse** (déjà
   couvert par la règle 1, mais vérifie le message).
Garde le verrou sur la caisse et la transaction. Garde l'audit `session_opened`.

### Le bureau du caissier (`CashDeskController::index` + `cash/index`)
Aujourd'hui, s'il a une session ouverte, il est redirigé vers elle. Nouveau
comportement :
- Le bureau liste **toutes ses sessions ouvertes** (0, 1 ou plusieurs), avec
  pour chacune un accès direct à sa page de session.
- S'il peut encore en ouvrir une (limite non atteinte) ET qu'il reste des
  caisses actives libres, le formulaire d'ouverture reste visible, ne
  proposant que les caisses **sans session ouverte**.
- Depuis la page d'une session (`cash/session`), il doit pouvoir revenir au
  bureau et basculer vers ses autres sessions ouvertes (un simple bandeau ou
  un lien « Mes sessions ouvertes : Caisse Ticket · Caisse Services » suffit —
  reste sobre, pas de nouveau design).
- Ne casse pas le cas mono-session : avec la limite à 1, le comportement doit
  rester équivalent à aujourd'hui (une seule session, accès direct).

### Administration de la limite par caissier (léger)
Ajoute, sous `can:finance.registers.manage` (déjà réservé à l'administrateur),
un moyen simple de fixer la surcharge d'un caissier. Reste minimal : réutilise
un écran existant plutôt que d'en inventer un lourd. Trace toute écriture via
`Auditor` (`cashier_limit_set`). Si tu crées une permission dédiée, ajoute-la
dans `Rbac` et donne-la à l'administrateur.

## Tests (obligatoires)
- Ouverture : avec limite 1, la 2ᵉ session du même caissier est refusée
  (comportement actuel préservé). Avec limite 2, il peut ouvrir 2 caisses
  différentes, mais pas une 3ᵉ, et jamais deux fois la même caisse.
- La surcharge par caissier l'emporte sur le défaut de config, dans les deux
  sens (plus permissif et plus restrictif).
- Deux caissiers différents ne peuvent jamais ouvrir la même caisse.
- Le bureau affiche bien plusieurs sessions ouvertes et ne propose que les
  caisses libres.
- Le VOLET 1 n'a rien cassé.
- Conserve 100 % de réussite. Ajoute tes tests aux 159 existants.

## Déroulé que TU exécutes toi-même
1. `docker compose -f docker-compose.yml run --rm app composer install` si besoin.
2. Écris le code et les tests des deux volets.
3. `docker compose -f docker-compose.yml run --rm app composer test` — tout au
   vert, corrige tes propres erreurs, relance jusqu'au bout.
4. `docker compose -f docker-compose.yml run --rm app vendor/bin/pint`.
5. Mets à jour `README.md` (le réglage, la table, le comportement du bureau) et
   `config/finance.php` version → `0.6.0` (laisse `composer.json` tel quel).
6. Ne committe pas.

## Pièges déjà rencontrés sur ce module
- Testbench compile les vues en cache : le socle de test met `view.compiled`
  dans un dossier par PID (`tests/TestCase.php`), garde ce mécanisme.
- FK actives en test (`DB_FOREIGN_KEYS=true`), déclare des `onDelete`
  cohérents (`restrictOnDelete`).
- Montants entiers, jamais de float.
- Un message d'une requête précédente peut rester affiché au tour suivant :
  teste sur des cibles précises (option de liste, pas texte global de page).

Quand tout est vert et Pint propre, arrête-toi et résume-moi : ce que tu as
retiré au VOLET 1, la table/le réglage ajoutés, la nouvelle règle d'ouverture,
comment le bureau affiche les sessions multiples, le nombre de tests, et toute
décision que tu as dû prendre seul.
