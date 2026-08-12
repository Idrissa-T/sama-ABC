<?php
/**
 * Analyse de rentabilité par objet de coût.
 *
 * Classe les objets par marge ABC décroissante, calcule la classification
 * ABC des marges (courbe de concentration) et identifie les segments
 * destructeurs de valeur.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];

$lignes = comparaisonCouts($periodeId);

// Classement par marge ABC décroissante
usort($lignes, static fn($a, $b) => (float) $b['marge_abc'] <=> (float) $a['marge_abc']);

$caTotal    = array_sum(array_map(static fn($l) => (float) $l['chiffre_affaires'], $lignes));
$margeTotal = array_sum(array_map(static fn($l) => (float) $l['marge_abc'], $lignes));

// Classification ABC : la marge positive cumulée sert d'assiette
$margePositive = array_sum(array_map(
    static fn($l) => max(0.0, (float) $l['marge_abc']), $lignes
));

$cumul = 0.0;
$table = [];
foreach ($lignes as $l) {
    $marge  = (float) $l['marge_abc'];
    $cumul += max(0.0, $marge);
    $cumulPct = $margePositive > 0 ? 100 * $cumul / $margePositive : 0;

    if ($marge < 0) {
        $classe = 'D';
    } elseif ($cumulPct <= 80) {
        $classe = 'A';
    } elseif ($cumulPct <= 95) {
        $classe = 'B';
    } else {
        $classe = 'C';
    }

    $table[] = ['l' => $l, 'cumul_pct' => $cumulPct, 'classe' => $classe];
}

$badges = [
    'A' => ['bg-success',   'Contribue à l\'essentiel de la marge'],
    'B' => ['bg-primary',   'Contribution intermédiaire'],
    'C' => ['bg-secondary', 'Contribution marginale'],
    'D' => ['bg-danger',    'Détruit de la valeur'],
];

$nbDeficit    = count(array_filter($table, static fn($t) => $t['classe'] === 'D'));
$perteTotale  = array_sum(array_map(
    static fn($t) => min(0.0, (float) $t['l']['marge_abc']), $table
));

afficherEntete('Rentabilité par objet de coût', $periode);
?>

<!-- ============ SYNTHÈSE ============ -->
<div class="row g-3 mb-4">
  <div class="col-12 col-sm-6 col-xl-3">
    <div class="card h-100 kpi">
      <div class="card-body">
        <p class="kpi-label">Chiffre d'affaires</p>
        <p class="kpi-valeur"><?= fcfa($caTotal) ?></p>
        <p class="kpi-detail"><?= count($lignes) ?> objets de coût</p>
      </div>
    </div>
  </div>
  <div class="col-12 col-sm-6 col-xl-3">
    <div class="card h-100 kpi">
      <div class="card-body">
        <p class="kpi-label">Marge globale ABC</p>
        <p class="kpi-valeur <?= classeSigne($margeTotal) ?>"><?= fcfa($margeTotal) ?></p>
        <p class="kpi-detail">
          Taux : <?= pct($caTotal > 0 ? 100 * $margeTotal / $caTotal : null, 2) ?>
        </p>
      </div>
    </div>
  </div>
  <div class="col-12 col-sm-6 col-xl-3">
    <div class="card h-100 kpi <?= $nbDeficit > 0 ? 'kpi-alerte' : '' ?>">
      <div class="card-body">
        <p class="kpi-label">Objets déficitaires</p>
        <p class="kpi-valeur"><?= $nbDeficit ?></p>
        <p class="kpi-detail">Classe D de la segmentation</p>
      </div>
    </div>
  </div>
  <div class="col-12 col-sm-6 col-xl-3">
    <div class="card h-100 kpi">
      <div class="card-body">
        <p class="kpi-label">Destruction de valeur</p>
        <p class="kpi-valeur text-danger"><?= fcfa($perteTotale) ?></p>
        <p class="kpi-detail">
          Marge potentielle si corrigée :
          <?= fcfa($margeTotal - $perteTotale) ?>
        </p>
      </div>
    </div>
  </div>
</div>

<!-- ============ CLASSEMENT ============ -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Classement par contribution à la marge</strong>
    <span class="small text-muted">Classification ABC des objets de coût</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Rang</th>
          <th>Objet de coût</th>
          <th>Famille</th>
          <th class="text-end">Chiffre<br>d'affaires</th>
          <th class="text-end">Coût total<br>ABC</th>
          <th class="text-end">Marge ABC</th>
          <th class="text-end">Taux de<br>marge</th>
          <th class="text-end">Cumul de<br>marge</th>
          <th class="text-center">Classe</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($table as $rang => $t):
          $l = $t['l'];
          [$couleur, $sens] = $badges[$t['classe']]; ?>
        <tr>
          <td><?= $rang + 1 ?></td>
          <td>
            <span class="badge bg-secondary"><?= e($l['objet_code']) ?></span>
            <?= e($l['objet_libelle']) ?>
          </td>
          <td class="small text-muted"><?= e($l['famille'] ?? '—') ?></td>
          <td class="text-end"><?= fcfa((float) $l['chiffre_affaires']) ?></td>
          <td class="text-end"><?= fcfa((float) $l['cout_total_abc']) ?></td>
          <td class="text-end <?= classeSigne((float) $l['marge_abc']) ?>">
            <?= fcfa((float) $l['marge_abc']) ?>
          </td>
          <td class="text-end"><?= pct($l['taux_marge_abc'] !== null
              ? (float) $l['taux_marge_abc'] : null, 1) ?></td>
          <td class="text-end small text-muted">
            <?= $t['classe'] === 'D' ? '—' : pct($t['cumul_pct'], 1) ?>
          </td>
          <td class="text-center">
            <span class="badge <?= $couleur ?>" title="<?= e($sens) ?>">
              <?= e($t['classe']) ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-semibold">
        <tr>
          <td colspan="3">Total</td>
          <td class="text-end"><?= fcfa($caTotal) ?></td>
          <td class="text-end"><?= fcfa($caTotal - $margeTotal) ?></td>
          <td class="text-end <?= classeSigne($margeTotal) ?>"><?= fcfa($margeTotal) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <div class="card-footer small text-muted">
    <span class="badge bg-success">A</span> premiers 80 % de la marge &nbsp;
    <span class="badge bg-primary">B</span> de 80 à 95 % &nbsp;
    <span class="badge bg-secondary">C</span> au-delà de 95 % &nbsp;
    <span class="badge bg-danger">D</span> marge négative
  </div>
</div>

<!-- ============ RECOMMANDATIONS ============ -->
<div class="card mb-4">
  <div class="card-header"><strong>Recommandations hiérarchisées</strong></div>
  <div class="card-body">
    <ol class="mb-0">
    <?php
    $recos = [];
    foreach ($table as $t) {
        $l  = $t['l'];
        $pv = (float) $l['prix_vente_unitaire'];
        $cu = (float) $l['cout_unitaire_abc'];

        if ($t['classe'] === 'D') {
            $recos[] = sprintf(
                '<strong>%s — %s</strong> : porter le prix de vente de %s à au moins %s '
                . '(hausse de %s) ou allonger les séries de production. '
                . 'Perte actuelle : %s.',
                e($l['objet_code']), e($l['objet_libelle']),
                fcfa($pv), fcfa($cu),
                pct($pv > 0 ? 100 * ($cu - $pv) / $pv : null, 1),
                fcfa((float) $l['marge_abc'])
            );
        }
    }
    foreach ($table as $t) {
        $l = $t['l'];
        if ($t['classe'] === 'A' && (float) $l['subventionnement_croise'] > 0) {
            $recos[] = sprintf(
                '<strong>%s — %s</strong> : rentabilité réelle supérieure de %s à celle '
                . 'affichée par la méthode classique. Marge de manœuvre commerciale '
                . 'disponible pour défendre des parts de marché.',
                e($l['objet_code']), e($l['objet_libelle']),
                fcfa((float) $l['subventionnement_croise'])
            );
        }
    }
    if (!$recos) {
        $recos[] = 'Aucun objet de coût déficitaire : la structure de gamme est saine.';
    }
    foreach ($recos as $r): ?>
      <li class="mb-2"><?= $r ?></li>
    <?php endforeach; ?>
    </ol>
  </div>
</div>

<div class="d-flex flex-wrap gap-2">
  <a href="export_csv.php?periode=<?= $periodeId ?>&amp;etat=rentabilite" class="btn btn-outline-secondary">
    Export CSV
  </a>
  <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
    Imprimer cet état
  </button>
</div>

<?php
audit('CONSULTATION', 'v_comparaison_couts', $periode['code'], 'Analyse de rentabilité');
afficherPied();
