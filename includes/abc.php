<?php
/**
 * Couche d'accès aux résultats du modèle ABC.
 *
 * Le moteur de calcul est implémenté dans les vues SQL
 * (v_cout_activite, v_cout_inducteur, v_comparaison_couts...).
 * PHP se contente de lire ces vues : un seul endroit détient la règle
 * de calcul, ce qui évite toute divergence entre écrans et exports.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// =====================================================================
//  CONTRÔLES DE COHÉRENCE
// =====================================================================

/**
 * Ressources dont la somme des clés de répartition n'atteint pas 100 %.
 * Tant que cette liste n'est pas vide, les coûts sont faux : c'est le
 * garde-fou principal du modèle.
 */
function controleCles(int $periodeId): array
{
    $sql = 'SELECT ressource_id, ressource_code, ressource_libelle,
                   total_pourcentage, ecart, statut
              FROM v_controle_cles
             WHERE periode_id = :p
             ORDER BY statut DESC, ressource_code';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId]);
    return $stmt->fetchAll();
}

/** Nombre de ressources en anomalie de clés. */
function nbAnomaliesCles(int $periodeId): int
{
    // Test numérique plutôt que comparaison de chaîne : évite tout conflit
    // de collation entre la colonne calculée de la vue et le paramètre lié.
    $sql = 'SELECT COUNT(*) FROM v_controle_cles
             WHERE periode_id = :p AND ABS(ecart) >= 0.0001';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId]);
    return (int) $stmt->fetchColumn();
}

/**
 * Contrôle de bouclage : le total imputé aux objets de coût doit être
 * égal au total des charges indirectes (écart attendu = 0).
 */
function controleBouclage(int $periodeId): ?array
{
    $sql = 'SELECT total_indirect, total_impute_abc, ecart_imputation
              FROM v_controle_bouclage WHERE periode_id = :p';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId]);
    $r = $stmt->fetch();
    return $r === false ? null : $r;
}

// =====================================================================
//  ÉTAPE 1 : RESSOURCES → ACTIVITÉS
// =====================================================================

/** Coût de chaque activité pour la période. */
function coutsActivites(int $periodeId): array
{
    $sql = 'SELECT activite_id, activite_code, activite_libelle, processus,
                   type_activite, niveau_hierarchique, cout_activite
              FROM v_cout_activite
             WHERE periode_id = :p
             ORDER BY activite_code';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId]);
    return $stmt->fetchAll();
}

/** Coût cumulé par processus (pour le graphique de répartition). */
function coutsParProcessus(int $periodeId): array
{
    $sql = 'SELECT processus, ROUND(SUM(cout_activite), 2) AS cout
              FROM v_cout_activite
             WHERE periode_id = :p
             GROUP BY processus
             ORDER BY cout DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId]);
    return $stmt->fetchAll();
}

// =====================================================================
//  ÉTAPE 2 : ACTIVITÉS → INDUCTEURS
// =====================================================================

/**
 * Coût unitaire d'inducteur de chaque activité, avec les indicateurs
 * TDABC (taux de capacité et coût de la capacité non utilisée).
 */
function coutsInducteurs(int $periodeId): array
{
    $sql = 'SELECT activite_code, activite_libelle, inducteur_libelle, unite_oeuvre,
                   cout_activite, volume_total, cout_unitaire_inducteur,
                   capacite_pratique, taux_capacite_tdabc, cout_capacite_non_utilisee
              FROM v_cout_inducteur
             WHERE periode_id = :p
             ORDER BY activite_code';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId]);
    return $stmt->fetchAll();
}

// =====================================================================
//  ÉTAPE 3 : INDUCTEURS → OBJETS DE COÛT
// =====================================================================

/**
 * Synthèse comparative méthode classique / méthode ABC par objet de coût.
 * C'est la vue centrale de la plateforme.
 */
