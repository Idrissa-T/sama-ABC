<?php
/**
 * Référentiel des activités et de leurs inducteurs de coût.
 *
 * Règle de gestion : une activité possède un et un seul inducteur
 * (contrainte d'unicité en base). L'inducteur est donc géré dans le même
 * formulaire que l'activité, ce qui évite toute activité orpheline dont
 * les charges ne pourraient pas être imputées.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];
$editable  = periodeModifiable($periode) && peutEcrire();

$types   = ['PRINCIPALE', 'SUPPORT'];
$niveaux = ['UNITE', 'LOT', 'PRODUIT', 'ENTREPRISE'];
$libNiveaux = [
    'UNITE'      => 'Unité — proportionnel au volume produit',
    'LOT'        => 'Lot — par série ou par commande',
    'PRODUIT'    => 'Produit — par référence gérée',
    'ENTREPRISE' => 'Entreprise — charge de structure',
];

$erreurs = [];
$edition = null;

// =====================================================================
//  ACTIONS
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();
    exigerEcriture();

    $action = postTexte('action', 20);

    if ($action === 'enregistrer') {
        $id        = entierPositif($_POST['id'] ?? 0);
        $code      = strtoupper(postTexte('code', 20));
        $libelle   = postTexte('libelle', 150);
        $processus = postTexte('processus', 80);
        $type      = postTexte('type_activite', 20);
        $niveau    = postTexte('niveau_hierarchique', 20);
        $actif     = isset($_POST['actif']) ? 1 : 0;

        $indLibelle = postTexte('inducteur_libelle', 120);
        $indUnite   = postTexte('unite_oeuvre', 50);
        $indCapa    = postDecimal('capacite_pratique', -1);

        if ($code === '')       { $erreurs[] = 'Le code de l\'activité est obligatoire.'; }
        if ($libelle === '')    { $erreurs[] = 'Le libellé de l\'activité est obligatoire.'; }
        if ($processus === '')  { $erreurs[] = 'Le processus de rattachement est obligatoire.'; }
        if (!in_array($type, $types, true))      { $erreurs[] = 'Type d\'activité invalide.'; }
        if (!in_array($niveau, $niveaux, true))  { $erreurs[] = 'Niveau hiérarchique invalide.'; }
        if ($indLibelle === '') { $erreurs[] = 'Le libellé de l\'inducteur est obligatoire.'; }
        if ($indUnite === '')   { $erreurs[] = 'L\'unité d\'œuvre est obligatoire.'; }

        if (!$erreurs) {
            $st = db()->prepare('SELECT id FROM activites WHERE code = :c AND id <> :i');
            $st->execute([':c' => $code, ':i' => $id]);
            if ($st->fetch()) {
                $erreurs[] = 'Le code ' . $code . ' est déjà utilisé par une autre activité.';
            }
        }

        if (!$erreurs) {
            try {
                db()->beginTransaction();

                if ($id > 0) {
                    $sql = 'UPDATE activites
                               SET code = :c, libelle = :l, processus = :pr,
                                   type_activite = :t, niveau_hierarchique = :n, actif = :a
                             WHERE id = :i';
                    db()->prepare($sql)->execute([
                        ':c' => $code, ':l' => $libelle, ':pr' => $processus,
                        ':t' => $type, ':n' => $niveau, ':a' => $actif, ':i' => $id,
                    ]);
                    audit('UPDATE', 'activites', (string) $id, 'Activité ' . $code);
                    $message = 'Activité ' . $code . ' modifiée.';
                } else {
                    $sql = 'INSERT INTO activites
                              (code, libelle, processus, type_activite, niveau_hierarchique, actif)
                            VALUES (:c, :l, :pr, :t, :n, :a)';
                    db()->prepare($sql)->execute([
                        ':c' => $code, ':l' => $libelle, ':pr' => $processus,
                        ':t' => $type, ':n' => $niveau, ':a' => $actif,
                    ]);
                    $id = (int) db()->lastInsertId();
                    audit('CREATE', 'activites', (string) $id, 'Activité ' . $code);
                    $message = 'Activité ' . $code . ' créée avec son inducteur.';
                }

                // Inducteur : un seul par activité
                $sql = 'INSERT INTO inducteurs
                          (activite_id, libelle, unite_oeuvre, capacite_pratique)
                        VALUES (:a, :l, :u, :c)
                        ON DUPLICATE KEY UPDATE
                          libelle = VALUES(libelle),
                          unite_oeuvre = VALUES(unite_oeuvre),
                          capacite_pratique = VALUES(capacite_pratique)';
                db()->prepare($sql)->execute([
                    ':a' => $id, ':l' => $indLibelle, ':u' => $indUnite,
                    ':c' => $indCapa >= 0 ? $indCapa : null,
                ]);

                db()->commit();
                flash('success', $message);
                header('Location: activites.php?periode=' . $periodeId);
                exit;

            } catch (PDOException $ex) {
                db()->rollBack();
                $erreurs[] = 'Erreur d\'enregistrement : opération annulée.';
                error_log('Activites KO : ' . $ex->getMessage());
            }
        }
    }

    if ($action === 'supprimer') {
        $id = entierPositif($_POST['id'] ?? 0);

        $st = db()->prepare('SELECT COUNT(*) FROM cles_ressources WHERE activite_id = :i');
        $st->execute([':i' => $id]);
        $nbCles = (int) $st->fetchColumn();

        $st = db()->prepare('SELECT COUNT(*) FROM consommations co
                               JOIN inducteurs i ON i.id = co.inducteur_id
                              WHERE i.activite_id = :i');
        $st->execute([':i' => $id]);
        $nbConso = (int) $st->fetchColumn();

        if ($nbCles > 0 || $nbConso > 0) {
            flash('danger', sprintf(
                'Suppression refusée : cette activité porte %d clé(s) de répartition et '
                . '%d consommation(s) d\'inducteur. Désactivez-la plutôt.',
                $nbCles, $nbConso
            ));
        } else {
            try {
                db()->prepare('DELETE FROM activites WHERE id = :i')->execute([':i' => $id]);
                audit('DELETE', 'activites', (string) $id, 'Suppression d\'activité');
                flash('success', 'Activité supprimée (son inducteur également).');
            } catch (PDOException $ex) {
                flash('danger', 'Suppression impossible : l\'activité est utilisée ailleurs.');
            }
        }
        header('Location: activites.php?periode=' . $periodeId);
        exit;
    }
}

// ---------- Chargement pour modification ----------
$idEdition = entierPositif($_GET['modifier'] ?? 0);
if ($idEdition > 0) {
    $sql = 'SELECT a.*, i.libelle AS inducteur_libelle, i.unite_oeuvre, i.capacite_pratique
              FROM activites a
         LEFT JOIN inducteurs i ON i.activite_id = a.id
             WHERE a.id = :i';
    $st = db()->prepare($sql);
    $st->execute([':i' => $idEdition]);
    $edition = $st->fetch() ?: null;
}

// =====================================================================
//  LISTE
// =====================================================================
$recherche = trim((string) ($_GET['q'] ?? ''));
$filtreTyp = (string) ($_GET['type'] ?? '');
$filtreNiv = (string) ($_GET['niveau'] ?? '');

$where  = ['1 = 1'];
$params = [];

if ($recherche !== '') {
    $where[] = '(a.code LIKE :q OR a.libelle LIKE :q2 OR a.processus LIKE :q3)';
    $params[':q']  = '%' . $recherche . '%';
    $params[':q2'] = '%' . $recherche . '%';
    $params[':q3'] = '%' . $recherche . '%';
}
if (in_array($filtreTyp, $types, true)) {
    $where[]        = 'a.type_activite = :typ';
    $params[':typ'] = $filtreTyp;
}
if (in_array($filtreNiv, $niveaux, true)) {
    $where[]        = 'a.niveau_hierarchique = :niv';
    $params[':niv'] = $filtreNiv;
}
$clause = implode(' AND ', $where);

$stNb = db()->prepare('SELECT COUNT(*) FROM activites a WHERE ' . $clause);
foreach ($params as $k => $v) {
    $stNb->bindValue($k, $v);
}
$stNb->execute();
$total = (int) $stNb->fetchColumn();

$p = paginer($total, entierPositif($_GET['page'] ?? 1));

$sql = 'SELECT a.id, a.code, a.libelle, a.processus, a.type_activite,
               a.niveau_hierarchique, a.actif,
               i.libelle AS inducteur_libelle, i.unite_oeuvre, i.capacite_pratique,
               (SELECT COALESCE(SUM(rm.montant * c.pourcentage / 100), 0)
                  FROM cles_ressources c
                  JOIN ressource_montants rm ON rm.ressource_id = c.ressource_id
                                            AND rm.periode_id = c.periode_id
                 WHERE c.activite_id = a.id AND c.periode_id = :p1) AS cout_activite,
               (SELECT COALESCE(SUM(co.quantite), 0) FROM consommations co
                 WHERE co.inducteur_id = i.id AND co.periode_id = :p2) AS volume
          FROM activites a
     LEFT JOIN inducteurs i ON i.activite_id = a.id
         WHERE ' . $clause . '
      ORDER BY a.code
         LIMIT ' . LIGNES_PAR_PAGE . ' OFFSET ' . $p['offset'];

$st = db()->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
// La periode est liee autant de fois qu'elle apparait : PDO en mode
// requetes preparees natives n'autorise pas de reutiliser un marqueur.
$st->bindValue(':p1', $periodeId, PDO::PARAM_INT);
$st->bindValue(':p2', $periodeId, PDO::PARAM_INT);
$st->execute();
$activites = $st->fetchAll();

$qs = http_build_query(array_filter([
    'periode' => $periodeId, 'q' => $recherche,
    'type'    => $filtreTyp, 'niveau' => $filtreNiv,
], static fn($v) => $v !== '' && $v !== null));

afficherEntete('Activités et inducteurs de coût', $periode);
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
        <strong><?= $edition ? 'Modifier l\'activité' : 'Nouvelle activité' ?></strong>
      </div>
      <div class="card-body">
        <form method="post" action="activites.php?periode=<?= $periodeId ?>"
              id="formActivite" novalidate>
          <?= csrfChamp() ?>
          <input type="hidden" name="action" value="enregistrer">
          <input type="hidden" name="id" value="<?= (int) ($edition['id'] ?? 0) ?>">

          <div class="mb-3">
            <label for="code" class="form-label">Code <span class="text-danger">*</span></label>
            <input type="text" class="form-control text-uppercase" id="code" name="code"
                   value="<?= e($edition['code'] ?? '') ?>" required maxlength="20"
                   placeholder="A9">
          </div>

          <div class="mb-3">
            <label for="libelle" class="form-label">Libellé <span class="text-danger">*</span></label>
            <textarea class="form-control" id="libelle" name="libelle" rows="2" required
                      maxlength="150"
                      placeholder="Verbe à l'infinitif + complément"><?= e($edition['libelle'] ?? '') ?></textarea>
            <div class="form-text">Une activité se nomme par un verbe d'action.</div>
          </div>

          <div class="mb-3">
            <label for="processus" class="form-label">Processus <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="processus" name="processus"
                   value="<?= e($edition['processus'] ?? '') ?>" required maxlength="80"
                   list="listeProcessus" placeholder="Production">
            <datalist id="listeProcessus">
              <?php foreach (db()->query('SELECT DISTINCT processus FROM activites ORDER BY processus') as $pr): ?>
                <option value="<?= e($pr['processus']) ?>"></option>
              <?php endforeach; ?>
            </datalist>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label for="type_activite" class="form-label">Type</label>
              <select class="form-select" id="type_activite" name="type_activite">
                <?php foreach ($types as $t): ?>
                  <option value="<?= e($t) ?>"
                    <?= ($edition['type_activite'] ?? 'PRINCIPALE') === $t ? 'selected' : '' ?>>
                    <?= e(ucfirst(strtolower($t))) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6">
              <label for="niveau_hierarchique" class="form-label">Niveau</label>
              <select class="form-select" id="niveau_hierarchique" name="niveau_hierarchique">
                <?php foreach ($niveaux as $n): ?>
                  <option value="<?= e($n) ?>"
                    title="<?= e($libNiveaux[$n]) ?>"
                    <?= ($edition['niveau_hierarchique'] ?? 'UNITE') === $n ? 'selected' : '' ?>>
                    <?= e(ucfirst(strtolower($n))) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <hr>
          <p class="fw-semibold small mb-3">Inducteur de coût de cette activité</p>

          <div class="mb-3">
            <label for="inducteur_libelle" class="form-label">
              Libellé de l'inducteur <span class="text-danger">*</span>
            </label>
            <input type="text" class="form-control" id="inducteur_libelle"
                   name="inducteur_libelle" required maxlength="120"
                   value="<?= e($edition['inducteur_libelle'] ?? '') ?>"
                   placeholder="Nombre de changements de moule">
          </div>

          <div class="mb-3">
            <label for="unite_oeuvre" class="form-label">
              Unité d'œuvre <span class="text-danger">*</span>
            </label>
            <input type="text" class="form-control" id="unite_oeuvre" name="unite_oeuvre"
                   required maxlength="50" value="<?= e($edition['unite_oeuvre'] ?? '') ?>"
                   placeholder="changement">
          </div>

          <div class="mb-3">
            <label for="capacite_pratique" class="form-label">Capacité pratique</label>
            <input type="number" class="form-control text-end" id="capacite_pratique"
                   name="capacite_pratique" min="0" step="0.0001"
                   value="<?= $edition && $edition['capacite_pratique'] !== null
                       ? e(rtrim(rtrim(number_format((float) $edition['capacite_pratique'], 4, '.', ''), '0'), '.'))
                       : '' ?>">
            <div class="form-text">
              Facultatif. Permet le calcul TDABC du coût de la capacité non utilisée.
            </div>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="actif" name="actif" value="1"
              <?= (int) ($edition['actif'] ?? 1) === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="actif">Activité active</label>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-brique">
              <?= $edition ? 'Enregistrer' : 'Créer' ?>
            </button>
            <?php if ($edition): ?>
              <a href="activites.php?periode=<?= $periodeId ?>" class="btn btn-outline-secondary">
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
        <form method="get" action="activites.php" class="row g-2 align-items-end">
          <input type="hidden" name="periode" value="<?= $periodeId ?>">
          <div class="col-12 col-sm-5">
            <label for="q" class="form-label small mb-1">Recherche</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   value="<?= e($recherche) ?>" placeholder="Code, libellé ou processus">
          </div>
          <div class="col-6 col-sm-3">
            <label for="ftype" class="form-label small mb-1">Type</label>
            <select class="form-select form-select-sm" id="ftype" name="type">
              <option value="">Tous</option>
              <?php foreach ($types as $t): ?>
                <option value="<?= e($t) ?>" <?= $filtreTyp === $t ? 'selected' : '' ?>>
                  <?= e(ucfirst(strtolower($t))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-sm-2">
            <label for="fniveau" class="form-label small mb-1">Niveau</label>
            <select class="form-select form-select-sm" id="fniveau" name="niveau">
              <option value="">Tous</option>
              <?php foreach ($niveaux as $n): ?>
                <option value="<?= e($n) ?>" <?= $filtreNiv === $n ? 'selected' : '' ?>>
                  <?= e(ucfirst(strtolower($n))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-brique w-100">Filtrer</button>
            <a href="activites.php?periode=<?= $periodeId ?>"
               class="btn btn-sm btn-outline-secondary">Tout</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><strong><?= $total ?> activité(s)</strong></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Code</th>
              <th>Activité</th>
              <th>Inducteur</th>
              <th class="text-end">Coût période</th>
              <th class="text-end">Volume</th>
              <th class="text-end">Coût unitaire</th>
              <?php if ($editable): ?><th class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (!$activites): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">
              Aucune activité ne correspond à ces critères.
            </td></tr>
          <?php endif; ?>

          <?php foreach ($activites as $a):
              $vol = (float) $a['volume'];
              $cu  = $vol > 0 ? (float) $a['cout_activite'] / $vol : null; ?>
            <tr>
              <td>
                <span class="badge bg-secondary"><?= e($a['code']) ?></span>
                <?php if ((int) $a['actif'] !== 1): ?>
                  <span class="badge bg-secondary">Inactive</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?= e($a['libelle']) ?><br>
                <span class="text-muted">
                  <?= e($a['processus']) ?> ·
                  <?= e(ucfirst(strtolower($a['type_activite']))) ?> ·
                  niveau <?= e(strtolower($a['niveau_hierarchique'])) ?>
                </span>
              </td>
              <td class="small">
                <?php if ($a['inducteur_libelle'] === null): ?>
                  <span class="badge bg-danger">aucun inducteur</span>
                <?php else: ?>
                  <?= e($a['inducteur_libelle']) ?><br>
                  <span class="text-muted">en <?= e($a['unite_oeuvre']) ?></span>
                <?php endif; ?>
              </td>
              <td class="text-end"><?= fcfa((float) $a['cout_activite']) ?></td>
              <td class="text-end"><?= $vol > 0 ? nb($vol) : '—' ?></td>
              <td class="text-end fw-semibold">
                <?= $cu === null ? '—' : fcfa($cu, 2) ?>
              </td>
              <?php if ($editable): ?>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary"
                   href="activites.php?periode=<?= $periodeId ?>&amp;modifier=<?= (int) $a['id'] ?>">
                  Modifier
                </a>
                <form method="post" action="activites.php?periode=<?= $periodeId ?>"
                      class="d-inline" onsubmit="return confirm('Supprimer l\'activité <?= e($a['code']) ?> et son inducteur ?');">
                  <?= csrfChamp() ?>
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
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
        <span class="small text-muted">Page <?= $p['page'] ?> sur <?= $p['total_pages'] ?></span>
        <?= paginationHtml($p, 'activites.php?' . $qs) ?>
      </div>
      <?php endif; ?>
    </div>

    <p class="small text-muted mt-3 mb-0">
      Créer une activité ne suffit pas à lui affecter des charges : il faut ensuite
      la servir dans les <a href="cles.php?periode=<?= $periodeId ?>">clés de répartition</a>
      et saisir les <a href="consommations.php?periode=<?= $periodeId ?>">consommations
      d'inducteur</a> des objets de coût.
    </p>
  </div>
</div>

<script>
document.getElementById('formActivite')?.addEventListener('submit', function (ev) {
  if (!this.checkValidity()) {
    ev.preventDefault();
    ev.stopPropagation();
  }
  this.classList.add('was-validated');
});
</script>

<?php
afficherPied();
