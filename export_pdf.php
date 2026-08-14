<?php
/**
 * Export PDF du rapport officiel de rentabilité par la méthode ABC.
 *
 * Trois états au choix via le paramètre etat :
 *   rentabilite — rapport complet : synthèse, comparaison, rentabilité,
 *                 subventionnements croisés et recommandations chiffrées
 *   activites   — état des coûts d'activités et des coûts d'inducteurs
 *   fiche       — fiche de coût de revient d'un objet de coût donné
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/abc.php';
require_once __DIR__ . '/lib/EtatPdf.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];

$etat = $_GET['etat'] ?? 'rentabilite';
if (!in_array($etat, ['rentabilite', 'activites', 'fiche'], true)) {
    $etat = 'rentabilite';
}

$sousTitre = $periode['libelle'] . ' — ' . $periode['statut'];

// =====================================================================
//  ÉTAT 1 : RAPPORT DE RENTABILITÉ
// =====================================================================
if ($etat === 'rentabilite') {

    $lignes = comparaisonCouts($periodeId);
    $kpi    = indicateursPeriode($periodeId);
    $masq   = objetsMasques($periodeId);

    usort($lignes, static fn($a, $b) => (float) $b['marge_abc'] <=> (float) $a['marge_abc']);

    $pdf = new EtatPdf('Rapport de rentabilité ABC', $sousTitre, utilisateurNom(), 'L');
    $pdf->AddPage();

    // ---------- Indicateurs ----------
    $pdf->indicateurs([
        ["Chiffre d'affaires", $pdf->montant((float) ($kpi['ca_total'] ?? 0)) . ' FCFA'],
        ['Charges indirectes imputées', $pdf->montant((float) ($kpi['indirect_total'] ?? 0)) . ' FCFA'],
        ['Marge globale ABC', $pdf->montant((float) ($kpi['marge_abc_total'] ?? 0)) . ' FCFA'],
        ['Taux de marge', $pdf->montant($kpi['taux_marge'] ?? 0, 2) . ' %'],
        ['Objets déficitaires', (string) (int) ($kpi['nb_deficitaires'] ?? 0)],
    ]);

    // ---------- Méthodologie ----------
    $pdf->titreSection('1. Méthode appliquée');
    $pdf->paragraphe(
        "La méthode ABC (Activity Based Costing) impute les charges indirectes en deux étapes : "
        . "les ressources sont d'abord réparties entre les activités au moyen de clés de répartition, "
        . "puis le coût de chaque activité est imputé aux objets de coût proportionnellement aux "
        . "inducteurs qu'ils consomment réellement. Le présent rapport confronte ce résultat à la "
        . "méthode traditionnelle, qui répartit les mêmes charges au prorata d'une clé unique : "
        . "la main-d'œuvre directe. L'écart entre les deux mesure le subventionnement croisé."
    );

    // ---------- Tableau comparatif ----------
    $pdf->titreSection('2. Comparaison des coûts de revient : méthode classique et méthode ABC');

    $colonnes = [
        ['Code', 14, 'C'],
        ['Objet de coût', 62, 'L'],
        ['Quantité', 22, 'R'],
        ['Coûts directs', 28, 'R'],
        ['Indirect classique', 28, 'R'],
        ['Indirect ABC', 28, 'R'],
        ['Subvention. croisé', 28, 'R'],
        ['Coût unit. classique', 29, 'R'],
        ['Coût unit. ABC', 25, 'R'],
    ];
    $pdf->enteteTableau($colonnes);

    $totDirects = $totClas = $totAbc = 0.0;

    foreach ($lignes as $l) {
        $subv        = (float) $l['subventionnement_croise'];
        $totDirects += (float) $l['couts_directs'];
        $totClas    += (float) $l['indirect_classique'];
        $totAbc     += (float) $l['indirect_abc'];

        $deficit = $l['statut_rentabilite_abc'] === 'DEFICITAIRE';

        $pdf->ligneTableau([
            [$l['objet_code'], 14, 'C'],
            [$l['objet_libelle'], 62, 'L'],
            [$pdf->montant((float) $l['quantite_produite']), 22, 'R'],
            [$pdf->montant((float) $l['couts_directs']), 28, 'R'],
            [$pdf->montant((float) $l['indirect_classique']), 28, 'R'],
            [$pdf->montant((float) $l['indirect_abc']), 28, 'R'],
            [($subv > 0 ? '+' : '') . $pdf->montant($subv), 28, 'R'],
            [$pdf->montant((float) $l['cout_unitaire_classique']), 29, 'R'],
            [$pdf->montant((float) $l['cout_unitaire_abc']), 25, 'R'],
        ], false, $deficit ? [250, 235, 233] : null);
    }

    $pdf->ligneTableau([
        ['', 14, 'C'],
        ['Total', 62, 'L'],
        ['', 22, 'R'],
        [$pdf->montant($totDirects), 28, 'R'],
        [$pdf->montant($totClas), 28, 'R'],
        [$pdf->montant($totAbc), 28, 'R'],
        [$pdf->montant($totClas - $totAbc), 28, 'R'],
        ['', 29, 'R'],
        ['', 25, 'R'],
    ], true, [238, 234, 232]);

    $pdf->Ln(1);
    $pdf->paragraphe(
        "Les deux méthodes imputent un total identique de charges indirectes : elles ne divergent "
        . "que par la répartition. La somme des subventionnements croisés est donc nulle par "
        . "construction. Ce qu'un objet de coût ne supporte pas, un autre le supporte à sa place."
    );

    // ---------- Rentabilité ----------
    $pdf->AddPage();
    $pdf->titreSection('3. Rentabilité par objet de coût');

    $colonnes = [
        ['Rang', 14, 'C'],
        ['Code', 14, 'C'],
        ['Objet de coût', 62, 'L'],
        ["Chiffre d'affaires", 30, 'R'],
        ['Coût total ABC', 30, 'R'],
        ['Marge classique', 30, 'R'],
        ['Marge ABC', 30, 'R'],
        ['Taux marge ABC', 24, 'R'],
        ['Statut', 24, 'C'],
    ];
    $pdf->enteteTableau($colonnes);

    foreach ($lignes as $rang => $l) {
        $deficit = $l['statut_rentabilite_abc'] === 'DEFICITAIRE';

        $pdf->ligneTableau([
            [(string) ($rang + 1), 14, 'C'],
            [$l['objet_code'], 14, 'C'],
            [$l['objet_libelle'], 62, 'L'],
            [$pdf->montant((float) $l['chiffre_affaires']), 30, 'R'],
            [$pdf->montant((float) $l['cout_total_abc']), 30, 'R'],
            [$pdf->montant((float) $l['marge_classique']), 30, 'R'],
            [$pdf->montant((float) $l['marge_abc']), 30, 'R'],
            [$pdf->montant($l['taux_marge_abc'] !== null ? (float) $l['taux_marge_abc'] : null, 1) . ' %', 24, 'R'],
            [$deficit ? 'Déficitaire' : 'Rentable', 24, 'C'],
        ], $deficit, $deficit ? [250, 235, 233] : null,
           $deficit ? [155, 45, 32] : null);
    }

    // ---------- Subventionnements ----------
    $pdf->Ln(2);
    $pdf->titreSection('4. Subventionnement croisé par objet de coût');
    $pdf->paragraphe(
        "Une valeur positive signale un objet surchargé par la méthode classique : il supportait "
        . "des charges qu'il ne consomme pas. Une valeur négative signale un objet subventionné "
        . "par les autres."
    );
    $pdf->histogramme(array_map(
        static fn($l) => [$l['objet_code'] . ' ' . mb_substr($l['objet_libelle'], 0, 26),
                          (float) $l['subventionnement_croise']],
        $lignes
    ));

    // ---------- Conclusion ----------
    $pdf->titreSection('5. Conclusion et recommandations');

    if ($masq) {
        $detail = [];
        $perte  = 0.0;

        foreach ($masq as $m) {
            $pv     = (float) $m['prix_vente_unitaire'];
            $cu     = (float) $m['cout_unitaire_abc'];
            $perte += (float) $m['marge_abc'];

            $detail[] = sprintf(
                "%s (%s) : vendu à %s FCFA pour un coût de revient réel de %s FCFA, soit une hausse "
                . "minimale de %s %% à appliquer. La méthode classique affichait pourtant une marge "
                . "positive de %s FCFA.",
                $m['objet_code'], $m['objet_libelle'],
                $pdf->montant($pv), $pdf->montant($cu),
                $pdf->montant($pv > 0 ? 100 * ($cu - $pv) / $pv : 0, 1),
                $pdf->montant((float) $m['marge_classique'])
            );
        }

        $pdf->encadre(
            sprintf('%d objet(s) de coût détruisent de la valeur sans que la méthode classique '
                . 'ne le révèle', count($masq)),
            implode(' ', $detail)
            . sprintf(' Perte cumulée sur la période : %s FCFA.', $pdf->montant($perte)),
            'danger'
        );

        $pdf->paragraphe(
            "Trois leviers d'action sont mobilisables : relever le prix de vente au moins au niveau "
            . "du coût de revient ABC ; allonger les séries de production afin de diluer le coût des "
            . "activités de lot, en particulier les changements de moule et les contrôles qualité ; "
            . "ou réexaminer l'opportunité de maintenir la gamme concernée au catalogue."
        );
    } else {
        $pdf->encadre(
            'Aucun objet de coût déficitaire',
            "Après imputation par la méthode ABC, tous les objets de coût dégagent une marge "
            . "positive. La structure de gamme est saine et la politique tarifaire couvre les "
            . "coûts réellement consommés.",
            'succes'
        );
    }

    $nomFichier = 'ABC_rapport_rentabilite_' . $periode['code'] . '.pdf';
}

// =====================================================================
//  ÉTAT 2 : COÛTS DES ACTIVITÉS ET DES INDUCTEURS
// =====================================================================
elseif ($etat === 'activites') {

    $activites  = coutsActivites($periodeId);
    $inducteurs = coutsInducteurs($periodeId);
    $pareto     = paretoActivites($periodeId);
    $bouclage   = controleBouclage($periodeId);

    $total = array_sum(array_column($activites, 'cout_activite'));

    $pdf = new EtatPdf("État des coûts d'activités", $sousTitre, utilisateurNom());
    $pdf->AddPage();

    $pdf->titreSection('1. Coût des activités (ressources vers activités)');
    $pdf->paragraphe(
        "Coût de l'activité = somme, pour chaque ressource, du montant de la période multiplié "
        . "par la clé de répartition affectée à cette activité."
    );

    $pdf->enteteTableau([
        ['Code', 14, 'C'],
        ['Activité', 74, 'L'],
        ['Processus', 32, 'L'],
        ['Type', 22, 'C'],
        ["Coût de l'activité", 30, 'R'],
        ['Part', 14, 'R'],
    ]);

    foreach ($activites as $a) {
        $part = $total > 0 ? 100 * (float) $a['cout_activite'] / $total : 0;
        $pdf->ligneTableau([
            [$a['activite_code'], 14, 'C'],
            [$a['activite_libelle'], 74, 'L'],
            [$a['processus'], 32, 'L'],
            [ucfirst(strtolower($a['type_activite'])), 22, 'C'],
            [$pdf->montant((float) $a['cout_activite']), 30, 'R'],
            [$pdf->montant($part, 1) . ' %', 14, 'R'],
        ]);
    }
    $pdf->ligneTableau([
        ['', 14, 'C'],
        ['Total des charges indirectes', 74, 'L'],
        ['', 32, 'L'],
        ['', 22, 'C'],
        [$pdf->montant($total), 30, 'R'],
        ['100,0 %', 14, 'R'],
    ], true, [238, 234, 232]);

    $pdf->Ln(2);
    $pdf->titreSection("2. Coût unitaire d'inducteur (activités vers objets de coût)");
    $pdf->paragraphe(
        "Coût unitaire = coût de l'activité divisé par le volume total de l'inducteur consommé "
        . "sur la période. La dernière colonne applique l'approche Time-Driven ABC : elle chiffre "
        . "le coût de la capacité disponible mais non utilisée, que la méthode ABC classique "
        . "imputerait à tort aux objets de coût."
    );

    $pdf->enteteTableau([
        ['Code', 14, 'C'],
        ['Inducteur de coût', 60, 'L'],
        ["Unité d'œuvre", 26, 'L'],
        ['Volume', 22, 'R'],
        ['Coût unitaire', 26, 'R'],
        ['Capacité pratique', 24, 'R'],
        ['Coût capacité inutilisée', 24, 'R'],
    ]);

    $totalInutil = 0.0;
    foreach ($inducteurs as $i) {
        $inutil       = $i['cout_capacite_non_utilisee'] !== null
                      ? (float) $i['cout_capacite_non_utilisee'] : null;
        $totalInutil += $inutil ?? 0.0;

        $pdf->ligneTableau([
            [$i['activite_code'], 14, 'C'],
            [$i['inducteur_libelle'], 60, 'L'],
            [$i['unite_oeuvre'], 26, 'L'],
            [$pdf->montant((float) $i['volume_total']), 22, 'R'],
            [$i['cout_unitaire_inducteur'] !== null
                ? $pdf->montant((float) $i['cout_unitaire_inducteur'], 2) : 'n.c.', 26, 'R'],
            [$i['capacite_pratique'] !== null
                ? $pdf->montant((float) $i['capacite_pratique']) : '-', 24, 'R'],
            [$inutil !== null ? $pdf->montant($inutil) : '-', 24, 'R'],
        ]);
    }
    $pdf->ligneTableau([
        ['', 14, 'C'],
        ['Coût total de la sous-activité', 60, 'L'],
        ['', 26, 'L'],
        ['', 22, 'R'],
        ['', 26, 'R'],
        ['', 24, 'R'],
        [$pdf->montant($totalInutil), 24, 'R'],
    ], true, [238, 234, 232]);

    // ---------- Pareto ----------
    $pdf->AddPage();
    $pdf->titreSection('3. Analyse de Pareto des activités');
    $pdf->paragraphe(
        "Les activités sont classées par coût décroissant. Celles qui composent les premiers "
        . "80 % du cumul concentrent l'essentiel des charges indirectes : ce sont elles qu'il "
        . "faut optimiser en priorité."
    );

    $pdf->enteteTableau([
        ['Rang', 16, 'C'],
        ['Code', 16, 'C'],
        ['Activité', 84, 'L'],
        ['Coût', 30, 'R'],
        ['Part', 20, 'R'],
        ['Cumul', 20, 'R'],
    ]);

    foreach ($pareto as $rang => $p) {
        $dans80 = $p['cumul_pct'] <= 80.0001;
        $pdf->ligneTableau([
            [(string) ($rang + 1), 16, 'C'],
            [$p['code'], 16, 'C'],
            [$p['libelle'], 84, 'L'],
            [$pdf->montant($p['cout']), 30, 'R'],
            [$pdf->montant($p['part'], 1) . ' %', 20, 'R'],
            [$pdf->montant($p['cumul_pct'], 1) . ' %', 20, 'R'],
        ], false, $dans80 ? [252, 246, 232] : null);
    }

    $pdf->Ln(2);
    $pdf->histogramme(array_map(
        static fn($p) => [$p['code'] . ' ' . mb_substr($p['libelle'], 0, 28), $p['cout']],
        $pareto
    ));

    // ---------- Contrôle ----------
    if ($bouclage !== null) {
        $ecart    = (float) $bouclage['ecart_imputation'];
        $conforme = abs($ecart) < 0.01;

        $pdf->titreSection('4. Contrôle de bouclage');
        $pdf->encadre(
            $conforme ? 'Bouclage conforme' : "Écart d'imputation détecté",
            sprintf(
                'Charges indirectes de la période : %s FCFA. Total imputé aux objets de coût : '
                . '%s FCFA. Ecart : %s FCFA. %s',
                $pdf->montant((float) $bouclage['total_indirect']),
                $pdf->montant((float) $bouclage['total_impute_abc']),
                $pdf->montant($ecart),
                $conforme
                    ? "L'intégralité des charges indirectes est imputée : le modèle est complet."
                    : "Une ou plusieurs activités n'ont aucune consommation d'inducteur saisie."
            ),
            $conforme ? 'succes' : 'danger'
        );
    }

    $nomFichier = 'ABC_couts_activites_' . $periode['code'] . '.pdf';
}

// =====================================================================
//  ÉTAT 3 : FICHE DE COÛT DE REVIENT D'UN OBJET
// =====================================================================
else {
    $objetId = entierPositif($_GET['objet'] ?? 0);
    $lignes  = comparaisonCouts($periodeId);

    $objet = null;
    foreach ($lignes as $l) {
        if ((int) $l['objet_cout_id'] === $objetId) {
            $objet = $l;
            break;
        }
    }
    if ($objet === null) {
        $objet = $lignes[0] ?? null;
    }
    if ($objet === null) {
        http_response_code(404);
        die('Aucun objet de coût disponible pour cette période.');
    }

    $detail = detailImputation($periodeId, (int) $objet['objet_cout_id']);

    $pdf = new EtatPdf('Fiche de coût de revient', $sousTitre, utilisateurNom());
    $pdf->AddPage();

    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->Cell(0, 8, $pdf->txt($objet['objet_code'] . ' — ' . $objet['objet_libelle']), 0, 1);
    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(110, 110, 110);
    $pdf->Cell(0, 5, $pdf->txt(
        'Famille : ' . ($objet['famille'] ?? 'non renseignée')
        . '   |   Quantité produite : ' . $pdf->montant((float) $objet['quantite_produite'])
    ), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(3);

    // ---------- Détail de l'imputation ----------
    $pdf->titreSection("1. Détail de l'imputation par activité");

    $pdf->enteteTableau([
        ['Code', 16, 'C'],
        ['Activité', 62, 'L'],
        ["Unité d'œuvre", 26, 'L'],
        ['Quantité consommée', 26, 'R'],
        ['Coût unitaire', 26, 'R'],
        ['Coût imputé', 30, 'R'],
    ]);

    $totImpute = 0.0;
    foreach ($detail as $d) {
        $totImpute += (float) $d['cout_impute'];
        $pdf->ligneTableau([
            [$d['activite_code'], 16, 'C'],
            [$d['activite_libelle'], 62, 'L'],
            [$d['unite_oeuvre'], 26, 'L'],
            [$pdf->montant((float) $d['quantite_inducteur']), 26, 'R'],
            [$pdf->montant((float) $d['cout_unitaire_inducteur'], 2), 26, 'R'],
            [$pdf->montant((float) $d['cout_impute']), 30, 'R'],
        ]);
    }
    $pdf->ligneTableau([
        ['', 16, 'C'],
        ['Charges indirectes imputées (ABC)', 62, 'L'],
        ['', 26, 'L'],
        ['', 26, 'R'],
        ['', 26, 'R'],
        [$pdf->montant($totImpute), 30, 'R'],
    ], true, [238, 234, 232]);

    // ---------- Structure du coût ----------
    $pdf->Ln(3);
    $pdf->titreSection('2. Structure du coût de revient');

    $qte = (float) $objet['quantite_produite'];
    $u   = static fn(float $v) => $qte > 0 ? $v / $qte : 0.0;

    $pdf->enteteTableau([
        ['Composante', 100, 'L'],
        ['Montant total', 40, 'R'],
        ['Par unité', 30, 'R'],
        ['Part', 16, 'R'],
    ]);

    $totalCout = (float) $objet['cout_total_abc'];
    foreach ([
        ['Matières consommées', (float) $objet['cout_matieres']],
        ["Main-d'œuvre directe", (float) $objet['cout_mod']],
        ['Charges indirectes imputées (ABC)', (float) $objet['indirect_abc']],
    ] as [$lib, $val]) {
        $pdf->ligneTableau([
            [$lib, 100, 'L'],
            [$pdf->montant($val), 40, 'R'],
            [$pdf->montant($u($val), 2), 30, 'R'],
            [$pdf->montant($totalCout > 0 ? 100 * $val / $totalCout : 0, 1) . ' %', 16, 'R'],
        ]);
    }
    $pdf->ligneTableau([
        ['Coût de revient complet', 100, 'L'],
        [$pdf->montant($totalCout), 40, 'R'],
        [$pdf->montant($u($totalCout), 2), 30, 'R'],
        ['100,0 %', 16, 'R'],
    ], true, [238, 234, 232]);

    // ---------- Diagnostic ----------
    $pdf->Ln(3);
    $pdf->titreSection('3. Diagnostic de rentabilité');

    $pv     = (float) $objet['prix_vente_unitaire'];
    $cu     = (float) $objet['cout_unitaire_abc'];
    $margeU = $pv - $cu;

    $pdf->enteteTableau([
        ['Indicateur', 100, 'L'],
        ['Valeur', 86, 'R'],
    ]);
    foreach ([
        ['Prix de vente unitaire', $pdf->montant($pv) . ' FCFA'],
        ['Coût de revient unitaire ABC', $pdf->montant($cu) . ' FCFA'],
        ['Marge unitaire', $pdf->montant($margeU) . ' FCFA'],
        ["Chiffre d'affaires de la période", $pdf->montant((float) $objet['chiffre_affaires']) . ' FCFA'],
        ['Marge ABC de la période', $pdf->montant((float) $objet['marge_abc']) . ' FCFA'],
        ['Taux de marge ABC', $pdf->montant($objet['taux_marge_abc'] !== null
            ? (float) $objet['taux_marge_abc'] : null, 2) . ' %'],
        ['Coût unitaire en méthode classique',
            $pdf->montant((float) $objet['cout_unitaire_classique']) . ' FCFA'],
        ['Subventionnement croisé',
            $pdf->montant((float) $objet['subventionnement_croise']) . ' FCFA'],
    ] as [$lib, $val]) {
        $pdf->ligneTableau([[$lib, 100, 'L'], [$val, 86, 'R']]);
    }

    $pdf->Ln(2);
    if ($objet['statut_rentabilite_abc'] === 'DEFICITAIRE') {
        $pdf->encadre(
            'Objet de coût déficitaire',
            sprintf(
                "Cet objet est vendu sous son coût de revient réel. La méthode classique affichait "
                . "une marge de %s FCFA parce qu'il était subventionné à hauteur de %s FCFA par les "
                . "autres objets de coût. Le prix de vente devrait atteindre au moins %s FCFA, soit "
                . "une hausse de %s %%.",
                $pdf->montant((float) $objet['marge_classique']),
                $pdf->montant(-1 * (float) $objet['subventionnement_croise']),
                $pdf->montant($cu),
                $pdf->montant($pv > 0 ? 100 * ($cu - $pv) / $pv : 0, 1)
            ),
            'danger'
        );
    } else {
        $pdf->encadre(
            'Objet de coût rentable',
            sprintf(
                "Cet objet dégage une marge de %s FCFA après imputation ABC, soit un taux de %s %%. %s",
                $pdf->montant((float) $objet['marge_abc']),
                $pdf->montant($objet['taux_marge_abc'] !== null
                    ? (float) $objet['taux_marge_abc'] : null, 2),
                (float) $objet['subventionnement_croise'] > 0
                    ? sprintf('Il était même surchargé de %s FCFA par la méthode classique : sa '
                        . 'rentabilité réelle dépasse ce que laissait croire la clé unique.',
                        $pdf->montant((float) $objet['subventionnement_croise']))
                    : ''
            ),
            'succes'
        );
    }

    $nomFichier = 'ABC_fiche_' . $objet['objet_code'] . '_' . $periode['code'] . '.pdf';
}

// =====================================================================
//  ENVOI
// =====================================================================
audit('EXPORT_PDF', null, $periode['code'], 'Etat PDF exporte : ' . $etat);

$pdf->Output('I', $nomFichier);
