# modules

Modules autonomes de **Keneya Workflow**, l'application de gestion hospitalière
de l'Hôpital Fousseyni Daou de Kayes.

Chaque module vit dans **sa propre branche**. Cette branche `main` ne sert
qu'à les lister : elle ne contient aucun code.

## Modules

| Module | Branche | État | Description |
|---|---|---|---|
| Finance, Caisse et Facturation | [`finance-caisse`](../../tree/finance-caisse) | v0.5.0 | Caisse (sessions, encaissements, décaissements, clôture avec écart, validation), catalogue des actes et tarifs, journal d'audit. |

## Récupérer un module

```bash
git clone --branch finance-caisse --single-branch https://github.com/LoloGH/modules.git finance-caisse
```

## Ajouter un module

Un module est un paquet Laravel autonome qui se monte dans l'application hôte.
Il arrive ici sur une branche à son nom, sans historique commun avec les
autres : les branches sont volontairement indépendantes.

```bash
cd chemin/vers/mon-module
git remote add modules https://github.com/LoloGH/modules.git
git push modules main:mon-module
```

Pensez ensuite à ajouter une ligne au tableau ci-dessus.
