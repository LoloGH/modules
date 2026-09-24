# Keneya Pharmacy — analyse de l'existant et feuille de route d'intégration

Document de travail. Il répond à la règle 37 du cahier des charges : **comprendre
l'existant avant de modifier quoi que ce soit**, puis proposer le parcours
fonctionnel complet avant l'implémentation.

---

## 1. Ce qui existe déjà, et qu'on ne refera pas

### 1.1 Keneya Workflow (le cœur)

| Domaine | Ce qui existe | Conséquence pour la pharmacie |
| --- | --- | --- |
| **Patient** | `patients`, avec un `patient_code` attribué une fois pour toutes (préfixe `PAT-`), recherche, portail, historique | La pharmacie **ne crée aucune table patient**. Elle copie le `patient_code` et demande le reste à l'hôte. |
| **Parcours** | `visits`, files d'attente par service, appel, renvois (`referrals`), hospitalisation, urgences, maternité, bloc | La file de la pharmacie sera **alimentée par l'hôte**, comme la file de caisse de Finance. |
| **Personnel et droits** | `staff_types` avec capacités, rôles spatie partagés par tous les modules | Les rôles pharmaceutiques s'ajoutent au même référentiel, sans doublon. |
| **Notifications** | `StaffNotification` | Les alertes de la pharmacie pourront s'y déverser, plus tard. |
| **Stock** | **rien** | Tout le cœur pharmaceutique est à construire. Aucun risque de doublon. |

### 1.2 Module DME (`keneya/dme`)

- `dme_prescriptions` : numéro, patient, consultation, prescripteur, dates,
  **statut** (`draft`, `validated`, `dispensed`, `cancelled`), instructions,
  **alertes d'allergie** et leur justification, `validated_by`, **`dispensed_by`
  et `dispensed_at`**.
- `dme_prescription_items` : `medication_name`, `dosage`, `form`, `route`,
  `frequency`, `duration`, `quantity`, `instructions`, **`is_substitutable`**.
- `dme_medications` : les traitements en cours du patient ; `allergies`.
- `PatientIdentifierResolver` : le pont entre un patient DME et le patient de
  l'hôte (`source_system` = `keneya_workflow`, valeur = `patient_code`).

**Lecture** : le DME sait déjà prescrire, et il attend déjà qu'on lui dise
qu'une ordonnance a été délivrée. Il ne sait rien du stock, des lots, ni des
reliquats — et ce n'est pas son rôle.

### 1.3 Module Finance (`keneya/finance-caisse`, v0.22.0)

Caisse (sessions, tiroir, écarts, validation), file de caisse fournie par
l'hôte, factures, assurances et prises en charge par acte, créances, remises et
remboursements, avances et comptes patients, rapports, **exports comptables**,
paramètres, journal d'audit, alertes.

**Point d'attention** : une facture Finance se compose de **lignes d'actes du
catalogue Finance**, avec un tarif. Un produit pharmaceutique n'est pas un acte.
Facturer une dispensation demandera donc une évolution côté Finance (voir 3.3).

### 1.4 Module Pharmacie (ce qui est déjà posé, v0.1.0)

Montage, porte d'entrée décidée par l'hôte, quatre rôles avec séparation des
tâches, **file d'attente par contrat** (`PharmacyQueueProvider`), **point de
sortie neutre de ce qui doit être payé** (`SaleSink`), journal d'audit, hôte de
démonstration Docker autonome. 12 tests.

---

## 2. Ce qui manque

1. **Tout le stock** : catalogue, lots, emplacements, mouvements, inventaires.
2. **Le lien prescription → dispensation** : le DME ne connaît ni les produits
   du catalogue pharmaceutique, ni les reliquats.
3. **La facturation des produits** : Finance ne facture que des actes.
4. **Le multi-établissement** : rien nulle part. À prévoir **dès les premières
   migrations**, car l'ajouter après coup coûte une réécriture.
5. **Les documents** : bons de commande, de réception, de transfert, fiches
   d'inventaire, documents de destruction et de rappel.

---

## 3. Décisions d'architecture à acter avant de coder

Chacune est une décision de produit, pas de facilité technique. Ma
recommandation est donnée ; elles seront confirmées avant la tranche concernée.

### 3.1 Le patient reste à l'hôte

