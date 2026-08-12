<?php
/**
 * Export CSV des états de la plateforme.
 *
 * Trois états disponibles via le paramètre etat :
 *   activites    — coûts des activités et coûts unitaires d'inducteurs
 *   comparaison  — méthode classique vs ABC par objet de coût
 *   rentabilite  — marges et taux de marge par objet de coût
 *
 * Le fichier est produit avec un BOM UTF-8 et le point-virgule comme
 * séparateur, afin qu'Excel en configuration française l'ouvre directement
 * en colonnes et affiche correctement les accents.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];

$etat = $_GET['etat'] ?? 'comparaison';
if (!in_array($etat, ['activites', 'comparaison', 'rentabilite'], true)) {
    $etat = 'comparaison';
}

// ---------------------------------------------------------------------
// Construction des lignes
// ---------------------------------------------------------------------
$titre  = '';
$entete = [];
$lignes = [];

if ($etat === 'activites') {
    $titre  = 'Couts des activites et inducteurs';
    $entete = ['Code', 'Activite', 'Processus', 'Type', 'Niveau',
               'Cout de l activite', 'Inducteur', 'Unite d oeuvre',
               'Volume consomme', 'Cout unitaire d inducteur',
               'Capacite pratique', 'Cout capacite non utilisee'];

    $couts = [];
    foreach (coutsActivites($periodeId) as $a) {
        $couts[$a['activite_code']] = $a;
    }

    foreach (coutsInducteurs($periodeId) as $i) {
        $a = $couts[$i['activite_code']] ?? [];
        $lignes[] = [
            $i['activite_code'],
            $i['activite_libelle'],
            $a['processus'] ?? '',
            $a['type_activite'] ?? '',
            $a['niveau_hierarchique'] ?? '',
            $i['cout_activite'],
            $i['inducteur_libelle'],
            $i['unite_oeuvre'],
            $i['volume_total'],
            $i['cout_unitaire_inducteur'],
            $i['capacite_pratique'],
            $i['cout_capacite_non_utilisee'],
        ];
    }

} elseif ($etat === 'comparaison') {
    $titre  = 'Comparaison methode classique vs ABC';
    $entete = ['Code', 'Objet de cout', 'Famille', 'Quantite produite',
               'Prix de vente unitaire', 'Chiffre d affaires',
               'Cout matieres', 'Cout MOD', 'Couts directs',
               'Indirect classique', 'Cout total classique', 'Cout unitaire classique',
               'Indirect ABC', 'Cout total ABC', 'Cout unitaire ABC',
               'Subventionnement croise', 'Marge classique', 'Marge ABC',
               'Taux de marge ABC', 'Statut'];

    foreach (comparaisonCouts($periodeId) as $l) {
        $lignes[] = [
            $l['objet_code'], $l['objet_libelle'], $l['famille'],
            $l['quantite_produite'], $l['prix_vente_unitaire'], $l['chiffre_affaires'],
            $l['cout_matieres'], $l['cout_mod'], $l['couts_directs'],
            $l['indirect_classique'], $l['cout_total_classique'], $l['cout_unitaire_classique'],
            $l['indirect_abc'], $l['cout_total_abc'], $l['cout_unitaire_abc'],
            $l['subventionnement_croise'], $l['marge_classique'], $l['marge_abc'],
            $l['taux_marge_abc'], $l['statut_rentabilite_abc'],
        ];
    }

} else {
    $titre  = 'Rentabilite par objet de cout';
    $entete = ['Rang', 'Code', 'Objet de cout', 'Famille', 'Chiffre d affaires',
               'Cout total ABC', 'Marge ABC', 'Taux de marge ABC', 'Statut'];

    $objets = comparaisonCouts($periodeId);
    usort($objets, static fn($a, $b) => (float) $b['marge_abc'] <=> (float) $a['marge_abc']);

    foreach ($objets as $rang => $l) {
        $lignes[] = [
            $rang + 1, $l['objet_code'], $l['objet_libelle'], $l['famille'],
            $l['chiffre_affaires'], $l['cout_total_abc'], $l['marge_abc'],
            $l['taux_marge_abc'], $l['statut_rentabilite_abc'],
        ];
    }
}

// ---------------------------------------------------------------------
// Envoi du fichier
// ---------------------------------------------------------------------
$nomFichier = sprintf('ABC_%s_%s.csv', $etat, $periode['code']);

audit('EXPORT_CSV', null, $periode['code'], 'État exporté : ' . $etat);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('Pragma: no-cache');
header('Expires: 0');

$sortie = fopen('php://output', 'wb');

// BOM UTF-8 : indispensable pour que Excel reconnaisse l'encodage
fwrite($sortie, "\xEF\xBB\xBF");

// Bandeau d'identification de l'état
fputcsv($sortie, [APP_ENTREPRISE], ';');
fputcsv($sortie, [$titre], ';');
fputcsv($sortie, ['Periode', $periode['libelle'], 'Statut', $periode['statut']], ';');
fputcsv($sortie, ['Edite le', date('d/m/Y H:i'), 'Par', utilisateurNom()], ';');
fputcsv($sortie, [], ';');

fputcsv($sortie, $entete, ';');

foreach ($lignes as $ligne) {
    // Virgule décimale pour Excel francophone
    $ligne = array_map(static function ($v) {
        if (is_float($v) || (is_string($v) && is_numeric($v) && str_contains($v, '.'))) {
            return str_replace('.', ',', (string) $v);
        }
        return $v ?? '';
    }, $ligne);

    fputcsv($sortie, $ligne, ';');
}

fclose($sortie);
exit;
