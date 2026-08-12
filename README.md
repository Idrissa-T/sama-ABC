# Plateforme web de comptabilité par activités (ABC)

**A & P BRIQUES SUARL** — briqueterie, Rufisque (Sénégal)

Projet n° 26 — *Contrôle de gestion : Activity Based Costing*
Master CCA — École Supérieure Polytechnique de Dakar — Année 2025-2026

---

## 1. Objet de la plateforme

L'application implémente la méthode ABC de Cooper et Kaplan pour une
briqueterie, et la confronte systématiquement à la méthode traditionnelle
d'imputation par clé unique.

Chaîne de calcul :

```
RESSOURCES  --(clés de répartition %)-->  ACTIVITÉS  --(inducteurs)-->  OBJETS DE COÛT
```

La plateforme révèle les **subventionnements croisés** : les produits que la
méthode classique présente comme rentables alors qu'ils détruisent de la valeur.

---

## 2. Prérequis

| Composant | Version minimale |
|---|---|
| XAMPP | 8.2 |
| Apache | 2.4 |
| PHP | 8.0 (extension `pdo_mysql` activée) |
| MySQL / MariaDB | 8.0 / 10.4 |
| Navigateur | Chrome, Firefox ou Edge à jour |

Bootstrap 5.3 et Chart.js 4.4 sont chargés depuis un CDN : une connexion
internet est nécessaire au premier affichage.

---

## 3. Installation

### 3.1 Déploiement des fichiers

Copier le dossier du projet dans la racine web de XAMPP :

- Windows : `C:\xampp\htdocs\abc-costing`
- Linux : `/opt/lampp/htdocs/abc-costing`
- macOS : `/Applications/XAMPP/htdocs/abc-costing`

### 3.2 Création de la base de données

1. Démarrer **Apache** et **MySQL** depuis le panneau de contrôle XAMPP.
2. Ouvrir <http://localhost/phpmyadmin>.
3. Onglet **Importer** → sélectionner `sql/abc_costing.sql` → **Exécuter**.

Le script crée la base `abc_costing`, ses 11 tables, ses 9 vues de calcul et
charge le jeu de démonstration (2 périodes, 6 ressources, 8 activités,
4 produits).

> Le script commence par `DROP DATABASE IF EXISTS abc_costing` : le réimporter
> réinitialise entièrement les données.

### 3.3 Paramétrage de la connexion

Les paramètres XAMPP par défaut sont déjà en place dans
`config/config.php`. Ne les modifier que si votre MySQL est protégé :

```php
const DB_HOST = '127.0.0.1';
const DB_NAME = 'abc_costing';
const DB_USER = 'root';
const DB_PASS = '';          // renseigner si un mot de passe root est défini
```

Avant la remise, passer `MODE_DEBUG` à `false`.

### 3.4 Accès

<http://localhost/abc-costing/login.php>

---

## 4. Utilisateurs de test

| Identifiant | Mot de passe | Rôle | Droits |
|---|---|---|---|
| `admin` | `Admin@2026` | ADMIN | Tout, dont périodes, utilisateurs et journal d'audit |
| `controleur` | `Controle@2026` | CONTROLEUR | Saisie et modification des données de gestion |
| `lecteur` | `Lecture@2026` | LECTEUR | Consultation et exports uniquement |

Les mots de passe sont stockés hachés avec `password_hash()` (bcrypt, coût 10).
Le réhachage est automatique si le coût évolue.

---

## 5. Vérification après installation

À exécuter dans l'onglet SQL de PhpMyAdmin :

```sql
-- Doit ne renvoyer aucune ligne : chaque ressource est répartie à 100 %
SELECT * FROM v_controle_cles WHERE statut = 'ANOMALIE';

-- L'écart d'imputation doit valoir 0 : tout est imputé aux objets de coût
SELECT * FROM v_controle_bouclage;
```

Résultats attendus pour juin 2026 :

