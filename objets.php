<?php
/**
 * Référentiel des objets de coût (produits, clients, canaux, zones)
 * et saisie de leurs données économiques sur la période :
 * quantité produite, prix de vente, coût matières et main-d'œuvre directe.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];
$editable  = periodeModifiable($periode) && peutEcrire();

$typesObjet = ['PRODUIT', 'CLIENT', 'CANAL', 'ZONE'];

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
        $id      = entierPositif($_POST['id'] ?? 0);
        $code    = strtoupper(postTexte('code', 20));
        $libelle = postTexte('libelle', 150);
        $type    = postTexte('type_objet', 20);
        $famille = postTexte('famille', 80);
        $unite   = postTexte('unite', 30);
        $actif   = isset($_POST['actif']) ? 1 : 0;

        $quantite = postDecimal('quantite_produite');
        $prix     = postDecimal('prix_vente_unitaire');
        $matieres = postDecimal('cout_matieres');
        $mod      = postDecimal('cout_mod');

        if ($code === '')    { $erreurs[] = 'Le code est obligatoire.'; }
        if ($libelle === '') { $erreurs[] = 'Le libellé est obligatoire.'; }
        if (!in_array($type, $typesObjet, true)) {
            $erreurs[] = 'Type d\'objet de coût invalide.';
        }
        foreach ([
            'quantité produite' => $quantite,
            'prix de vente'     => $prix,
            'coût matières'     => $matieres,
            'coût de main-d\'œuvre directe' => $mod,
        ] as $nom => $valeur) {
            if ($valeur < 0) {
                $erreurs[] = 'Le ' . $nom . ' ne peut pas être négatif.';
            }
        }

        if (!$erreurs) {
            $st = db()->prepare('SELECT id FROM objets_cout WHERE code = :c AND id <> :i');
            $st->execute([':c' => $code, ':i' => $id]);
            if ($st->fetch()) {
                $erreurs[] = 'Le code ' . $code . ' est déjà utilisé.';
            }
        }

        if (!$erreurs) {
            try {
                db()->beginTransaction();

                if ($id > 0) {
                    $sql = 'UPDATE objets_cout
                               SET code = :c, libelle = :l, type_objet = :t,
                                   famille = :f, unite = :u, actif = :a
                             WHERE id = :i';
                    db()->prepare($sql)->execute([
                        ':c' => $code, ':l' => $libelle, ':t' => $type,
                        ':f' => $famille !== '' ? $famille : null,
                        ':u' => $unite !== '' ? $unite : null,
                        ':a' => $actif, ':i' => $id,
                    ]);
                    audit('UPDATE', 'objets_cout', (string) $id, 'Objet ' . $code);
                    $message = 'Objet de coût ' . $code . ' modifié.';
                } else {
                    $sql = 'INSERT INTO objets_cout (code, libelle, type_objet, famille, unite, actif)
                            VALUES (:c, :l, :t, :f, :u, :a)';
                    db()->prepare($sql)->execute([
                        ':c' => $code, ':l' => $libelle, ':t' => $type,
                        ':f' => $famille !== '' ? $famille : null,
                        ':u' => $unite !== '' ? $unite : null, ':a' => $actif,
                    ]);
                    $id = (int) db()->lastInsertId();
                    audit('CREATE', 'objets_cout', (string) $id, 'Objet ' . $code);
                    $message = 'Objet de coût ' . $code . ' créé.';
                }

                if (periodeModifiable($periode)) {
                    $sql = 'INSERT INTO couts_directs
                              (objet_cout_id, periode_id, quantite_produite,
                               prix_vente_unitaire, cout_matieres, cout_mod)
                            VALUES (:o, :p, :q, :pv, :ma, :mo)
                            ON DUPLICATE KEY UPDATE
                              quantite_produite   = VALUES(quantite_produite),
                              prix_vente_unitaire = VALUES(prix_vente_unitaire),
                              cout_matieres       = VALUES(cout_matieres),
                              cout_mod            = VALUES(cout_mod)';
                    db()->prepare($sql)->execute([
                        ':o' => $id, ':p' => $periodeId, ':q' => $quantite,
                        ':pv' => $prix, ':ma' => $matieres, ':mo' => $mod,
                    ]);
                }

                db()->commit();
                flash('success', $message);
                header('Location: objets.php?periode=' . $periodeId);
                exit;

            } catch (PDOException $ex) {
                db()->rollBack();
                $erreurs[] = 'Erreur d\'enregistrement : opération annulée.';
                error_log('Objets KO : ' . $ex->getMessage());
            }
        }
    }

    if ($action === 'supprimer') {
        $id = entierPositif($_POST['id'] ?? 0);

        $st = db()->prepare('SELECT COUNT(*) FROM consommations WHERE objet_cout_id = :i');
        $st->execute([':i' => $id]);
        $nbConso = (int) $st->fetchColumn();

        if ($nbConso > 0) {
            flash('danger', 'Suppression refusée : ' . $nbConso . ' consommation(s) '
                . 'd\'inducteur sont rattachées à cet objet. Désactivez-le plutôt.');
        } else {
            try {
                db()->prepare('DELETE FROM objets_cout WHERE id = :i')->execute([':i' => $id]);
                audit('DELETE', 'objets_cout', (string) $id, 'Suppression d\'objet de coût');
                flash('success', 'Objet de coût supprimé.');
            } catch (PDOException $ex) {
                flash('danger', 'Suppression impossible : l\'objet est utilisé ailleurs.');
            }
        }
        header('Location: objets.php?periode=' . $periodeId);
        exit;
    }
}

// ---------- Chargement pour modification ----------
$idEdition = entierPositif($_GET['modifier'] ?? 0);
if ($idEdition > 0) {
    $sql = 'SELECT o.*, cd.quantite_produite, cd.prix_vente_unitaire,
                   cd.cout_matieres, cd.cout_mod
              FROM objets_cout o
         LEFT JOIN couts_directs cd ON cd.objet_cout_id = o.id AND cd.periode_id = :p
             WHERE o.id = :i';
    $st = db()->prepare($sql);
    $st->execute([':p' => $periodeId, ':i' => $idEdition]);
    $edition = $st->fetch() ?: null;
}

// =====================================================================
//  LISTE
// =====================================================================
$recherche = trim((string) ($_GET['q'] ?? ''));
$filtreTyp = (string) ($_GET['type'] ?? '');
$filtreFam = trim((string) ($_GET['famille'] ?? ''));

$where  = ['1 = 1'];
$params = [];

if ($recherche !== '') {
    $where[] = '(o.code LIKE :q OR o.libelle LIKE :q2)';
    $params[':q']  = '%' . $recherche . '%';
    $params[':q2'] = '%' . $recherche . '%';
}
if (in_array($filtreTyp, $typesObjet, true)) {
    $where[]        = 'o.type_objet = :typ';
    $params[':typ'] = $filtreTyp;
}
if ($filtreFam !== '') {
    $where[]        = 'o.famille = :fam';
    $params[':fam'] = $filtreFam;
}
$clause = implode(' AND ', $where);

$stNb = db()->prepare('SELECT COUNT(*) FROM objets_cout o WHERE ' . $clause);
foreach ($params as $k => $v) {
    $stNb->bindValue($k, $v);
}
$stNb->execute();
$total = (int) $stNb->fetchColumn();

$p = paginer($total, entierPositif($_GET['page'] ?? 1));

$sql = 'SELECT o.id, o.code, o.libelle, o.type_objet, o.famille, o.unite, o.actif,
               COALESCE(cd.quantite_produite, 0)   AS quantite_produite,
               COALESCE(cd.prix_vente_unitaire, 0) AS prix_vente_unitaire,
               COALESCE(cd.cout_matieres, 0)       AS cout_matieres,
               COALESCE(cd.cout_mod, 0)            AS cout_mod,
               (SELECT COUNT(*) FROM consommations co
                 WHERE co.objet_cout_id = o.id AND co.periode_id = :p1) AS nb_conso
          FROM objets_cout o
     LEFT JOIN couts_directs cd ON cd.objet_cout_id = o.id AND cd.periode_id = :p2
         WHERE ' . $clause . '
      ORDER BY o.type_objet, o.code
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
$objets = $st->fetchAll();

$familles = db()->query('SELECT DISTINCT famille FROM objets_cout
                          WHERE famille IS NOT NULL ORDER BY famille')->fetchAll();

$nbInducteurs = (int) db()->query('SELECT COUNT(*) FROM inducteurs')->fetchColumn();

$qs = http_build_query(array_filter([
    'periode' => $periodeId, 'q' => $recherche,
    'type'    => $filtreTyp, 'famille' => $filtreFam,
], static fn($v) => $v !== '' && $v !== null));

afficherEntete('Objets de coût', $periode);
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
        <strong><?= $edition ? 'Modifier l\'objet de coût' : 'Nouvel objet de coût' ?></strong>
      </div>
      <div class="card-body">
        <form method="post" action="objets.php?periode=<?= $periodeId ?>"
              id="formObjet" novalidate>
          <?= csrfChamp() ?>
          <input type="hidden" name="action" value="enregistrer">
          <input type="hidden" name="id" value="<?= (int) ($edition['id'] ?? 0) ?>">

          <div class="row g-2 mb-3">
            <div class="col-5">
              <label for="code" class="form-label">Code <span class="text-danger">*</span></label>
              <input type="text" class="form-control text-uppercase" id="code" name="code"
                     value="<?= e($edition['code'] ?? '') ?>" required maxlength="20"
                     placeholder="B5">
            </div>
            <div class="col-7">
              <label for="type_objet" class="form-label">Type</label>
              <select class="form-select" id="type_objet" name="type_objet">
                <?php foreach ($typesObjet as $t): ?>
                  <option value="<?= e($t) ?>"
                    <?= ($edition['type_objet'] ?? 'PRODUIT') === $t ? 'selected' : '' ?>>
                    <?= e(ucfirst(strtolower($t))) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label for="libelle" class="form-label">Libellé <span class="text-danger">*</span></label>
            <textarea class="form-control" id="libelle" name="libelle" rows="2" required
                      maxlength="150"><?= e($edition['libelle'] ?? '') ?></textarea>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-7">
              <label for="famille" class="form-label">Famille</label>
              <input type="text" class="form-control" id="famille" name="famille"
                     value="<?= e($edition['famille'] ?? '') ?>" maxlength="80"
                     list="listeFamilles" placeholder="Maçonnerie">
              <datalist id="listeFamilles">
                <?php foreach ($familles as $f): ?>
                  <option value="<?= e($f['famille']) ?>"></option>
                <?php endforeach; ?>
              </datalist>
            </div>
            <div class="col-5">
              <label for="unite" class="form-label">Unité</label>
              <input type="text" class="form-control" id="unite" name="unite"
                     value="<?= e($edition['unite'] ?? '') ?>" maxlength="30"
                     placeholder="unité">
            </div>
          </div>

          <hr>
          <p class="fw-semibold small mb-3">
            Données de <?= e($periode['libelle']) ?>
          </p>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label for="quantite_produite" class="form-label">Quantité produite</label>
              <input type="number" class="form-control text-end" id="quantite_produite"
                     name="quantite_produite" min="0" step="0.0001"
                     value="<?= e(number_format((float) ($edition['quantite_produite'] ?? 0), 0, '.', '')) ?>">
            </div>
            <div class="col-6">
              <label for="prix_vente_unitaire" class="form-label">Prix de vente unitaire</label>
              <input type="number" class="form-control text-end" id="prix_vente_unitaire"
                     name="prix_vente_unitaire" min="0" step="0.01"
                     value="<?= e(number_format((float) ($edition['prix_vente_unitaire'] ?? 0), 2, '.', '')) ?>">
            </div>
          </div>

          <div class="mb-3">
            <label for="cout_matieres" class="form-label">Coût des matières consommées</label>
            <input type="number" class="form-control text-end" id="cout_matieres"
                   name="cout_matieres" min="0" step="0.01"
                   value="<?= e(number_format((float) ($edition['cout_matieres'] ?? 0), 2, '.', '')) ?>">
          </div>

          <div class="mb-3">
            <label for="cout_mod" class="form-label">Coût de main-d'œuvre directe</label>
            <input type="number" class="form-control text-end" id="cout_mod"
                   name="cout_mod" min="0" step="0.01"
                   value="<?= e(number_format((float) ($edition['cout_mod'] ?? 0), 2, '.', '')) ?>">
            <div class="form-text">
              Sert aussi de clé unique à la méthode classique, pour la comparaison.
            </div>
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="actif" name="actif" value="1"
              <?= (int) ($edition['actif'] ?? 1) === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="actif">Objet actif</label>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-brique">
              <?= $edition ? 'Enregistrer' : 'Créer' ?>
            </button>
            <?php if ($edition): ?>
              <a href="objets.php?periode=<?= $periodeId ?>" class="btn btn-outline-secondary">
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
        <form method="get" action="objets.php" class="row g-2 align-items-end">
          <input type="hidden" name="periode" value="<?= $periodeId ?>">
          <div class="col-12 col-sm-5">
            <label for="q" class="form-label small mb-1">Recherche</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   value="<?= e($recherche) ?>" placeholder="Code ou libellé">
          </div>
          <div class="col-6 col-sm-3">
            <label for="ftype" class="form-label small mb-1">Type</label>
            <select class="form-select form-select-sm" id="ftype" name="type">
              <option value="">Tous</option>
              <?php foreach ($typesObjet as $t): ?>
                <option value="<?= e($t) ?>" <?= $filtreTyp === $t ? 'selected' : '' ?>>
                  <?= e(ucfirst(strtolower($t))) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-sm-2">
            <label for="ffamille" class="form-label small mb-1">Famille</label>
            <select class="form-select form-select-sm" id="ffamille" name="famille">
              <option value="">Toutes</option>
              <?php foreach ($familles as $f): ?>
                <option value="<?= e($f['famille']) ?>"
                  <?= $filtreFam === $f['famille'] ? 'selected' : '' ?>>
                  <?= e($f['famille']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-brique w-100">Filtrer</button>
            <a href="objets.php?periode=<?= $periodeId ?>"
               class="btn btn-sm btn-outline-secondary">Tout</a>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><strong><?= $total ?> objet(s) de coût</strong></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Code</th>
              <th>Libellé</th>
              <th class="text-end">Quantité</th>
              <th class="text-end">Prix vente</th>
              <th class="text-end">Coûts directs</th>
              <th class="text-center">Inducteurs<br>saisis</th>
              <?php if ($editable): ?><th class="text-end">Actions</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (!$objets): ?>
            <tr><td colspan="7" class="text-center text-muted py-4">
              Aucun objet de coût ne correspond à ces critères.
            </td></tr>
          <?php endif; ?>

          <?php foreach ($objets as $o):
              $directs = (float) $o['cout_matieres'] + (float) $o['cout_mod'];
              $manque  = $nbInducteurs - (int) $o['nb_conso']; ?>
            <tr>
              <td>
                <span class="badge bg-secondary"><?= e($o['code']) ?></span>
                <?php if ((int) $o['actif'] !== 1): ?>
                  <span class="badge bg-secondary">Inactif</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?= e($o['libelle']) ?><br>
                <span class="text-muted">
                  <?= e(ucfirst(strtolower($o['type_objet']))) ?>
                  <?= $o['famille'] !== null ? ' · ' . e($o['famille']) : '' ?>
                  <?= $o['unite'] !== null ? ' · en ' . e($o['unite']) : '' ?>
                </span>
              </td>
              <td class="text-end"><?= nb((float) $o['quantite_produite']) ?></td>
              <td class="text-end"><?= fcfa((float) $o['prix_vente_unitaire']) ?></td>
              <td class="text-end"><?= fcfa($directs) ?></td>
              <td class="text-center small">
                <?php if ((int) $o['nb_conso'] === 0): ?>
                  <span class="badge bg-warning text-dark">aucune</span>
                <?php elseif ($manque > 0): ?>
                  <span class="badge bg-warning text-dark"
                        title="<?= $manque ?> activité(s) sans consommation saisie">
                    <?= (int) $o['nb_conso'] ?> / <?= $nbInducteurs ?>
                  </span>
                <?php else: ?>
                  <span class="badge bg-success"><?= (int) $o['nb_conso'] ?> / <?= $nbInducteurs ?></span>
                <?php endif; ?>
              </td>
              <?php if ($editable): ?>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary"
                   href="objets.php?periode=<?= $periodeId ?>&amp;modifier=<?= (int) $o['id'] ?>">
                  Modifier
                </a>
                <form method="post" action="objets.php?periode=<?= $periodeId ?>"
                      class="d-inline" onsubmit="return confirm('Supprimer l\'objet <?= e($o['code']) ?> ?');">
                  <?= csrfChamp() ?>
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"
                    <?= (int) $o['nb_conso'] > 0 ? 'disabled title="Consommations rattachées"' : '' ?>>
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
        <span class="small text-muted">Page <?= $p['page'] ?> sur <?= $p['total_pages'] ?></span>
        <?= paginationHtml($p, 'objets.php?' . $qs) ?>
      </div>
      <?php endif; ?>
    </div>

    <p class="small text-muted mt-3 mb-0">
      La colonne « Inducteurs saisis » compare le nombre de consommations
      enregistrées au nombre d'activités existantes. Un objet incomplet supporte
      moins de charges qu'il ne devrait : sa rentabilité est alors surévaluée.
    </p>
  </div>
</div>

<script>
document.getElementById('formObjet')?.addEventListener('submit', function (ev) {
  if (!this.checkValidity()) {
    ev.preventDefault();
    ev.stopPropagation();
  }
  this.classList.add('was-validated');
});
</script>

<?php
afficherPied();
