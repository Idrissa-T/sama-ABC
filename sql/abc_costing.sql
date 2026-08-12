-- =====================================================================
--  PLATEFORME WEB DE COMPTABILITE PAR ACTIVITES (ABC)
--  Master CCA - Ecole Superieure Polytechnique de Dakar
--  Projet n° 26 - Controle de gestion / Activity Based Costing
--  Stack : XAMPP (Apache + MySQL 8 / MariaDB 10.4+ + PHP 8)
--  Fichier : abc_costing.sql  (schema + vues + donnees de demonstration)
-- =====================================================================
--  Chaine de calcul implementee :
--    RESSOURCES --(cles de repartition %)--> ACTIVITES
--    ACTIVITES  --(inducteurs de cout)---->  OBJETS DE COUT
--  Comparaison systematique avec la methode classique (cle unique = MOD)
--  pour reveler les subventionnements croises.
-- =====================================================================

DROP DATABASE IF EXISTS abc_costing;
CREATE DATABASE abc_costing
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE abc_costing;

SET FOREIGN_KEY_CHECKS = 1;
SET SQL_MODE = 'STRICT_ALL_TABLES';

-- =====================================================================
--  1. TABLES
-- =====================================================================

-- ---------------------------------------------------------------------
-- 1.1 Utilisateurs et roles (3 profils differencies)
-- ---------------------------------------------------------------------
CREATE TABLE utilisateurs (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  login             VARCHAR(50)  NOT NULL,
  mot_de_passe      VARCHAR(255) NOT NULL COMMENT 'password_hash() PHP - bcrypt',
  nom_complet       VARCHAR(120) NOT NULL,
  email             VARCHAR(150) NOT NULL,
  role              ENUM('ADMIN','CONTROLEUR','LECTEUR') NOT NULL DEFAULT 'LECTEUR',
  actif             TINYINT(1)   NOT NULL DEFAULT 1,
  derniere_connexion DATETIME NULL,
  date_creation     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_utilisateurs_login (login),
  UNIQUE KEY uk_utilisateurs_email (email)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.2 Periodes d'analyse (verrouillables)
-- ---------------------------------------------------------------------
CREATE TABLE periodes (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20)  NOT NULL COMMENT 'ex : 2026-06',
  libelle       VARCHAR(80)  NOT NULL,
  date_debut    DATE NOT NULL,
  date_fin      DATE NOT NULL,
  statut        ENUM('OUVERTE','CLOTUREE') NOT NULL DEFAULT 'OUVERTE',
  date_creation DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_periodes_code (code),
  CONSTRAINT ck_periodes_dates CHECK (date_fin >= date_debut)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.3 Ressources consommees (charges indirectes a ventiler)
-- ---------------------------------------------------------------------
CREATE TABLE ressources (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20)  NOT NULL,
  libelle       VARCHAR(120) NOT NULL,
  nature        ENUM('PERSONNEL','ENERGIE','IMMOBILIER','AMORTISSEMENT',
                     'FOURNITURE','SERVICE_EXTERIEUR','AUTRE') NOT NULL,
  compte_syscohada VARCHAR(10) NULL COMMENT 'rattachement plan de comptes',
  actif         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_ressources_code (code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.4 Montant de chaque ressource par periode
-- ---------------------------------------------------------------------
CREATE TABLE ressource_montants (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ressource_id  INT UNSIGNED NOT NULL,
  periode_id    INT UNSIGNED NOT NULL,
  montant       DECIMAL(15,2) NOT NULL DEFAULT 0.00 COMMENT 'FCFA',
  UNIQUE KEY uk_rm (ressource_id, periode_id),
  KEY idx_rm_periode (periode_id),
  CONSTRAINT fk_rm_ressource FOREIGN KEY (ressource_id)
    REFERENCES ressources(id) ON DELETE CASCADE,
  CONSTRAINT fk_rm_periode FOREIGN KEY (periode_id)
    REFERENCES periodes(id) ON DELETE CASCADE,
  CONSTRAINT ck_rm_montant CHECK (montant >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.5 Activites (carte des activites)
-- ---------------------------------------------------------------------
CREATE TABLE activites (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20)  NOT NULL,
  libelle       VARCHAR(150) NOT NULL,
  processus     VARCHAR(80)  NOT NULL COMMENT 'regroupement macro',
  type_activite ENUM('PRINCIPALE','SUPPORT') NOT NULL DEFAULT 'PRINCIPALE',
  niveau_hierarchique ENUM('UNITE','LOT','PRODUIT','ENTREPRISE') NOT NULL DEFAULT 'UNITE'
                COMMENT 'hierarchie Cooper & Kaplan',
  actif         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_activites_code (code)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.6 Cles de repartition ressource -> activite (en %)
--     Regle de gestion : SOMME(pourcentage) = 100 % par ressource/periode
--     Le controle est assure par la vue v_controle_cles (ecran d'alerte).
-- ---------------------------------------------------------------------
CREATE TABLE cles_ressources (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ressource_id  INT UNSIGNED NOT NULL,
  activite_id   INT UNSIGNED NOT NULL,
  periode_id    INT UNSIGNED NOT NULL,
  pourcentage   DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
  justification VARCHAR(255) NULL COMMENT 'base de la cle : effectif, m2, kWh...',
  UNIQUE KEY uk_cles (ressource_id, activite_id, periode_id),
  KEY idx_cles_activite (activite_id, periode_id),
  CONSTRAINT fk_cles_ressource FOREIGN KEY (ressource_id)
    REFERENCES ressources(id) ON DELETE CASCADE,
  CONSTRAINT fk_cles_activite FOREIGN KEY (activite_id)
    REFERENCES activites(id) ON DELETE CASCADE,
  CONSTRAINT fk_cles_periode FOREIGN KEY (periode_id)
    REFERENCES periodes(id) ON DELETE CASCADE,
  CONSTRAINT ck_cles_pct CHECK (pourcentage >= 0 AND pourcentage <= 100)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.7 Inducteurs de cout (une unite d'oeuvre par activite)
-- ---------------------------------------------------------------------
CREATE TABLE inducteurs (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  activite_id        INT UNSIGNED NOT NULL,
  libelle            VARCHAR(120) NOT NULL,
  unite_oeuvre       VARCHAR(50)  NOT NULL COMMENT 'commande, reglage, heure...',
  capacite_pratique  DECIMAL(14,4) NULL COMMENT 'TDABC : capacite theorique utilisable',
  UNIQUE KEY uk_inducteurs_activite (activite_id),
  CONSTRAINT fk_ind_activite FOREIGN KEY (activite_id)
    REFERENCES activites(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.8 Objets de cout (produits, clients, canaux, zones)
-- ---------------------------------------------------------------------
CREATE TABLE objets_cout (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(20)  NOT NULL,
  libelle       VARCHAR(150) NOT NULL,
  type_objet    ENUM('PRODUIT','CLIENT','CANAL','ZONE') NOT NULL DEFAULT 'PRODUIT',
  famille       VARCHAR(80)  NULL,
  unite         VARCHAR(30)  NULL COMMENT 'litre, pot, bouteille...',
  actif         TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_objets_code (code),
  KEY idx_objets_type (type_objet)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.9 Couts directs + volumes + prix par objet et periode
-- ---------------------------------------------------------------------
CREATE TABLE couts_directs (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  objet_cout_id     INT UNSIGNED NOT NULL,
  periode_id        INT UNSIGNED NOT NULL,
  quantite_produite DECIMAL(14,4) NOT NULL DEFAULT 0,
  prix_vente_unitaire DECIMAL(12,2) NOT NULL DEFAULT 0,
  cout_matieres     DECIMAL(15,2) NOT NULL DEFAULT 0,
  cout_mod          DECIMAL(15,2) NOT NULL DEFAULT 0
                      COMMENT 'main d oeuvre directe = cle unique methode classique',
  UNIQUE KEY uk_cd (objet_cout_id, periode_id),
  KEY idx_cd_periode (periode_id),
  CONSTRAINT fk_cd_objet FOREIGN KEY (objet_cout_id)
    REFERENCES objets_cout(id) ON DELETE CASCADE,
  CONSTRAINT fk_cd_periode FOREIGN KEY (periode_id)
    REFERENCES periodes(id) ON DELETE CASCADE,
  CONSTRAINT ck_cd_qte CHECK (quantite_produite >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.10 Consommation d'inducteurs par objet de cout
-- ---------------------------------------------------------------------
CREATE TABLE consommations (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  inducteur_id  INT UNSIGNED NOT NULL,
  objet_cout_id INT UNSIGNED NOT NULL,
  periode_id    INT UNSIGNED NOT NULL,
  quantite      DECIMAL(14,4) NOT NULL DEFAULT 0,
  UNIQUE KEY uk_conso (inducteur_id, objet_cout_id, periode_id),
  KEY idx_conso_periode (periode_id),
  KEY idx_conso_objet (objet_cout_id, periode_id),
  CONSTRAINT fk_conso_inducteur FOREIGN KEY (inducteur_id)
    REFERENCES inducteurs(id) ON DELETE CASCADE,
  CONSTRAINT fk_conso_objet FOREIGN KEY (objet_cout_id)
    REFERENCES objets_cout(id) ON DELETE CASCADE,
  CONSTRAINT fk_conso_periode FOREIGN KEY (periode_id)
    REFERENCES periodes(id) ON DELETE CASCADE,
  CONSTRAINT ck_conso_qte CHECK (quantite >= 0)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 1.11 Journal d'audit horodate des actions sensibles
-- ---------------------------------------------------------------------
CREATE TABLE journal_audit (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  utilisateur_id INT UNSIGNED NULL,
  action         VARCHAR(60)  NOT NULL COMMENT 'LOGIN, CREATE, UPDATE, DELETE, EXPORT...',
  table_cible    VARCHAR(60)  NULL,
  id_cible       VARCHAR(40)  NULL,
  details        TEXT NULL,
  adresse_ip     VARCHAR(45)  NULL,
  date_action    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_date (date_action),
  KEY idx_audit_user (utilisateur_id),
  CONSTRAINT fk_audit_user FOREIGN KEY (utilisateur_id)
    REFERENCES utilisateurs(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- =====================================================================
--  2. VUES DE CALCUL  (le moteur ABC est en SQL, PHP ne fait qu'afficher)
--  Note : les colonnes de statut calculees portent un COLLATE explicite.
--  Sans cela, MySQL leve une erreur 1267 (Illegal mix of collations) des
--  qu'on les compare a un parametre lie depuis PHP.
-- =====================================================================

-- 2.1 Controle de coherence des cles : doit valoir 100 % par ressource
CREATE OR REPLACE VIEW v_controle_cles AS
SELECT
  c.periode_id,
  p.code                  AS periode_code,
  r.id                    AS ressource_id,
  r.code                  AS ressource_code,
  r.libelle               AS ressource_libelle,
  SUM(c.pourcentage)      AS total_pourcentage,
  ROUND(100 - SUM(c.pourcentage), 4) AS ecart,
  CASE WHEN ABS(100 - SUM(c.pourcentage)) < 0.0001
       THEN 'CONFORME' ELSE 'ANOMALIE' END
       COLLATE utf8mb4_unicode_ci AS statut
FROM cles_ressources c
JOIN ressources r ON r.id = c.ressource_id
JOIN periodes  p ON p.id = c.periode_id
GROUP BY c.periode_id, p.code, r.id, r.code, r.libelle;

-- 2.2 Cout de chaque activite = SOMME (montant ressource x cle %)
CREATE OR REPLACE VIEW v_cout_activite AS
SELECT
  c.periode_id,
  a.id                AS activite_id,
  a.code              AS activite_code,
  a.libelle           AS activite_libelle,
  a.processus,
  a.type_activite,
  a.niveau_hierarchique,
  ROUND(SUM(rm.montant * c.pourcentage / 100), 2) AS cout_activite
FROM cles_ressources c
JOIN activites a          ON a.id = c.activite_id
JOIN ressource_montants rm ON rm.ressource_id = c.ressource_id
                          AND rm.periode_id   = c.periode_id
GROUP BY c.periode_id, a.id, a.code, a.libelle, a.processus,
         a.type_activite, a.niveau_hierarchique;

-- 2.3 Volume total consomme par inducteur
CREATE OR REPLACE VIEW v_volume_inducteur AS
SELECT
  co.periode_id,
  co.inducteur_id,
  SUM(co.quantite) AS volume_total
FROM consommations co
GROUP BY co.periode_id, co.inducteur_id;

-- 2.4 Cout unitaire d'inducteur = cout activite / volume total
CREATE OR REPLACE VIEW v_cout_inducteur AS
SELECT
  ca.periode_id,
  i.id                 AS inducteur_id,
  ca.activite_id,
  ca.activite_code,
  ca.activite_libelle,
  i.libelle            AS inducteur_libelle,
  i.unite_oeuvre,
  ca.cout_activite,
  COALESCE(v.volume_total, 0) AS volume_total,
  CASE WHEN COALESCE(v.volume_total,0) = 0 THEN NULL
       ELSE ROUND(ca.cout_activite / v.volume_total, 4) END AS cout_unitaire_inducteur,
  i.capacite_pratique,
  CASE WHEN i.capacite_pratique IS NULL OR i.capacite_pratique = 0 THEN NULL
       ELSE ROUND(ca.cout_activite / i.capacite_pratique, 4) END AS taux_capacite_tdabc,
  CASE WHEN i.capacite_pratique IS NULL THEN NULL
       ELSE ROUND((i.capacite_pratique - COALESCE(v.volume_total,0))
                  * (ca.cout_activite / i.capacite_pratique), 2) END
       AS cout_capacite_non_utilisee
FROM v_cout_activite ca
JOIN inducteurs i             ON i.activite_id = ca.activite_id
LEFT JOIN v_volume_inducteur v ON v.inducteur_id = i.id
                              AND v.periode_id   = ca.periode_id;

-- 2.5 Detail de l'imputation ABC : objet x activite
CREATE OR REPLACE VIEW v_imputation_abc AS
SELECT
  co.periode_id,
  co.objet_cout_id,
  o.code    AS objet_code,
  o.libelle AS objet_libelle,
  ci.activite_id,
  ci.activite_code,
  ci.activite_libelle,
  ci.unite_oeuvre,
  co.quantite                       AS quantite_inducteur,
  ci.cout_unitaire_inducteur,
  ROUND(co.quantite * ci.cout_unitaire_inducteur, 2) AS cout_impute
FROM consommations co
JOIN objets_cout o     ON o.id = co.objet_cout_id
JOIN v_cout_inducteur ci ON ci.inducteur_id = co.inducteur_id
                        AND ci.periode_id   = co.periode_id;

-- 2.6 Charges indirectes ABC imputees par objet
CREATE OR REPLACE VIEW v_indirect_abc AS
SELECT
  periode_id,
  objet_cout_id,
  ROUND(SUM(cout_impute), 2) AS indirect_abc
FROM v_imputation_abc
GROUP BY periode_id, objet_cout_id;

-- 2.7 Totaux de periode (assiette de la methode classique)
CREATE OR REPLACE VIEW v_totaux_periode AS
SELECT
  p.id   AS periode_id,
  p.code AS periode_code,
  (SELECT COALESCE(SUM(cd.cout_mod),0)
     FROM couts_directs cd WHERE cd.periode_id = p.id)      AS total_mod,
  (SELECT COALESCE(SUM(cd.cout_matieres),0)
     FROM couts_directs cd WHERE cd.periode_id = p.id)      AS total_matieres,
  (SELECT COALESCE(SUM(ca.cout_activite),0)
     FROM v_cout_activite ca WHERE ca.periode_id = p.id)    AS total_indirect
FROM periodes p;

-- 2.8 Synthese comparative : methode classique vs ABC
--     Subventionnement croise = cout classique - cout ABC
--       > 0 : l objet etait SURCHARGE par la methode classique
--       < 0 : l objet etait SUBVENTIONNE par les autres objets
CREATE OR REPLACE VIEW v_comparaison_couts AS
SELECT
  cd.periode_id,
  t.periode_code,
  o.id                  AS objet_cout_id,
  o.code                AS objet_code,
  o.libelle             AS objet_libelle,
  o.type_objet,
  o.famille,
  cd.quantite_produite,
  cd.prix_vente_unitaire,
  ROUND(cd.quantite_produite * cd.prix_vente_unitaire, 2) AS chiffre_affaires,
  cd.cout_matieres,
  cd.cout_mod,
  ROUND(cd.cout_matieres + cd.cout_mod, 2)               AS couts_directs,
  /* --- methode classique : cle unique = MOD --- */
  ROUND(CASE WHEN t.total_mod = 0 THEN 0
             ELSE t.total_indirect * cd.cout_mod / t.total_mod END, 2) AS indirect_classique,
  ROUND(cd.cout_matieres + cd.cout_mod
        + CASE WHEN t.total_mod = 0 THEN 0
               ELSE t.total_indirect * cd.cout_mod / t.total_mod END, 2) AS cout_total_classique,
  /* --- methode ABC --- */
  COALESCE(ia.indirect_abc, 0)                            AS indirect_abc,
  ROUND(cd.cout_matieres + cd.cout_mod + COALESCE(ia.indirect_abc,0), 2) AS cout_total_abc,
  /* --- couts unitaires --- */
  ROUND(CASE WHEN cd.quantite_produite = 0 THEN NULL
        ELSE (cd.cout_matieres + cd.cout_mod
              + CASE WHEN t.total_mod = 0 THEN 0
                     ELSE t.total_indirect * cd.cout_mod / t.total_mod END)
             / cd.quantite_produite END, 2) AS cout_unitaire_classique,
  ROUND(CASE WHEN cd.quantite_produite = 0 THEN NULL
        ELSE (cd.cout_matieres + cd.cout_mod + COALESCE(ia.indirect_abc,0))
             / cd.quantite_produite END, 2) AS cout_unitaire_abc,
  /* --- subventionnement croise --- */
  ROUND(CASE WHEN t.total_mod = 0 THEN 0
             ELSE t.total_indirect * cd.cout_mod / t.total_mod END
        - COALESCE(ia.indirect_abc,0), 2) AS subventionnement_croise,
  /* --- marges --- */
  ROUND(cd.quantite_produite * cd.prix_vente_unitaire
        - (cd.cout_matieres + cd.cout_mod
           + CASE WHEN t.total_mod = 0 THEN 0
                  ELSE t.total_indirect * cd.cout_mod / t.total_mod END), 2) AS marge_classique,
  ROUND(cd.quantite_produite * cd.prix_vente_unitaire
        - (cd.cout_matieres + cd.cout_mod + COALESCE(ia.indirect_abc,0)), 2)  AS marge_abc,
  ROUND(CASE WHEN cd.quantite_produite * cd.prix_vente_unitaire = 0 THEN NULL
        ELSE 100 * (cd.quantite_produite * cd.prix_vente_unitaire
             - (cd.cout_matieres + cd.cout_mod + COALESCE(ia.indirect_abc,0)))
             / (cd.quantite_produite * cd.prix_vente_unitaire) END, 2) AS taux_marge_abc,
  CASE WHEN cd.quantite_produite * cd.prix_vente_unitaire
            - (cd.cout_matieres + cd.cout_mod + COALESCE(ia.indirect_abc,0)) < 0
       THEN 'DEFICITAIRE' ELSE 'RENTABLE' END
       COLLATE utf8mb4_unicode_ci AS statut_rentabilite_abc
FROM couts_directs cd
JOIN objets_cout o        ON o.id = cd.objet_cout_id
JOIN v_totaux_periode t   ON t.periode_id = cd.periode_id
LEFT JOIN v_indirect_abc ia ON ia.objet_cout_id = cd.objet_cout_id
                           AND ia.periode_id    = cd.periode_id;

-- 2.9 Controle de bouclage : total impute ABC = total charges indirectes
CREATE OR REPLACE VIEW v_controle_bouclage AS
SELECT
  t.periode_id,
  t.periode_code,
  t.total_indirect,
  COALESCE((SELECT SUM(ia.indirect_abc) FROM v_indirect_abc ia
             WHERE ia.periode_id = t.periode_id), 0) AS total_impute_abc,
  ROUND(t.total_indirect
        - COALESCE((SELECT SUM(ia.indirect_abc) FROM v_indirect_abc ia
                     WHERE ia.periode_id = t.periode_id), 0), 2) AS ecart_imputation
FROM v_totaux_periode t;

-- =====================================================================
--  3. DONNEES DE DEMONSTRATION
--  Entreprise : A & P BRIQUES SUARL - Briqueterie (Rufisque, Senegal)
--  Fabrication de briques, pavés et bordures en béton vibré.
--  4 produits, 8 activités, 6 ressources, 2 périodes.
--
--  Mots de passe en clair (à reporter dans le README) :
--    admin      / Admin@2026
--    controleur / Controle@2026
--    lecteur    / Lecture@2026
-- =====================================================================

INSERT INTO utilisateurs (login, mot_de_passe, nom_complet, email, role) VALUES
('admin',      '$2y$10$QgyQm3csJ07Y0oQKt.trTO/1Qn3pcwCdG7.cmPGrFEdMQkhxHmwsi',
 'Administrateur Système', 'admin@ap-briques.sn',      'ADMIN'),
('controleur', '$2y$10$V.8OYeZjXoop2e84YCudyO6YvbfjtAkIDOn9O98Kw.Yw9ZaarNT4G',
 'Aminata Sow — Contrôle de gestion', 'a.sow@ap-briques.sn', 'CONTROLEUR'),
('lecteur',    '$2y$10$i4/ALQhs0SpshbQKNkoePueLfyRi3ShVEfAAfnrAiU9Y/UQTXZyM2',
 'Moussa Fall — Direction générale', 'm.fall@ap-briques.sn', 'LECTEUR');

-- ---------------------------------------------------------------------
-- 3.1 Periodes
-- ---------------------------------------------------------------------
INSERT INTO periodes (code, libelle, date_debut, date_fin, statut) VALUES
('2026-05', 'Mai 2026',  '2026-05-01', '2026-05-31', 'CLOTUREE'),
('2026-06', 'Juin 2026', '2026-06-01', '2026-06-30', 'OUVERTE');

-- ---------------------------------------------------------------------
-- 3.2 Ressources de la briqueterie
--     Total des charges indirectes de juin 2026 = 48 000 000 FCFA
-- ---------------------------------------------------------------------
INSERT INTO ressources (code, libelle, nature, compte_syscohada) VALUES
('R1', 'Salaires du personnel indirect (chefs d''équipe, magasinier, laborantin)',
       'PERSONNEL',         '661'),
('R2', 'Énergie électrique et eau (presse, malaxeur, arrosage de cure)',
       'ENERGIE',           '605'),
('R3', 'Loyer du site et aires de séchage',
       'IMMOBILIER',        '622'),
('R4', 'Amortissements (presse à briques, malaxeur, moules, chariots)',
       'AMORTISSEMENT',     '681'),
('R5', 'Fournitures et pièces d''usure (moules, huile de démoulage, adjuvants)',
       'FOURNITURE',        '604'),
('R6', 'Services extérieurs (transport sur ventes, maintenance, télécom)',
       'SERVICE_EXTERIEUR', '628');

INSERT INTO ressource_montants (ressource_id, periode_id, montant) VALUES
((SELECT id FROM ressources WHERE code='R1'), 2, 15000000.00),
((SELECT id FROM ressources WHERE code='R2'), 2,  9600000.00),
((SELECT id FROM ressources WHERE code='R3'), 2,  4800000.00),
((SELECT id FROM ressources WHERE code='R4'), 2, 12000000.00),
((SELECT id FROM ressources WHERE code='R5'), 2,  4200000.00),
((SELECT id FROM ressources WHERE code='R6'), 2,  2400000.00);

-- ---------------------------------------------------------------------
-- 3.3 Carte des activites et inducteurs
-- ---------------------------------------------------------------------
INSERT INTO activites (code, libelle, processus, type_activite, niveau_hierarchique) VALUES
('A1', 'Approvisionner en ciment, sable et latérite', 'Approvisionnement', 'PRINCIPALE', 'LOT'),
('A2', 'Réceptionner et stocker les matières',        'Approvisionnement', 'PRINCIPALE', 'LOT'),
('A3', 'Changer les moules et régler la presse',      'Production',        'PRINCIPALE', 'LOT'),
('A4', 'Malaxer et presser les éléments',             'Production',        'PRINCIPALE', 'UNITE'),
('A5', 'Conduire la cure et le séchage',              'Production',        'PRINCIPALE', 'LOT'),
('A6', 'Contrôler la qualité (essais de compression)','Qualité',           'PRINCIPALE', 'LOT'),
('A7', 'Palettiser, charger et livrer sur chantier',  'Distribution',      'PRINCIPALE', 'LOT'),
('A8', 'Administrer les commandes clients',           'Support',           'SUPPORT',    'PRODUIT');

INSERT INTO inducteurs (activite_id, libelle, unite_oeuvre, capacite_pratique) VALUES
((SELECT id FROM activites WHERE code='A1'), 'Nombre de commandes d''achat',        'commande',     260),
((SELECT id FROM activites WHERE code='A2'), 'Nombre de réceptions',               'reception',    240),
((SELECT id FROM activites WHERE code='A3'), 'Nombre de changements de moule',     'changement',   330),
((SELECT id FROM activites WHERE code='A4'), 'Heures de presse',                   'heure',       1320),
((SELECT id FROM activites WHERE code='A5'), 'Palettes-jours en aire de cure',     'palette-jour',24000),
((SELECT id FROM activites WHERE code='A6'), 'Nombre d''essais de compression',     'essai',        340),
((SELECT id FROM activites WHERE code='A7'), 'Nombre de livraisons',               'livraison',    500),
((SELECT id FROM activites WHERE code='A8'), 'Nombre de commandes clients',        'commande',     660);

-- ---------------------------------------------------------------------
-- 3.4 Cles de repartition ressource -> activite (juin 2026)
--     Regle : la somme des pourcentages vaut 100 % par ressource.
-- ---------------------------------------------------------------------
INSERT INTO cles_ressources (ressource_id, activite_id, periode_id, pourcentage, justification) VALUES
-- R1 Salaires indirects : cle = effectif affecte a chaque activite
(1,1,2, 8.00,'Effectif service achats'),
(1,2,2,10.00,'Magasinier et manœuvres de stockage'),
(1,3,2,12.00,'Régleurs de presse'),
(1,4,2,20.00,'Chefs d''équipe de production'),
(1,5,2,10.00,'Surveillance des aires de cure'),
(1,6,2,12.00,'Laborantin et aide-laborantin'),
(1,7,2,18.00,'Équipe palettisation et chargement'),
(1,8,2,10.00,'Administration des ventes'),
-- R2 Energie et eau : cle = releves de compteurs kWh et m3
(2,1,2, 1.00,'Bureaux du service achats'),
(2,2,2, 3.00,'Éclairage et manutention du magasin'),
(2,3,2, 6.00,'Consommation pendant les changements de moule'),
(2,4,2,55.00,'Malaxeur et presse vibrante'),
(2,5,2,20.00,'Eau d''arrosage et brumisation de cure'),
(2,6,2, 3.00,'Presse d''essai du laboratoire'),
(2,7,2, 8.00,'Chariots élévateurs et zone de chargement'),
(2,8,2, 4.00,'Bureaux commerciaux'),
-- R3 Loyer du site : cle = surface occupee en m2
(3,1,2, 4.00,'Bureaux achats'),
(3,2,2,16.00,'Aire de stockage ciment, sable et latérite'),
(3,3,2, 5.00,'Atelier moules'),
(3,4,2,20.00,'Halle de malaxage et pressage'),
(3,5,2,30.00,'Aires de séchage et de cure (poste le plus étendu)'),
(3,6,2, 5.00,'Laboratoire d''essais'),
(3,7,2,12.00,'Aire de palettisation et quai de chargement'),
(3,8,2, 8.00,'Bureaux commerciaux'),
-- R4 Amortissements : cle = valeur brute des equipements affectes
(4,1,2, 2.00,'Matériel informatique achats'),
(4,2,2, 8.00,'Chargeur, bennes et bacs de stockage'),
(4,3,2,14.00,'Jeux de moules et outillage de réglage'),
(4,4,2,45.00,'Presse vibrante et malaxeur'),
(4,5,2, 6.00,'Bâches, râteliers et système de brumisation'),
(4,6,2, 7.00,'Presse d''essai et étuve du laboratoire'),
(4,7,2,14.00,'Chariots élévateurs et cercleuse'),
(4,8,2, 4.00,'Matériel de bureau'),
-- R5 Fournitures et pieces d usure : cle = bons de sortie magasin
(5,1,2, 4.00,'Fournitures administratives'),
(5,2,2, 4.00,'Consommables de magasin'),
(5,3,2,32.00,'Moules, cales et huile de démoulage (poste dominant)'),
(5,4,2,25.00,'Adjuvants, lubrifiants et pièces d''usure presse'),
(5,5,2, 8.00,'Bâches et films de cure'),
(5,6,2,15.00,'Consommables de laboratoire et éprouvettes'),
(5,7,2, 8.00,'Feuillards, films et cornières de palettisation'),
(5,8,2, 4.00,'Fournitures commerciales'),
-- R6 Services exterieurs : cle = analyse des factures fournisseurs
(6,1,2,14.00,'Télécom et déplacements achats'),
(6,2,2, 6.00,'Transport sur achats de matières'),
(6,3,2, 4.00,'Rectification externe des moules'),
(6,4,2, 8.00,'Maintenance externe de la presse'),
(6,5,2, 2.00,'Analyses externes de cure'),
(6,6,2, 6.00,'Essais en laboratoire agréé (LBTP)'),
(6,7,2,40.00,'Transport sur ventes vers chantiers (poste dominant)'),
(6,8,2,20.00,'Télécom et frais commerciaux');

-- ---------------------------------------------------------------------
-- 3.5 Objets de cout : la gamme A & P Briques
-- ---------------------------------------------------------------------
INSERT INTO objets_cout (code, libelle, type_objet, famille, unite) VALUES
('B1', 'Brique creuse 15 x 20 x 40',        'PRODUIT', 'Maçonnerie',   'unité'),
('B2', 'Brique pleine 10 x 20 x 40',        'PRODUIT', 'Maçonnerie',   'unité'),
('B3', 'Pavé autobloquant coloré 8 cm',     'PRODUIT', 'Voirie',       'unité'),
('B4', 'Bordure de trottoir T2',            'PRODUIT', 'Voirie',       'unité');

-- Couts directs, volumes et prix de vente - juin 2026
-- Total MOD = 10 000 000 FCFA
--   -> taux d imputation de la methode classique = 48 000 000 / 10 000 000 = 4,8
INSERT INTO couts_directs
  (objet_cout_id, periode_id, quantite_produite, prix_vente_unitaire, cout_matieres, cout_mod) VALUES
(1, 2, 180000,  425.00, 30000000.00, 4600000.00),
(2, 2, 120000,  350.00, 19000000.00, 3200000.00),
(3, 2,  25000,  850.00,  8000000.00, 1300000.00),
(4, 2,   9000, 1650.00,  5500000.00,  900000.00);

-- ---------------------------------------------------------------------
-- 3.6 Consommations d'inducteurs - juin 2026
--
--  Lecture metier : les pavés colorés (B3) et les bordures (B4) ne pesent
--  que 16 % des volumes produits mais absorbent :
--    - 82 % des changements de moule (changement de teinte et de format),
--    - 65 % des essais de compression (exigences de voirie),
--    - 45 % des palettes-jours de cure (28 jours contre 14 en maconnerie).
--  C est la source du subventionnement croise que l ABC va reveler.
-- ---------------------------------------------------------------------
INSERT INTO consommations (inducteur_id, objet_cout_id, periode_id, quantite) VALUES
-- A1 Commandes d achat (total 240)
(1,1,2,    60),(1,2,2,    45),(1,3,2,    75),(1,4,2,    60),
-- A2 Receptions (total 220)
(2,1,2,    60),(2,2,2,    45),(2,3,2,    65),(2,4,2,    50),
-- A3 Changements de moule (total 300)
(3,1,2,    30),(3,2,2,    25),(3,3,2,   130),(3,4,2,   115),
-- A4 Heures de presse (total 1 200)
(4,1,2,   520),(4,2,2,   380),(4,3,2,   180),(4,4,2,   120),
-- A5 Palettes-jours en aire de cure (total 21 000)
--    B1 : 480 palettes x 14 j | B2 : 340 x 14 j
--    B3 : 240 palettes x 28 j | B4 : 100 x 28 j
(5,1,2,  6720),(5,2,2,  4760),(5,3,2,  6720),(5,4,2,  2800),
-- A6 Essais de compression (total 300)
(6,1,2,    60),(6,2,2,    45),(6,3,2,   110),(6,4,2,    85),
-- A7 Livraisons (total 450)
(7,1,2,   140),(7,2,2,   100),(7,3,2,   120),(7,4,2,    90),
-- A8 Commandes clients (total 600)
(8,1,2,   150),(8,2,2,   120),(8,3,2,   190),(8,4,2,   140);

-- ---------------------------------------------------------------------
-- 3.7 Periode comparative mai 2026 (derivee de juin)
--     Alimente les comparaisons N vs N-1 du tableau de bord.
-- ---------------------------------------------------------------------
INSERT INTO ressource_montants (ressource_id, periode_id, montant)
SELECT ressource_id, 1, ROUND(montant * 0.94, 2)
FROM ressource_montants WHERE periode_id = 2;

INSERT INTO cles_ressources (ressource_id, activite_id, periode_id, pourcentage, justification)
SELECT ressource_id, activite_id, 1, pourcentage, justification
FROM cles_ressources WHERE periode_id = 2;

INSERT INTO couts_directs
  (objet_cout_id, periode_id, quantite_produite, prix_vente_unitaire, cout_matieres, cout_mod)
SELECT objet_cout_id, 1,
       ROUND(quantite_produite * 0.92, 0), prix_vente_unitaire,
       ROUND(cout_matieres * 0.92, 2),     ROUND(cout_mod * 0.95, 2)
FROM couts_directs WHERE periode_id = 2;

INSERT INTO consommations (inducteur_id, objet_cout_id, periode_id, quantite)
SELECT inducteur_id, objet_cout_id, 1, GREATEST(ROUND(quantite * 0.9, 0), 1)
FROM consommations WHERE periode_id = 2;

-- ---------------------------------------------------------------------
-- 3.8 Amorce du journal d'audit
-- ---------------------------------------------------------------------
INSERT INTO journal_audit (utilisateur_id, action, table_cible, id_cible, details, adresse_ip) VALUES
(1, 'INSTALL', NULL, NULL, 'Installation de la base et chargement du jeu de démonstration A & P Briques', '127.0.0.1'),
(2, 'CREATE',  'cles_ressources', '2026-06', 'Saisie des clés de répartition de juin 2026', '127.0.0.1'),
(2, 'UPDATE',  'periodes', '2026-05', 'Clôture de la période mai 2026', '127.0.0.1');

-- =====================================================================
--  4. REQUETES DE VERIFICATION (a executer apres import)
-- =====================================================================
-- SELECT * FROM v_controle_cles WHERE statut = 'ANOMALIE';   -- doit etre vide
-- SELECT * FROM v_controle_bouclage;                         -- ecart doit valoir 0
--
-- SELECT activite_code, activite_libelle, cout_activite
--   FROM v_cout_activite WHERE periode_id = 2 ORDER BY activite_code;
-- SELECT activite_code, unite_oeuvre, volume_total, cout_unitaire_inducteur
--   FROM v_cout_inducteur WHERE periode_id = 2 ORDER BY activite_code;
-- SELECT objet_code, cout_total_classique, cout_total_abc, subventionnement_croise,
--        marge_classique, marge_abc, statut_rentabilite_abc
--   FROM v_comparaison_couts WHERE periode_id = 2 ORDER BY objet_code;
--
-- ---------------------------------------------------------------------
--  RESULTATS ATTENDUS - juin 2026 (verifies)
-- ---------------------------------------------------------------------
--  Cout des activites (FCFA) :
--    A1 Approvisionner ................  2 232 000
--    A2 Receptionner et stocker .......  3 828 000
--    A3 Changer les moules ............  5 736 000
--    A4 Malaxer et presser ............ 15 882 000
--    A5 Cure et sechage ...............  5 964 000
--    A6 Controle qualite ..............  3 942 000
--    A7 Palettiser et livrer ..........  7 020 000
--    A8 Administrer les commandes .....  3 396 000
--                              TOTAL ... 48 000 000
--
--  Cout unitaire d inducteur (FCFA) :
--    A1  240 commandes ......  9 300 / commande
--    A2  220 receptions ..... 17 400 / reception
--    A3  300 changements .... 19 120 / changement de moule
--    A4  1 200 heures ....... 13 235 / heure de presse
--    A5  21 000 pal.-jours ..    284 / palette-jour
--    A6  300 essais ......... 13 140 / essai
--    A7  450 livraisons ..... 15 600 / livraison
--    A8  600 commandes ......  5 660 / commande client
--
--  Comparaison classique vs ABC :
--    Prod. Indirect clas.  Indirect ABC  Subv. croise   Marge clas.    Marge ABC
--    B1      22 080 000     14 787 680    +7 292 320    19 820 000   27 112 320
--    B2      15 360 000     10 891 140    +4 468 860     4 440 000    8 908 860
--    B3       6 240 000     12 997 680    -6 757 680     5 710 000   -1 047 680
--    B4       4 320 000      9 323 500    -5 003 500     4 130 000     -873 500
--
--  Cout de revient unitaire ABC : B1 274 | B2 276 | B3 892 | B4 1 747 FCFA
--
--  CONCLUSION A DEFENDRE EN SOUTENANCE :
--    la methode classique (cle unique MOD) presente les pavés (B3) et les
--    bordures (B4) comme rentables (+5,7 M et +4,1 M FCFA). L ABC montre
--    qu ils sont DEFICITAIRES : ils sont subventionnes a hauteur de
--    11,8 M FCFA par les briques de maconnerie, qui supportaient a tort
--    des charges de changement de moule, d essais et de cure qu elles ne
--    consomment pas. Levier d action : reduire les changements de teinte
--    par des series plus longues, ou revaloriser le prix de vente du pave
--    de 850 a 950 FCFA au minimum.
-- =====================================================================
--  FIN DU SCRIPT
-- =====================================================================