| Contrôle | Valeur attendue |
|---|---|
| Total des charges indirectes | 48 000 000 FCFA |
| Total imputé aux produits | 48 000 000 FCFA |
| Écart d'imputation | 0 |
| Coût de l'activité A4 (pressage) | 15 882 000 FCFA |
| Coût unitaire de l'inducteur A3 | 19 120 FCFA / changement de moule |
| Produits déficitaires en ABC | 2 (B3 et B4) |

---

## 6. Structure du projet

```
abc-costing/
├── config/
│   ├── config.php          Constantes, session, fuseau Africa/Dakar
│   └── database.php        Connexion PDO en singleton
├── includes/
│   ├── functions.php       Échappement XSS, formatage, CSRF, pagination, audit
│   ├── auth.php            Connexion, expiration de session, gardes de rôles
│   ├── abc.php             Accès aux vues de calcul ABC
│   └── layout.php          En-tête, navigation par rôle, pied de page
├── assets/
│   ├── css/style.css       Charte terre cuite / béton, styles d'impression
│   └── js/app.js           Contrôle 100 % temps réel, 4 graphiques Chart.js
├── sql/
│   └── abc_costing.sql     Schéma, vues et données de démonstration
├── login.php               Connexion
├── logout.php              Déconnexion explicite
├── index.php               Tableau de bord
├── cles.php                Matrice des clés de répartition
├── 403.php                 Accès refusé
└── README.md
```

---

## 7. Sécurité mise en œuvre

| Risque | Parade |
|---|---|
| Injection SQL | Requêtes préparées PDO, `ATTR_EMULATE_PREPARES = false` |
| XSS | `htmlspecialchars()` via `e()` sur toute sortie ; JSON encodé avec `JSON_HEX_TAG` |
| Mots de passe | `password_hash()` bcrypt, jamais de stockage en clair |
| CSRF | Jeton par session vérifié avec `hash_equals()` sur chaque POST |
| Fixation de session | `session_regenerate_id(true)` à la connexion |
| Vol de cookie | Cookie `HttpOnly`, `SameSite=Lax`, `Secure` si HTTPS |
| Session abandonnée | Expiration après 30 minutes d'inactivité |
| Élévation de privilège | `exigerRole()` / `exigerEcriture()` sur chaque page sensible |
| Énumération de comptes | Message d'erreur identique pour login inconnu et mot de passe faux |
| Traçabilité | Journal d'audit horodaté avec adresse IP |

---

## 8. Modèle de données

11 tables liées par clés étrangères :

`utilisateurs`, `periodes`, `ressources`, `ressource_montants`, `activites`,
`cles_ressources`, `inducteurs`, `objets_cout`, `couts_directs`,
`consommations`, `journal_audit`.

Le moteur de calcul est implémenté dans **9 vues SQL**, et non en PHP : la règle
de calcul est ainsi définie une seule fois et alimente identiquement les écrans,
les graphiques et les exports.

| Vue | Rôle |
|---|---|
| `v_controle_cles` | Contrôle « somme des clés = 100 % » par ressource |
| `v_cout_activite` | Coût de chaque activité = Σ (ressource × clé %) |
| `v_volume_inducteur` | Volume total consommé par inducteur |
| `v_cout_inducteur` | Coût unitaire d'inducteur + indicateurs TDABC |
| `v_imputation_abc` | Détail objet × activité |
| `v_indirect_abc` | Charges indirectes ABC par objet |
| `v_totaux_periode` | Assiette de la méthode classique |
| `v_comparaison_couts` | Synthèse classique vs ABC, marges, subventionnement |
| `v_controle_bouclage` | Contrôle « total imputé = total des charges » |

---

## 9. Règles de gestion

1. Chaque ressource doit être répartie à **100 %** entre les activités.
   La sauvegarde est refusée sinon, côté client comme côté serveur.
2. Une **période clôturée** est en lecture seule.
3. Le total imputé aux objets de coût doit égaler le total des charges
   indirectes (écart de bouclage nul).
4. Chaque activité possède **un seul inducteur** (contrainte d'unicité).
5. Toute action sensible est inscrite au journal d'audit.

---

## 10. Auteur

Étudiant du Master CCA — École Supérieure Polytechnique de Dakar
Enseignant : M. Ousmane LY
