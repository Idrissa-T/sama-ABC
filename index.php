<?php
/**
 * Tableau de bord : indicateurs de synthèse, contrôles de cohérence
 * et graphiques dynamiques.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];

$anomalies  = nbAnomaliesCles($periodeId);
$bouclage   = controleBouclage($periodeId);
$kpi        = indicateursPeriode($periodeId);
$comparaison = comparaisonCouts($periodeId);
$processus  = coutsParProcessus($periodeId);
$pareto     = paretoActivites($periodeId);
$masques    = objetsMasques($periodeId);

afficherEntete('Tableau de bord', $periode);
?>

<?php if ($anomalies > 0): ?>
  <div class="alert alert-danger d-flex justify-content-between align-items-center">
    <span>
      <strong>Attention :</strong> <?= (int) $anomalies ?> ressource(s) dont la somme
      des clés de répartition n'atteint pas 100 %. Les coûts affichés sont incomplets.
    </span>
    <a href="cles.php?periode=<?= $periodeId ?>" class="btn btn-sm btn-danger">Corriger</a>
  </div>
<?php endif; ?>

<?php if ($bouclage !== null && abs((float) $bouclage['ecart_imputation']) > 0.01): ?>
  <div class="alert alert-warning">
    <strong>Écart d'imputation :</strong> <?= fcfa((float) $bouclage['ecart_imputation']) ?>.
    Une partie des charges indirectes n'est pas imputée : vérifiez que chaque
    activité possède un inducteur et des consommations saisies.
  </div>
<?php endif; ?>

<!-- ================= INDICATEURS ================= -->
<div class="row g-3 mb-4">
  <div class="col-12 col-sm-6 col-xl-3">
    <div class="card h-100 kpi">
      <div class="card-body">
        <p class="kpi-label">Chiffre d'affaires</p>
        <p class="kpi-valeur"><?= fcfa((float) ($kpi['ca_total'] ?? 0)) ?></p>
        <p class="kpi-detail"><?= (int) ($kpi['nb_objets'] ?? 0) ?> produits analysés</p>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="card h-100 kpi">
      <div class="card-body">
        <p class="kpi-label">Charges indirectes imputées</p>
        <p class="kpi-valeur"><?= fcfa((float) ($kpi['indirect_total'] ?? 0)) ?></p>
        <p class="kpi-detail">
          Coûts directs : <?= fcfa((float) ($kpi['directs_total'] ?? 0)) ?>
        </p>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="card h-100 kpi">
      <div class="card-body">
        <p class="kpi-label">Marge globale (ABC)</p>
        <p class="kpi-valeur <?= classeSigne((float) ($kpi['marge_abc_total'] ?? 0)) ?>">
          <?= fcfa((float) ($kpi['marge_abc_total'] ?? 0)) ?>
        </p>
        <p class="kpi-detail">Taux de marge : <?= pct($kpi['taux_marge'] ?? null) ?></p>
      </div>
    </div>
  </div>

  <div class="col-12 col-sm-6 col-xl-3">
    <div class="card h-100 kpi <?= ((int) ($kpi['nb_deficitaires'] ?? 0)) > 0 ? 'kpi-alerte' : '' ?>">
      <div class="card-body">
        <p class="kpi-label">Produits déficitaires en ABC</p>
        <p class="kpi-valeur"><?= (int) ($kpi['nb_deficitaires'] ?? 0) ?></p>
        <p class="kpi-detail">
          Subventionnement reçu : <?= fcfa((float) ($kpi['subvention_recue'] ?? 0)) ?>
        </p>
      </div>
    </div>
  </div>
</div>

<!-- ================= RÉVÉLATION ABC ================= -->
<?php if ($masques): ?>
<div class="card border-danger mb-4">
  <div class="card-header bg-danger-subtle">
    <strong>Ce que la méthode classique masquait</strong>
  </div>
  <div class="card-body">
    <p class="small text-muted">
      Ces produits paraissent rentables lorsque les charges indirectes sont réparties
      avec une clé unique (main-d'œuvre directe). L'ABC montre qu'ils détruisent de la
      valeur : ils sont subventionnés par les autres produits.
    </p>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>Produit</th>
            <th class="text-end">Marge classique</th>
            <th class="text-end">Marge ABC</th>
            <th class="text-end">Subvention reçue</th>
            <th class="text-end">Prix de vente</th>
            <th class="text-end">Coût réel unitaire</th>
            <th class="text-end">Hausse minimale</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($masques as $m):
            $pv     = (float) $m['prix_vente_unitaire'];
            $cu     = (float) $m['cout_unitaire_abc'];
            $hausse = $pv > 0 ? 100 * ($cu - $pv) / $pv : null; ?>
          <tr>
            <td>
              <span class="badge bg-secondary"><?= e($m['objet_code']) ?></span>
              <?= e($m['objet_libelle']) ?>
            </td>
            <td class="text-end text-success"><?= fcfa((float) $m['marge_classique']) ?></td>
            <td class="text-end text-danger fw-semibold"><?= fcfa((float) $m['marge_abc']) ?></td>
            <td class="text-end"><?= fcfa(-1 * (float) $m['subventionnement_croise']) ?></td>
            <td class="text-end"><?= fcfa($pv) ?></td>
            <td class="text-end fw-semibold"><?= fcfa($cu) ?></td>
            <td class="text-end text-danger">+<?= pct($hausse, 1) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ================= GRAPHIQUES ================= -->
<div class="row g-3 mb-4">
  <div class="col-12 col-xl-7">
    <div class="card h-100">
      <div class="card-header"><strong>Coût de revient unitaire : classique vs ABC</strong></div>
      <div class="card-body">
        <canvas id="graphComparaison" height="260"></canvas>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card h-100">
      <div class="card-header"><strong>Répartition des charges par processus</strong></div>
      <div class="card-body">
        <canvas id="graphProcessus" height="260"></canvas>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-7">
    <div class="card h-100">
      <div class="card-header"><strong>Pareto des activités (80 / 20)</strong></div>
      <div class="card-body">
        <canvas id="graphPareto" height="260"></canvas>
      </div>
    </div>
  </div>

  <div class="col-12 col-xl-5">
    <div class="card h-100">
      <div class="card-header"><strong>Subventionnement croisé par produit</strong></div>
      <div class="card-body">
        <canvas id="graphSubvention" height="260"></canvas>
      </div>
    </div>
  </div>
</div>

<div class="d-flex flex-wrap gap-2">
  <a href="comparaison.php?periode=<?= $periodeId ?>" class="btn btn-brique">
    Analyse détaillée classique vs ABC
  </a>
  <a href="export_pdf.php?periode=<?= $periodeId ?>" class="btn btn-outline-secondary">
    Export PDF
  </a>
  <a href="export_csv.php?periode=<?= $periodeId ?>" class="btn btn-outline-secondary">
    Export CSV
  </a>
</div>

<script>
// Données injectées depuis PHP, encodées en JSON pour éviter toute injection.
window.ABC_DATA = <?= json_encode([
    'objets' => array_map(static fn($c) => [
        'code'      => $c['objet_code'],
        'libelle'   => $c['objet_libelle'],
        'classique' => (float) $c['cout_unitaire_classique'],
        'abc'       => (float) $c['cout_unitaire_abc'],
        'prix'      => (float) $c['prix_vente_unitaire'],
        'subvention'=> (float) $c['subventionnement_croise'],
    ], $comparaison),
    'processus' => array_map(static fn($p) => [
        'libelle' => $p['processus'],
        'cout'    => (float) $p['cout'],
    ], $processus),
    'pareto' => array_map(static fn($p) => [
        'code'  => $p['code'],
        'cout'  => $p['cout'],
        'cumul' => round($p['cumul_pct'], 2),
    ], $pareto),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>

<?php
audit('CONSULTATION', 'v_comparaison_couts', $periode['code'], 'Tableau de bord');
afficherPied();