La pharmacie ne stocke qu'un `patient_id` (le `patient_code` de WorkFlow) et un
nom copié pour l'historique. Tout le reste — identité, allergies, traitements en
cours — se demande à l'hôte par un contrat `PatientDirectory`, implémenté
au-dessus de WorkFlow et du DME. **Aucune troisième table patient.**

### 3.2 Les prescriptions se lisent, ne se dupliquent pas

Deux contrats, implémentés par l'hôte au-dessus du DME :

- `PrescriptionProvider` — lire les ordonnances à servir (patient, prescripteur,
  lignes, posologie, substituable, alertes d'allergie) ;
- `PrescriptionSink` — dire au DME ce qui a été servi, et donc faire passer
  l'ordonnance à « délivrée » quand elle est complète.

**Le reliquat vit dans la pharmacie**, pas dans le DME : c'est un fait de stock,
pas un fait médical. Le DME garde son statut global.

### 3.3 L'argent reste à Finance

Deux chemins, selon le cas :

- **Comptant au comptoir** : la dispensation crée un passage dans la file de
  caisse de Finance, avec son montant attendu ; le caissier encaisse dans
  Finance, avec sa session, son tiroir, son reçu et son audit.
- **Sur facture (prise en charge, assurance, hospitalisation)** : demande une
  évolution de Finance — accepter des **lignes de facture venues d'un autre
  module** (libellé, quantité, prix, centre analytique), au lieu d'actes du seul
  catalogue Finance. C'est une tranche à part entière, côté Finance.

**Aucune caisse dans la pharmacie.** Deux caisses, ce sont deux vérités sur
l'argent.

### 3.4 Le stock est un grand livre

Chaque mouvement est une écriture **immuable** (entrée, sortie, transfert,
ajustement, perte, retour), avec son auteur, son motif et sa pièce. Les
quantités affichées sont dénormalisées par lot et par emplacement, recalculables
des mouvements, et modifiées **sous verrou**. Aucune suppression destructive :
on annule par une écriture inverse, jamais en effaçant.

C'est la leçon tirée de la caisse : un solde qui ne se recalcule pas des
écritures finit toujours par mentir.

### 3.5 FEFO proposé, jamais imposé en silence

Le système propose d'office le lot qui périme le premier. Le pharmacien peut
choisir un autre lot : c'est alors **tracé avec un motif**. Un lot périmé ou
bloqué n'est jamais proposé, et sa dispensation est refusée.

### 3.6 Multi-établissement dès la première migration

Toutes les tables portent `facility_id`, et les stocks portent en plus
`location_id` (pharmacie centrale, réserve, comptoir, urgences, maternité,
bloc, hospitalisation…). Tant qu'un seul établissement existe, la colonne vaut
une valeur par défaut — mais elle est là.

---

## 4. Feuille de route

Douze tranches. Chacune est livrable seule, testée, committée, et navigable dans
le module autonome avant toute intégration. L'ordre suit le cycle de vie du
médicament : on ne peut pas dispenser ce qu'on n'a pas reçu, ni recevoir ce qui
n'est pas référencé.

| # | Tranche | Ce qu'elle apporte | Cahier des charges |
| --- | --- | --- | --- |
| 1 | **Catalogue pharmaceutique** | Produits, DCI, formes, dosages, voies, conditionnements, catégories, seuils, codes-barres, natures (médicament, consommable, dispositif, hygiène) | §4, §31 |
| 2 | **Emplacements, lots et stock** | Emplacements, lots (péremption, prix, statut), grand livre des mouvements, stock physique / réservé / disponible | §5, §7, §26 |
| 3 | **Réception, fournisseurs, commandes** | Fournisseurs, demandes, commandes, réceptions avec contrôle et anomalies, entrée en stock par lot | §9, §10, §35 |
| 4 | **Dispensation** | File de travail, sélection FEFO, dispensation complète et partielle, reliquats, substitution tracée, sortie de stock, document de sortie | §8, §12, §13 |
| 5 | **Prescriptions du DME** | Contrats de lecture et de retour, écran « ordonnances à servir », allergies visibles, zéro ressaisie | §11, §14, §29 |
| 6 | **Argent : comptant et facture** | Panier, vente directe, envoi vers la caisse Finance ; puis lignes de facture venues de la pharmacie (prise en charge, assurance) | §15, §29 |
| 7 | **Expirations et alertes** | Surveillance des péremptions à trois niveaux, ruptures, seuils, lots bloqués, cloche et file « à traiter » | §6, §28, §32 |
| 8 | **Transferts entre emplacements** | Cycle demande → validation → sortie → réception, stock en transit | §17 |
| 9 | **Inventaires, pertes et destructions** | Inventaires complets et partiels, écarts justifiés et validés, pertes, casse, destructions avec document | §18, §19 |
| 10 | **Contrôle renforcé, rappels de lots, pharmacovigilance** | Produits à surveillance particulière (règles configurables), blocage et rappel d'un lot avec la liste des patients servis, signalement d'effet indésirable | §20, §21, §22 |
| 11 | **Analyse, prévision, rapports, KPI** | Consommation par période, service, catégorie ; rotation, taux de rupture et de péremption, valeur des pertes ; besoin estimé et risque de rupture ; rapports filtrables | §23, §24, §25, §36 |
| 12 | **Intégration à WorkFlow** | Fournisseur de services de l'hôte : accès, file, patients, prescriptions, caisse ; entrée de menu ; permissions ; migrations | §29, §37 |

### Ce que chaque tranche livre, sans exception

- les tables et leurs contraintes, avec `facility_id` ;
- les actions métier, avec leurs refus explicites et leurs messages en français ;
- les écrans, lisibles et rapides, sans dépendance front nouvelle ;
- **l'audit** de chaque opération sensible ;
- les droits, route par route ;
- les tests (le module est aujourd'hui à 12 tests ; chaque tranche ajoute les
  siens, et la suite reste verte) ;
- la documentation dans le `README`, et un commit qui explique le pourquoi.

### Deux jalons de démonstration

- **Après la tranche 4** : une pharmacie peut référencer, recevoir, stocker et
  délivrer, avec lots et FEFO — sans WorkFlow, sur Docker.
- **Après la tranche 6** : le cycle complet de l'établissement, argent compris.
  C'est le moment naturel pour brancher WorkFlow (tranche 12 peut alors être
  anticipée si le terrain l'exige).

---

## 5. Risques identifiés, et comment on les tient

| Risque | Comment on le tient |
| --- | --- |
| Le stock ment (deux vérités entre quantités et mouvements) | Grand livre immuable, quantités recalculables, verrous, tests qui rejouent les mouvements |
| Le reliquat se perd entre DME et pharmacie | Le reliquat vit dans la pharmacie ; le DME ne porte qu'un statut global |
| La facturation des produits force une caisse locale | Extension de Finance (lignes venues d'un module) plutôt qu'un second tiroir |
| Le multi-établissement arrive trop tard | `facility_id` dès la première migration |
| L'écran devient un dossier médical | Le pharmacien ne voit du patient que ce qui sert à délivrer |
| Les règles réglementaires se figent dans le code | Tout ce qui relève de la réglementation est configurable (§20, §34) |

---

## 6. Où en est la construction

Les tranches **1 à 11 sont construites et testées** : catalogue, stock,
approvisionnement, dispensation, ordonnances, envoi à la caisse, alertes,
transferts, inventaires et pertes, contrôle renforcé et rappels de lots,
analyse et prévision. Chaque tranche a ses tests, et la suite complète est
verte.

La **tranche 12 — intégration à WorkFlow** est volontairement gardée pour la
fin : le module se teste d'abord seul, sur sa propre pile Docker, avant
d'être branché à l'application hôte.

### Essayer le module seul

```
docker compose up -d
docker compose run --rm app php vendor/bin/testbench pharmacie:demo-setup
docker compose run --rm app php vendor/bin/testbench pharmacie:demo-data
```

Puis <http://localhost:8001/dev> : on y choisit un profil (pharmacien,
préparateur, magasinier, administrateur) et on ouvre `/pharmacie`. Les
données de démonstration passent par les actions réelles — réception,
transfert, dispensation, perte — jamais par des écritures directes en base.

Après une mise à jour du module, `pharmacie:sync-permissions` dit quelles
permissions nouvelles manqueraient aux rôles déjà en place ; il ne les
accorde que si on le lui demande (`--grant-missing`).