function comparaisonCouts(int $periodeId, string $typeObjet = 'PRODUIT'): array
{
    $sql = 'SELECT objet_cout_id, objet_code, objet_libelle, famille,
                   quantite_produite, prix_vente_unitaire, chiffre_affaires,
                   cout_matieres, cout_mod, couts_directs,
                   indirect_classique, cout_total_classique, cout_unitaire_classique,
                   indirect_abc, cout_total_abc, cout_unitaire_abc,
                   subventionnement_croise,
                   marge_classique, marge_abc, taux_marge_abc,
                   statut_rentabilite_abc
              FROM v_comparaison_couts
             WHERE periode_id = :p AND type_objet = :t
             ORDER BY objet_code';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId, ':t' => $typeObjet]);
    return $stmt->fetchAll();
}

/**
 * Détail de l'imputation d'un objet de coût : combien chaque activité
 * lui coûte et pourquoi. Sert à justifier le chiffre en soutenance.
 */
function detailImputation(int $periodeId, int $objetId): array
{
    $sql = 'SELECT activite_code, activite_libelle, unite_oeuvre,
                   quantite_inducteur, cout_unitaire_inducteur, cout_impute
              FROM v_imputation_abc
             WHERE periode_id = :p AND objet_cout_id = :o
             ORDER BY cout_impute DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId, ':o' => $objetId]);
    return $stmt->fetchAll();
}

// =====================================================================
//  INDICATEURS DE SYNTHÈSE (tableau de bord)
// =====================================================================

/**
 * Agrégats de la période : chiffre d'affaires, charges, marge globale,
 * nombre d'objets déficitaires en ABC, subventionnement croisé total.
 */
function indicateursPeriode(int $periodeId): array
{
    $sql = 'SELECT
              COUNT(*)                                        AS nb_objets,
              COALESCE(SUM(chiffre_affaires), 0)              AS ca_total,
              COALESCE(SUM(couts_directs), 0)                 AS directs_total,
              COALESCE(SUM(indirect_abc), 0)                  AS indirect_total,
              COALESCE(SUM(cout_total_abc), 0)                AS cout_total,
              COALESCE(SUM(marge_abc), 0)                     AS marge_abc_total,
              COALESCE(SUM(marge_classique), 0)               AS marge_classique_total,
              SUM(CASE WHEN marge_abc < 0 THEN 1 ELSE 0 END)  AS nb_deficitaires,
              COALESCE(SUM(CASE WHEN subventionnement_croise < 0
                           THEN -subventionnement_croise ELSE 0 END), 0) AS subvention_recue
            FROM v_comparaison_couts
           WHERE periode_id = :p AND type_objet = :t';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId, ':t' => 'PRODUIT']);
    $r = $stmt->fetch() ?: [];

    $r['taux_marge'] = ($r['ca_total'] ?? 0) > 0
        ? 100 * $r['marge_abc_total'] / $r['ca_total']
        : null;

    return $r;
}

/**
 * Objets de coût déficitaires en ABC alors qu'ils paraissent rentables
 * en méthode classique : le cœur de la démonstration.
 */
function objetsMasques(int $periodeId): array
{
    $sql = 'SELECT objet_code, objet_libelle, marge_classique, marge_abc,
                   subventionnement_croise, cout_unitaire_abc, prix_vente_unitaire
              FROM v_comparaison_couts
             WHERE periode_id = :p
               AND marge_abc < 0
               AND marge_classique > 0
             ORDER BY marge_abc';
    $stmt = db()->prepare($sql);
    $stmt->execute([':p' => $periodeId]);
    return $stmt->fetchAll();
}

/**
 * Analyse de Pareto des activités : identifie les activités qui
 * concentrent 80 % des charges indirectes.
 */
function paretoActivites(int $periodeId): array
{
    $activites = coutsActivites($periodeId);
    usort($activites, fn($a, $b) => $b['cout_activite'] <=> $a['cout_activite']);

    $total  = array_sum(array_column($activites, 'cout_activite'));
    $cumul  = 0.0;
    $lignes = [];

    foreach ($activites as $a) {
        $cumul += (float) $a['cout_activite'];
        $lignes[] = [
            'code'         => $a['activite_code'],
            'libelle'      => $a['activite_libelle'],
            'cout'         => (float) $a['cout_activite'],
            'part'         => $total > 0 ? 100 * $a['cout_activite'] / $total : 0,
            'cumul_pct'    => $total > 0 ? 100 * $cumul / $total : 0,
        ];
    }
    return $lignes;
}
