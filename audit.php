<?php
/**
 * Journal d'audit : consultation en lecture seule des actions sensibles.
 *
 * Réservé au profil ADMIN. Le journal n'est jamais modifiable depuis
 * l'interface, c'est la condition même de sa valeur probante.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

exigerRole('ADMIN');

$periode = periodeActive();

// ---------------------------------------------------------------------
// Filtres
// ---------------------------------------------------------------------
$recherche = trim((string) ($_GET['q'] ?? ''));
$filtreAct = trim((string) ($_GET['action'] ?? ''));
$filtreUti = entierPositif($_GET['utilisateur'] ?? 0);
$du        = trim((string) ($_GET['du'] ?? ''));
$au        = trim((string) ($_GET['au'] ?? ''));

$where  = ['1 = 1'];
$params = [];

if ($recherche !== '') {
    $where[] = '(j.details LIKE :q OR j.table_cible LIKE :q2 OR j.id_cible LIKE :q3)';
    $params[':q']  = '%' . $recherche . '%';
    $params[':q2'] = '%' . $recherche . '%';
    $params[':q3'] = '%' . $recherche . '%';
}
if ($filtreAct !== '') {
    $where[]        = 'j.action = :act';
    $params[':act'] = $filtreAct;
}
if ($filtreUti > 0) {
    $where[]        = 'j.utilisateur_id = :uti';
    $params[':uti'] = $filtreUti;
}
if (DateTime::createFromFormat('Y-m-d', $du)) {
    $where[]       = 'j.date_action >= :du';
    $params[':du'] = $du . ' 00:00:00';
}
if (DateTime::createFromFormat('Y-m-d', $au)) {
    $where[]       = 'j.date_action <= :au';
    $params[':au'] = $au . ' 23:59:59';
}
$clause = implode(' AND ', $where);

// ---------------------------------------------------------------------
// Comptage puis liste
// ---------------------------------------------------------------------
$stNb = db()->prepare('SELECT COUNT(*) FROM journal_audit j WHERE ' . $clause);
foreach ($params as $k => $v) {
    $stNb->bindValue($k, $v);
}
$stNb->execute();
$total = (int) $stNb->fetchColumn();

$p = paginer($total, entierPositif($_GET['page'] ?? 1));

$sql = 'SELECT j.id, j.action, j.table_cible, j.id_cible, j.details,
               j.adresse_ip, j.date_action,
               u.login, u.nom_complet
          FROM journal_audit j
     LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
         WHERE ' . $clause . '
      ORDER BY j.date_action DESC, j.id DESC
         LIMIT ' . LIGNES_PAR_PAGE . ' OFFSET ' . $p['offset'];

$st = db()->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->execute();
$lignes = $st->fetchAll();

// Référentiels pour les listes déroulantes
$actions = db()->query('SELECT DISTINCT action FROM journal_audit ORDER BY action')->fetchAll();
$comptes = db()->query('SELECT id, login, nom_complet FROM utilisateurs ORDER BY login')->fetchAll();

// Statistiques d'ensemble
$stats = db()->query(
    'SELECT action, COUNT(*) AS nb FROM journal_audit
      GROUP BY action ORDER BY nb DESC LIMIT 6'
)->fetchAll();

$couleurs = [
    'LOGIN'         => 'bg-success',
    'LOGOUT'        => 'bg-secondary',
    'LOGIN_ECHEC'   => 'bg-warning text-dark',
    'ACCES_REFUSE'  => 'bg-danger',
    'CREATE'        => 'bg-primary',
    'UPDATE'        => 'bg-info text-dark',
    'DELETE'        => 'bg-danger',
    'DUPLICATE'     => 'bg-primary',
    'EXPORT_CSV'    => 'bg-dark',
    'EXPORT_PDF'    => 'bg-dark',
    'CONSULTATION'  => 'bg-light text-dark border',
    'INSTALL'       => 'bg-dark',
];

$qs = http_build_query(array_filter([
    'q' => $recherche, 'action' => $filtreAct,
    'utilisateur' => $filtreUti ?: '', 'du' => $du, 'au' => $au,
], static fn($v) => $v !== '' && $v !== null));

afficherEntete('Journal d\'audit', $periode);
?>

<div class="alert alert-info small">
  Le journal recense les actions sensibles : connexions, tentatives échouées,
  accès refusés, créations, modifications, suppressions et exports.
  Il est <strong>consultable mais non modifiable</strong> depuis l'application.
</div>

<!-- ================= STATISTIQUES ================= -->
<div class="card mb-3">
  <div class="card-body py-3">
    <div class="d-flex flex-wrap gap-3 align-items-center">
      <span class="small text-muted">Répartition des actions enregistrées :</span>
      <?php foreach ($stats as $s): ?>
        <span class="badge <?= $couleurs[$s['action']] ?? 'bg-secondary' ?>">
          <?= e($s['action']) ?> · <?= (int) $s['nb'] ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ================= FILTRES ================= -->
<div class="card mb-3">
  <div class="card-body py-3">
    <form method="get" action="audit.php" class="row g-2 align-items-end">
      <div class="col-12 col-md-3">
        <label for="q" class="form-label small mb-1">Recherche</label>
        <input type="search" class="form-control form-control-sm" id="q" name="q"
               value="<?= e($recherche) ?>" placeholder="Détail, table ou cible">
      </div>

      <div class="col-6 col-md-2">
        <label for="faction" class="form-label small mb-1">Action</label>
        <select class="form-select form-select-sm" id="faction" name="action">
          <option value="">Toutes</option>
          <?php foreach ($actions as $a): ?>
            <option value="<?= e($a['action']) ?>"
              <?= $filtreAct === $a['action'] ? 'selected' : '' ?>>
              <?= e($a['action']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-3">
        <label for="futi" class="form-label small mb-1">Utilisateur</label>
        <select class="form-select form-select-sm" id="futi" name="utilisateur">
          <option value="">Tous</option>
          <?php foreach ($comptes as $c): ?>
            <option value="<?= (int) $c['id'] ?>"
              <?= $filtreUti === (int) $c['id'] ? 'selected' : '' ?>>
              <?= e($c['login']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <label for="du" class="form-label small mb-1">Du</label>
        <input type="date" class="form-control form-control-sm" id="du" name="du"
               value="<?= e($du) ?>">
      </div>

      <div class="col-6 col-md-2">
        <label for="au" class="form-label small mb-1">Au</label>
        <input type="date" class="form-control form-control-sm" id="au" name="au"
               value="<?= e($au) ?>">
      </div>

      <div class="col-12 d-flex gap-2 mt-2">
        <button type="submit" class="btn btn-sm btn-brique">Filtrer</button>
        <a href="audit.php" class="btn btn-sm btn-outline-secondary">Réinitialiser</a>
      </div>
    </form>
  </div>
</div>

<!-- ================= JOURNAL ================= -->
<div class="card">
  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
    <strong><?= nb((float) $total) ?> entrée(s)</strong>
    <span class="small text-muted">La plus récente en premier</span>
  </div>

  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead class="table-light">
        <tr>
          <th>Horodatage</th>
          <th>Utilisateur</th>
          <th>Action</th>
          <th>Cible</th>
          <th>Détail</th>
          <th>Adresse IP</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$lignes): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">
          Aucune entrée ne correspond à ces critères.
        </td></tr>
      <?php endif; ?>

      <?php foreach ($lignes as $l): ?>
        <tr>
          <td class="small text-nowrap">
            <?= e(date('d/m/Y', strtotime($l['date_action']))) ?><br>
            <span class="text-muted"><?= e(date('H:i:s', strtotime($l['date_action']))) ?></span>
          </td>
          <td class="small">
            <?php if ($l['login'] === null): ?>
              <span class="text-muted">compte supprimé</span>
            <?php else: ?>
              <code><?= e($l['login']) ?></code><br>
              <span class="text-muted"><?= e($l['nom_complet']) ?></span>
            <?php endif; ?>
          </td>
          <td>
            <span class="badge <?= $couleurs[$l['action']] ?? 'bg-secondary' ?>">
              <?= e($l['action']) ?>
            </span>
          </td>
          <td class="small text-muted">
            <?= e($l['table_cible'] ?? '—') ?>
            <?= $l['id_cible'] !== null ? '<br>' . e($l['id_cible']) : '' ?>
          </td>
          <td class="small"><?= e($l['details'] ?? '—') ?></td>
          <td class="small text-muted"><?= e($l['adresse_ip'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($p['total_pages'] > 1): ?>
  <div class="card-footer d-flex justify-content-between align-items-center">
    <span class="small text-muted">Page <?= $p['page'] ?> sur <?= $p['total_pages'] ?></span>
    <?= paginationHtml($p, 'audit.php?' . $qs) ?>
  </div>
  <?php endif; ?>
</div>

<p class="small text-muted mt-3 mb-0">
  La suppression d'un compte utilisateur ne supprime pas ses entrées : la clé
  étrangère est définie <code>ON DELETE SET NULL</code>, l'historique des actions
  reste donc consultable même après le départ d'un collaborateur.
</p>

<?php
afficherPied();
