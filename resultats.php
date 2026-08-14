<?php
/**
 * Résultats — étapes 1 et 2 du modèle ABC :
 *   Ressources → Activités  (coût de chaque activité)
 *   Activités  → Inducteurs (coût unitaire d'unité d'œuvre)
 *
 * Inclut l'analyse de Pareto des activités et les indicateurs TDABC
 * (coût de la capacité non utilisée).
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];

$activites  = coutsActivites($periodeId);
$inducteurs = coutsInducteurs($periodeId);
$pareto     = paretoActivites($periodeId);
$bouclage   = controleBouclage($periodeId);
$anomalies  = nbAnomaliesCles($periodeId);

$totalActivites = array_sum(array_column($activites, 'cout_activite'));

// Activités qui concentrent les premiers 80 % des charges
$seuil80 = [];
foreach ($pareto as $p) {
    $seuil80[] = $p['code'];
    if ($p['cumul_pct'] >= 80) {
        break;
    }
}

afficherEntete('Coûts des activités et des inducteurs', $periode);
?>

<?php if ($anomalies > 0): ?>
  <div class="alert alert-danger">
    <strong><?= (int) $anomalies ?> ressource(s)</strong> ne sont pas réparties à 100 % :
    les coûts ci-dessous sont incomplets.
    <a href="cles.php?periode=<?= $periodeId ?>" class="alert-link">Corriger les clés</a>.
  </div>
<?php endif; ?>

<!-- ============ ÉTAPE 1 : COÛT DES ACTIVITÉS ============ -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Étape 1 — Ressources vers activités</strong>
    <span class="small text-muted">
      Coût de l'activité = Σ (montant de la ressource × clé %)
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th>
          <th>Activité</th>
          <th>Processus</th>
          <th>Type</th>
          <th>Niveau</th>
          <th class="text-end">Coût de l'activité</th>
          <th class="text-end">Part</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($activites as $a):
          $part = $totalActivites > 0 ? 100 * $a['cout_activite'] / $totalActivites : 0; ?>
        <tr>
          <td><span class="badge bg-secondary"><?= e($a['activite_code']) ?></span></td>
          <td><?= e($a['activite_libelle']) ?></td>
          <td class="small text-muted"><?= e($a['processus']) ?></td>
          <td class="small">
            <?php if ($a['type_activite'] === 'SUPPORT'): ?>
              <span class="badge bg-light text-dark border">Support</span>
            <?php else: ?>
              <span class="badge bg-light text-dark border">Principale</span>
            <?php endif; ?>
          </td>
          <td class="small text-muted"><?= e(ucfirst(strtolower($a['niveau_hierarchique']))) ?></td>
          <td class="text-end"><?= fcfa((float) $a['cout_activite']) ?></td>
          <td class="text-end"><?= pct($part, 1) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light fw-semibold">
        <tr>
          <td colspan="5">Total des charges indirectes</td>
          <td class="text-end"><?= fcfa($totalActivites) ?></td>
          <td class="text-end">100,0 %</td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<!-- ============ ÉTAPE 2 : COÛT UNITAIRE D'INDUCTEUR ============ -->
<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong>Étape 2 — Activités vers inducteurs</strong>
    <span class="small text-muted">
      Coût unitaire = coût de l'activité ÷ volume total de l'inducteur
    </span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
      <thead class="table-light">
        <tr>
          <th>Code</th>
          <th>Inducteur de coût</th>
          <th>Unité d'œuvre</th>
          <th class="text-end">Coût de l'activité</th>
          <th class="text-end">Volume consommé</th>
          <th class="text-end">Coût unitaire</th>
          <th class="text-end">Capacité pratique</th>
          <th class="text-end">Coût de la capacité<br>non utilisée</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($inducteurs as $i):
          $capa   = $i['capacite_pratique'] !== null ? (float) $i['capacite_pratique'] : null;
          $inutil = $i['cout_capacite_non_utilisee'] !== null
                  ? (float) $i['cout_capacite_non_utilisee'] : null; ?>
        <tr>
          <td><span class="badge bg-secondary"><?= e($i['activite_code']) ?></span></td>
          <td><?= e($i['inducteur_libelle']) ?></td>
          <td class="small text-muted"><?= e($i['unite_oeuvre']) ?></td>
          <td class="text-end"><?= fcfa((float) $i['cout_activite']) ?></td>
          <td class="text-end"><?= nb((float) $i['volume_total']) ?></td>
          <td class="text-end fw-semibold">
            <?php if ($i['cout_unitaire_inducteur'] === null): ?>
              <span class="text-danger" title="Aucune consommation saisie">non calculable</span>
            <?php else: ?>
              <?= fcfa((float) $i['cout_unitaire_inducteur'], 2) ?>
            <?php endif; ?>
          </td>
          <td class="text-end small text-muted"><?= $capa === null ? '—' : nb($capa) ?></td>
          <td class="text-end <?= $inutil !== null && $inutil > 0 ? 'text-warning-emphasis' : '' ?>">
            <?= $inutil === null ? '—' : fcfa($inutil) ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    Approche <strong>Time-Driven ABC</strong> : la capacité pratique est la capacité
    réellement utilisable de l'activité. L'écart entre cette capacité et le volume
    consommé mesure le coût de la sous-activité, que la méthode ABC classique
    répartirait à tort sur les produits.
  </div>
</div>

<!-- ============ PARETO ============ -->
<div class="card mb-4">
  <div class="card-header">
    <strong>Analyse de Pareto — où se concentrent les charges indirectes</strong>
  </div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr>
          <th>Rang</th>
          <th>Activité</th>
          <th class="text-end">Coût</th>
          <th class="text-end">Part</th>
          <th class="text-end">Cumul</th>
          <th>Répartition</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($pareto as $rang => $p):
          $dans80 = in_array($p['code'], $seuil80, true); ?>
        <tr class="<?= $dans80 ? 'table-warning' : '' ?>">
          <td><?= $rang + 1 ?></td>
          <td>
            <span class="badge bg-secondary"><?= e($p['code']) ?></span>
            <?= e($p['libelle']) ?>
          </td>
          <td class="text-end"><?= fcfa($p['cout']) ?></td>
          <td class="text-end"><?= pct($p['part'], 1) ?></td>
          <td class="text-end fw-semibold"><?= pct($p['cumul_pct'], 1) ?></td>
          <td style="min-width:160px">
            <div class="progress" style="height:14px" role="progressbar"
                 aria-valuenow="<?= (int) round($p['part']) ?>"
                 aria-valuemin="0" aria-valuemax="100">
              <div class="progress-bar bg-brique-bar"
                   style="width: <?= number_format($p['part'], 2, '.', '') ?>%"></div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="card-footer small text-muted">
    Les lignes surlignées concentrent les premiers 80 % des charges indirectes
    (<?= count($seuil80) ?> activité(s) sur <?= count($pareto) ?>). Ce sont elles
    qu'il faut optimiser en priorité : agir sur les autres produit un effet marginal.
  </div>
</div>

<!-- ============ CONTRÔLE DE BOUCLAGE ============ -->
<?php if ($bouclage !== null): ?>
<div class="card mb-4">
  <div class="card-header"><strong>Contrôle de bouclage</strong></div>
  <div class="card-body">
    <div class="row g-3">
      <div class="col-12 col-md-4">
        <p class="kpi-label mb-1">Charges indirectes de la période</p>
        <p class="h6"><?= fcfa((float) $bouclage['total_indirect']) ?></p>
      </div>
      <div class="col-12 col-md-4">
        <p class="kpi-label mb-1">Total imputé aux objets de coût</p>
        <p class="h6"><?= fcfa((float) $bouclage['total_impute_abc']) ?></p>
      </div>
      <div class="col-12 col-md-4">
        <p class="kpi-label mb-1">Écart d'imputation</p>
        <p class="h6 <?= abs((float) $bouclage['ecart_imputation']) < 0.01 ? 'text-success' : 'text-danger' ?>">
          <?= fcfa((float) $bouclage['ecart_imputation']) ?>
          <?= abs((float) $bouclage['ecart_imputation']) < 0.01 ? '— conforme' : '— à corriger' ?>
        </p>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap gap-2">
  <a href="comparaison.php?periode=<?= $periodeId ?>" class="btn btn-brique">
    Étape 3 — Comparaison classique vs ABC
  </a>
  <a href="export_pdf.php?periode=<?= $periodeId ?>&amp;etat=activites" class="btn btn-outline-secondary">
    État PDF des coûts d'activités
  </a>
  <a href="export_csv.php?periode=<?= $periodeId ?>&amp;etat=activites" class="btn btn-outline-secondary">
    Export CSV des activités
  </a>
  <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
    Imprimer cet état
  </button>
</div>

<?php
audit('CONSULTATION', 'v_cout_activite', $periode['code'], 'État des coûts d\'activités');
afficherPied();
