<?php
/**
 * Référentiel des ressources + saisie de leur montant sur la période.
 *
 * CRUD complet (Create, Read, Update, Delete) avec recherche multicritères,
 * pagination et contrôle d'intégrité avant suppression.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];
$editable  = periodeModifiable($periode) && peutEcrire();

$natures = ['PERSONNEL', 'ENERGIE', 'IMMOBILIER', 'AMORTISSEMENT',
            'FOURNITURE', 'SERVICE_EXTERIEUR', 'AUTRE'];

$erreurs = [];
$edition = null;

// =====================================================================
//  ACTIONS
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();
    exigerEcriture();

    $action = postTexte('action', 20);

    // ---------- Création / modification ----------
    if ($action === 'enregistrer') {
        $id      = entierPositif($_POST['id'] ?? 0);
        $code    = strtoupper(postTexte('code', 20));
        $libelle = postTexte('libelle', 120);
        $nature  = postTexte('nature', 30);
        $compte  = postTexte('compte_syscohada', 10);
        $actif   = isset($_POST['actif']) ? 1 : 0;
        $montant = postDecimal('montant');

        if ($code === '') {
            $erreurs[] = 'Le code de la ressource est obligatoire.';
        }
        if ($libelle === '') {
            $erreurs[] = 'Le libellé est obligatoire.';
        }
        if (!in_array($nature, $natures, true)) {
            $erreurs[] = 'La nature sélectionnée est invalide.';
        }
        if ($montant < 0) {
            $erreurs[] = 'Le montant ne peut pas être négatif.';
        }

        // Unicité du code
        if (!$erreurs) {
            $st = db()->prepare('SELECT id FROM ressources WHERE code = :c AND id <> :i');
            $st->execute([':c' => $code, ':i' => $id]);
            if ($st->fetch()) {
                $erreurs[] = 'Le code ' . $code . ' est déjà utilisé par une autre ressource.';
            }
        }

        if (!$erreurs) {
            try {
                db()->beginTransaction();

                if ($id > 0) {
                    $sql = 'UPDATE ressources
                               SET code = :c, libelle = :l, nature = :n,
                                   compte_syscohada = :s, actif = :a
                             WHERE id = :i';
                    db()->prepare($sql)->execute([
                        ':c' => $code, ':l' => $libelle, ':n' => $nature,
                        ':s' => $compte !== '' ? $compte : null,
                        ':a' => $actif, ':i' => $id,
                    ]);
                    audit('UPDATE', 'ressources', (string) $id, 'Ressource ' . $code);
                    $message = 'Ressource ' . $code . ' modifiée.';
                } else {
                    $sql = 'INSERT INTO ressources (code, libelle, nature, compte_syscohada, actif)
                            VALUES (:c, :l, :n, :s, :a)';
                    db()->prepare($sql)->execute([
                        ':c' => $code, ':l' => $libelle, ':n' => $nature,
                        ':s' => $compte !== '' ? $compte : null, ':a' => $actif,
                    ]);
                    $id = (int) db()->lastInsertId();
                    audit('CREATE', 'ressources', (string) $id, 'Ressource ' . $code);
                    $message = 'Ressource ' . $code . ' créée.';
                }

                // Montant de la période (upsert)
                if (periodeModifiable($periode)) {
                    $sql = 'INSERT INTO ressource_montants (ressource_id, periode_id, montant)
                            VALUES (:r, :p, :m)
                            ON DUPLICATE KEY UPDATE montant = VALUES(montant)';
                    db()->prepare($sql)->execute([
                        ':r' => $id, ':p' => $periodeId, ':m' => $montant,
                    ]);
                }

                db()->commit();
                flash('success', $message);
                header('Location: ressources.php?periode=' . $periodeId);
                exit;

            } catch (PDOException $ex) {
                db()->rollBack();
                $erreurs[] = 'Erreur d\'enregistrement : opération annulée.';
                error_log('Ressources KO : ' . $ex->getMessage());
            }
        }
    }

    // ---------- Suppression ----------
    if ($action === 'supprimer') {
        $id = entierPositif($_POST['id'] ?? 0);

        // Contrôle d'intégrité : refus si la ressource est déjà ventilée
        $st = db()->prepare('SELECT COUNT(*) FROM cles_ressources WHERE ressource_id = :i');
        $st->execute([':i' => $id]);
        $nbCles = (int) $st->fetchColumn();

        if ($nbCles > 0) {
            flash('danger', 'Suppression refusée : cette ressource est répartie dans '
                . $nbCles . ' clé(s). Désactivez-la plutôt que de la supprimer.');
        } else {
            try {
                db()->prepare('DELETE FROM ressources WHERE id = :i')->execute([':i' => $id]);
                audit('DELETE', 'ressources', (string) $id, 'Suppression de ressource');
                flash('success', 'Ressource supprimée.');
            } catch (PDOException $ex) {
                flash('danger', 'Suppression impossible : la ressource est utilisée ailleurs.');
            }
        }
        header('Location: ressources.php?periode=' . $periodeId);
        exit;
    }
}

// ---------- Chargement d'une ressource à modifier ----------
$idEdition = entierPositif($_GET['modifier'] ?? 0);
if ($idEdition > 0) {
    $sql = 'SELECT r.*, COALESCE(rm.montant, 0) AS montant
              FROM ressources r
         LEFT JOIN ressource_montants rm ON rm.ressource_id = r.id AND rm.periode_id = :p
             WHERE r.id = :i';
    $st = db()->prepare($sql);
    $st->execute([':p' => $periodeId, ':i' => $idEdition]);
    $edition = $st->fetch() ?: null;
}

// =====================================================================
//  LISTE : recherche multicritères + pagination
// =====================================================================
$recherche = trim((string) ($_GET['q'] ?? ''));
$filtreNat = (string) ($_GET['nature'] ?? '');
$filtreAct = (string) ($_GET['actif'] ?? '');

$where  = ['1 = 1'];
$params = [];

if ($recherche !== '') {
    $where[] = '(r.code LIKE :q OR r.libelle LIKE :q2 OR r.compte_syscohada LIKE :q3)';
    $params[':q']  = '%' . $recherche . '%';
    $params[':q2'] = '%' . $recherche . '%';
    $params[':q3'] = '%' . $recherche . '%';
}
if (in_array($filtreNat, $natures, true)) {
    $where[]           = 'r.nature = :nat';
    $params[':nat']    = $filtreNat;
}
if ($filtreAct === '1' || $filtreAct === '0') {
    $where[]        = 'r.actif = :act';
    $params[':act'] = (int) $filtreAct;
}
$clause = implode(' AND ', $where);

// Comptage pour la pagination
$sqlNb = 'SELECT COUNT(*) FROM ressources r WHERE ' . $clause;
$stNb  = db()->prepare($sqlNb);
foreach ($params as $k => $v) {
    $stNb->bindValue($k, $v);
}
$stNb->execute();
$total = (int) $stNb->fetchColumn();

$p = paginer($total, entierPositif($_GET['page'] ?? 1));

$sql = 'SELECT r.id, r.code, r.libelle, r.nature, r.compte_syscohada, r.actif,
               COALESCE(rm.montant, 0) AS montant,
               (SELECT COUNT(*) FROM cles_ressources c
                 WHERE c.ressource_id = r.id AND c.periode_id = :p1) AS nb_cles,
               (SELECT COALESCE(SUM(c.pourcentage), 0) FROM cles_ressources c
                 WHERE c.ressource_id = r.id AND c.periode_id = :p2) AS total_cles
          FROM ressources r
     LEFT JOIN ressource_montants rm ON rm.ressource_id = r.id AND rm.periode_id = :p3
         WHERE ' . $clause . '
      ORDER BY r.code
         LIMIT ' . LIGNES_PAR_PAGE . ' OFFSET ' . $p['offset'];

$st = db()->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
// La periode est liee autant de fois qu'elle apparait : PDO en mode
// requetes preparees natives n'autorise pas de reutiliser un marqueur.
$st->bindValue(':p1', $periodeId, PDO::PARAM_INT);
$st->bindValue(':p2', $periodeId, PDO::PARAM_INT);
$st->bindValue(':p3', $periodeId, PDO::PARAM_INT);
$st->execute();
$ressources = $st->fetchAll();

$totalMontants = (float) db()->query(
    'SELECT COALESCE(SUM(montant), 0) FROM ressource_montants WHERE periode_id = ' . $periodeId
)->fetchColumn();

// Conserve les filtres dans les liens de pagination
$qs = http_build_query(array_filter([
    'periode' => $periodeId,
    'q'       => $recherche,
    'nature'  => $filtreNat,
    'actif'   => $filtreAct,
], static fn($v) => $v !== '' && $v !== null));

afficherEntete('Référentiel des ressources', $periode);
?>

<?php if ($erreurs): ?>
  <div class="alert alert-danger">
    <ul class="mb-0 small">
      <?php foreach ($erreurs as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row g-3">
  <!-- ================= FORMULAIRE ================= -->
  <?php if ($editable): ?>
  <div class="col-12 col-xl-4">
    <div class="card">
      <div class="card-header">
        <strong><?= $edition ? 'Modifier la ressource' : 'Nouvelle ressource' ?></strong>
      </div>
      <div class="card-body">
        <form method="post" action="ressources.php?periode=<?= $periodeId ?>"
              id="formRessource" novalidate>
          <?= csrfChamp() ?>
          <input type="hidden" name="action" value="enregistrer">
          <input type="hidden" name="id" value="<?= (int) ($edition['id'] ?? 0) ?>">

          <div class="mb-3">
            <label for="code" class="form-label">Code <span class="text-danger">*</span></label>
            <input type="text" class="form-control text-uppercase" id="code" name="code"
                   value="<?= e($edition['code'] ?? '') ?>" required maxlength="20"
                   placeholder="R7">
            <div class="invalid-feedback">Le code est obligatoire.</div>
          </div>

          <div class="mb-3">
            <label for="libelle" class="form-label">Libellé <span class="text-danger">*</span></label>
            <textarea class="form-control" id="libelle" name="libelle" rows="2"
                      required maxlength="120"><?= e($edition['libelle'] ?? '') ?></textarea>
            <div class="invalid-feedback">Le libellé est obligatoire.</div>
          </div>

          <div class="mb-3">
            <label for="nature" class="form-label">Nature <span class="text-danger">*</span></label>
            <select class="form-select" id="nature" name="nature" required>
              <?php foreach ($natures as $n): ?>
                <option value="<?= e($n) ?>"
                  <?= ($edition['nature'] ?? '') === $n ? 'selected' : '' ?>>
                  <?= e(ucfirst(strtolower(str_replace('_', ' ', $n)))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="compte_syscohada" class="form-label">Compte SYSCOHADA</label>
            <input type="text" class="form-control" id="compte_syscohada"
                   name="compte_syscohada" maxlength="10"
                   value="<?= e($edition['compte_syscohada'] ?? '') ?>" placeholder="661">
            <div class="form-text">Rattachement au plan de comptes (facultatif).</div>
          </div>

          <div class="mb-3">
            <label for="montant" class="form-label">
              Montant sur <?= e($periode['libelle']) ?> (<?= e(APP_DEVISE) ?>)
            </label>
            <input type="number" class="form-control text-end" id="montant" name="montant"
                   value="<?= e(number_format((float) ($edition['montant'] ?? 0), 2, '.', '')) ?>"
                   min="0" step="0.01">
            <div class="form-text">Charge indirecte de la période à ventiler.</div>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="actif" name="actif" value="1"
              <?= (int) ($edition['actif'] ?? 1) === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="actif">Ressource active</label>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-brique">
              <?= $edition ? 'Enregistrer' : 'Créer' ?>
            </button>
            <?php if ($edition): ?>
              <a href="ressources.php?periode=<?= $periodeId ?>" class="btn btn-outline-secondary">
                Annuler
              </a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- ================= LISTE ================= -->
  <div class="col-12 <?= $editable ? 'col-xl-8' : '' ?>">

    <div class="card mb-3">
      <div class="card-body py-3">
        <form method="get" action="ressources.php" class="row g-2 align-items-end">
          <input type="hidden" name="periode" value="<?= $periodeId ?>">

          <div class="col-12 col-sm-5">
            <label for="q" class="form-label small mb-1">Recherche</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   value="<?= e($recherche) ?>" placeholder="Code, libellé ou compte">
          </div>

          <div class="col-6 col-sm-3">
            <label for="fnature" class="form-label small mb-1">Nature</label>
            <select class="form-select form-select-sm" id="fnature" name="nature">
              <option value="">Toutes</option>
              <?php foreach ($natures as $n): ?>
                <option value="<?= e($n) ?>" <?= $filtreNat === $n ? 'selected' : '' ?>>
                  <?= e(ucfirst(strtolower(str_replace('_', ' ', $n)))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-6 col-sm-2">
            <label for="factif" class="form-label small mb-1">État</label>
            <select class="form-select form-select-sm" id="factif" name="actif">
              <option value="">Tous</option>
              <option value="1" <?= $filtreAct === '1' ? 'selected' : '' ?>>Actives</option>
              <option value="0" <?= $filtreAct === '0' ? 'selected' : '' ?>>Inactives</option>
            </select>
          </div>

          <div class="col-12 col-sm-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-brique w-100">Filtrer</button>
            <a href="ressources.php?periode=<?= $periodeId ?>"
               class="btn btn-sm btn-outline-secondary">Tout</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <strong><?= $total ?> ressource(s)</strong>
        <span class="small text-muted">
          Total des charges indirectes de la période : <?= fcfa($totalMontants) ?>
        </span>
      </div>

      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Code</th>
              <th>Libellé</th>
              <th>Nature</th>
              <th>Compte</th>
              <th class="text-end">Montant période</th>
              <th class="text-center">Clés</th>
              <th class="text-center">État</th>
              <?php if ($editable): ?><th class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (!$ressources): ?>
            <tr><td colspan="8" class="text-center text-muted py-4">
              Aucune ressource ne correspond à ces critères.
            </td></tr>
          <?php endif; ?>

          <?php foreach ($ressources as $r):
              $conforme = abs(100 - (float) $r['total_cles']) < TOLERANCE_CLES; ?>
            <tr>
              <td><span class="badge bg-secondary"><?= e($r['code']) ?></span></td>
              <td class="small"><?= e($r['libelle']) ?></td>
              <td class="small text-muted">
                <?= e(ucfirst(strtolower(str_replace('_', ' ', $r['nature'])))) ?>
              </td>
              <td class="small text-muted"><?= e($r['compte_syscohada'] ?? '—') ?></td>
              <td class="text-end"><?= fcfa((float) $r['montant']) ?></td>
              <td class="text-center small">
                <?php if ((int) $r['nb_cles'] === 0): ?>
                  <span class="badge bg-warning text-dark">non ventilée</span>
                <?php elseif ($conforme): ?>
                  <span class="badge bg-success">100 %</span>
                <?php else: ?>
                  <span class="badge bg-danger"><?= nb((float) $r['total_cles'], 2) ?> %</span>
                <?php endif; ?>
              </td>
              <td class="text-center">
                <?php if ((int) $r['actif'] === 1): ?>
                  <span class="badge bg-light text-dark border">Active</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <?php if ($editable): ?>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary"
                   href="ressources.php?periode=<?= $periodeId ?>&amp;modifier=<?= (int) $r['id'] ?>">
                  Modifier
                </a>
                <form method="post" action="ressources.php?periode=<?= $periodeId ?>"
                      class="d-inline" onsubmit="return confirm('Supprimer la ressource <?= e($r['code']) ?> ? Cette action est irréversible.');">
                  <?= csrfChamp() ?>
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"
                    <?= (int) $r['nb_cles'] > 0 ? 'disabled title="Ressource déjà ventilée"' : '' ?>>
                    Supprimer
                  </button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($p['total_pages'] > 1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">
          Page <?= $p['page'] ?> sur <?= $p['total_pages'] ?>
        </span>
        <?= paginationHtml($p, 'ressources.php?' . $qs) ?>
      </div>
      <?php endif; ?>
    </div>

    <p class="small text-muted mt-3 mb-0">
      Une ressource déjà répartie dans des clés ne peut pas être supprimée :
      cela invaliderait les coûts historiques. Désactivez-la pour la retirer
      des futures saisies tout en conservant les périodes passées.
    </p>
  </div>
</div>

<script>
document.getElementById('formRessource')?.addEventListener('submit', function (ev) {
  if (!this.checkValidity()) {
    ev.preventDefault();
    ev.stopPropagation();
  }
  this.classList.add('was-validated');
});
</script>

<?php
afficherPied();
