<?php
/**
 * Administration des périodes d'analyse.
 *
 * Réservé au profil ADMIN. Trois opérations sensibles :
 *   - la clôture, qui verrouille la période en lecture seule ;
 *   - la duplication, qui recopie la structure d'une période dans une autre
 *     (montants, clés, coûts directs, consommations) pour éviter une ressaisie ;
 *   - la suppression, refusée dès que la période porte des données.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerRole('ADMIN');

$periode   = periodeActive();
$periodeId = (int) $periode['id'];

$erreurs = [];
$edition = null;

// =====================================================================
//  ACTIONS
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();
    $action = postTexte('action', 20);

    // ---------- Création / modification ----------
    if ($action === 'enregistrer') {
        $id      = entierPositif($_POST['id'] ?? 0);
        $code    = postTexte('code', 20);
        $libelle = postTexte('libelle', 80);
        $debut   = postTexte('date_debut', 10);
        $fin     = postTexte('date_fin', 10);

        if ($code === '')    { $erreurs[] = 'Le code de la période est obligatoire.'; }
        if ($libelle === '') { $erreurs[] = 'Le libellé est obligatoire.'; }

        $dDebut = DateTime::createFromFormat('Y-m-d', $debut);
        $dFin   = DateTime::createFromFormat('Y-m-d', $fin);

        if (!$dDebut) { $erreurs[] = 'La date de début est invalide.'; }
        if (!$dFin)   { $erreurs[] = 'La date de fin est invalide.'; }
        if ($dDebut && $dFin && $dFin < $dDebut) {
            $erreurs[] = 'La date de fin ne peut pas précéder la date de début.';
        }

        if (!$erreurs) {
            $st = db()->prepare('SELECT id FROM periodes WHERE code = :c AND id <> :i');
            $st->execute([':c' => $code, ':i' => $id]);
            if ($st->fetch()) {
                $erreurs[] = 'Le code ' . $code . ' est déjà utilisé.';
            }
        }

        if (!$erreurs) {
            try {
                if ($id > 0) {
                    $sql = 'UPDATE periodes SET code = :c, libelle = :l,
                                   date_debut = :d, date_fin = :f
                             WHERE id = :i';
                    db()->prepare($sql)->execute([
                        ':c' => $code, ':l' => $libelle,
                        ':d' => $debut, ':f' => $fin, ':i' => $id,
                    ]);
                    audit('UPDATE', 'periodes', (string) $id, 'Période ' . $code);
                    flash('success', 'Période ' . $code . ' modifiée.');
                } else {
                    $sql = 'INSERT INTO periodes (code, libelle, date_debut, date_fin, statut)
                            VALUES (:c, :l, :d, :f, :s)';
                    db()->prepare($sql)->execute([
                        ':c' => $code, ':l' => $libelle,
                        ':d' => $debut, ':f' => $fin, ':s' => 'OUVERTE',
                    ]);
                    $id = (int) db()->lastInsertId();
                    audit('CREATE', 'periodes', (string) $id, 'Période ' . $code);
                    flash('success', 'Période ' . $code . ' créée. Elle est ouverte à la saisie.');
                }
                header('Location: periodes.php?periode=' . $id);
                exit;

            } catch (PDOException $ex) {
                $erreurs[] = 'Erreur d\'enregistrement.';
                error_log('Periodes KO : ' . $ex->getMessage());
            }
        }
    }

    // ---------- Clôture / réouverture ----------
    if ($action === 'basculer') {
        $id     = entierPositif($_POST['id'] ?? 0);
        $st     = db()->prepare('SELECT code, statut FROM periodes WHERE id = :i');
        $st->execute([':i' => $id]);
        $cible  = $st->fetch();

        if ($cible) {
            $nouveau = $cible['statut'] === 'OUVERTE' ? 'CLOTUREE' : 'OUVERTE';
            db()->prepare('UPDATE periodes SET statut = :s WHERE id = :i')
                ->execute([':s' => $nouveau, ':i' => $id]);
            audit('UPDATE', 'periodes', (string) $id,
                  'Passage de la période ' . $cible['code'] . ' en ' . $nouveau);
            flash('success', 'Période ' . $cible['code'] . ' désormais '
                . ($nouveau === 'CLOTUREE' ? 'clôturée : plus aucune saisie possible.'
                                           : 'réouverte à la saisie.'));
        }
        header('Location: periodes.php?periode=' . $periodeId);
        exit;
    }

    // ---------- Duplication ----------
    if ($action === 'dupliquer') {
        $source = entierPositif($_POST['source'] ?? 0);
        $cible  = entierPositif($_POST['cible'] ?? 0);
        $quoi   = is_array($_POST['quoi'] ?? null) ? $_POST['quoi'] : [];

        if ($source === 0 || $cible === 0 || $source === $cible) {
            flash('danger', 'Sélectionnez une période source et une période cible distinctes.');
            header('Location: periodes.php?periode=' . $periodeId);
            exit;
        }

        $st = db()->prepare('SELECT statut FROM periodes WHERE id = :i');
        $st->execute([':i' => $cible]);
        if (($st->fetchColumn() ?: '') === 'CLOTUREE') {
            flash('danger', 'La période cible est clôturée : rouvrez-la d\'abord.');
            header('Location: periodes.php?periode=' . $periodeId);
            exit;
        }

        $requetes = [
            'montants' => 'INSERT INTO ressource_montants (ressource_id, periode_id, montant)
                           SELECT ressource_id, :cible, montant
                             FROM ressource_montants WHERE periode_id = :source
                           ON DUPLICATE KEY UPDATE montant = VALUES(montant)',
            'cles'     => 'INSERT INTO cles_ressources
                             (ressource_id, activite_id, periode_id, pourcentage, justification)
                           SELECT ressource_id, activite_id, :cible, pourcentage, justification
                             FROM cles_ressources WHERE periode_id = :source
                           ON DUPLICATE KEY UPDATE pourcentage = VALUES(pourcentage),
                                                   justification = VALUES(justification)',
            'directs'  => 'INSERT INTO couts_directs
                             (objet_cout_id, periode_id, quantite_produite,
                              prix_vente_unitaire, cout_matieres, cout_mod)
                           SELECT objet_cout_id, :cible, quantite_produite,
                                  prix_vente_unitaire, cout_matieres, cout_mod
                             FROM couts_directs WHERE periode_id = :source
                           ON DUPLICATE KEY UPDATE
                             quantite_produite   = VALUES(quantite_produite),
                             prix_vente_unitaire = VALUES(prix_vente_unitaire),
                             cout_matieres       = VALUES(cout_matieres),
                             cout_mod            = VALUES(cout_mod)',
            'conso'    => 'INSERT INTO consommations
                             (inducteur_id, objet_cout_id, periode_id, quantite)
                           SELECT inducteur_id, objet_cout_id, :cible, quantite
                             FROM consommations WHERE periode_id = :source
                           ON DUPLICATE KEY UPDATE quantite = VALUES(quantite)',
        ];

        try {
            db()->beginTransaction();
            $faits = [];
            foreach ($requetes as $cle => $sql) {
                if (in_array($cle, $quoi, true)) {
                    db()->prepare($sql)->execute([':cible' => $cible, ':source' => $source]);
                    $faits[] = $cle;
                }
            }
            db()->commit();

            audit('DUPLICATE', 'periodes', (string) $cible,
                  'Duplication depuis la période ' . $source . ' : ' . implode(', ', $faits));
            flash('success', $faits
                ? 'Duplication effectuée : ' . implode(', ', $faits) . '.'
                : 'Aucun élément sélectionné : rien n\'a été copié.');

        } catch (PDOException $ex) {
            db()->rollBack();
            flash('danger', 'Duplication impossible : aucune modification appliquée.');
            error_log('Duplication KO : ' . $ex->getMessage());
        }
        header('Location: periodes.php?periode=' . $cible);
        exit;
    }

    // ---------- Suppression ----------
    if ($action === 'supprimer') {
        $id = entierPositif($_POST['id'] ?? 0);

        $nb = 0;
        foreach (['ressource_montants', 'cles_ressources', 'couts_directs', 'consommations'] as $t) {
            $st = db()->prepare('SELECT COUNT(*) FROM ' . $t . ' WHERE periode_id = :i');
            $st->execute([':i' => $id]);
            $nb += (int) $st->fetchColumn();
        }

        if ($nb > 0) {
            flash('danger', 'Suppression refusée : cette période porte ' . $nb
                . ' enregistrement(s). Une période renseignée ne se supprime pas.');
        } else {
            db()->prepare('DELETE FROM periodes WHERE id = :i')->execute([':i' => $id]);
            audit('DELETE', 'periodes', (string) $id, 'Suppression de période vide');
            flash('success', 'Période supprimée.');
        }
        header('Location: periodes.php');
        exit;
    }
}

// ---------- Chargement pour modification ----------
$idEdition = entierPositif($_GET['modifier'] ?? 0);
if ($idEdition > 0) {
    $st = db()->prepare('SELECT * FROM periodes WHERE id = :i');
    $st->execute([':i' => $idEdition]);
    $edition = $st->fetch() ?: null;
}

// =====================================================================
//  LISTE
// =====================================================================
$sql = 'SELECT p.id, p.code, p.libelle, p.date_debut, p.date_fin, p.statut,
               (SELECT COUNT(*) FROM ressource_montants r WHERE r.periode_id = p.id) AS nb_montants,
               (SELECT COUNT(*) FROM cles_ressources c WHERE c.periode_id = p.id)    AS nb_cles,
               (SELECT COUNT(*) FROM couts_directs d WHERE d.periode_id = p.id)      AS nb_directs,
               (SELECT COUNT(*) FROM consommations o WHERE o.periode_id = p.id)      AS nb_conso
          FROM periodes p
      ORDER BY p.date_debut DESC';
$periodes = db()->query($sql)->fetchAll();

afficherEntete('Administration des périodes', $periode);
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
  <div class="col-12 col-xl-4">
    <div class="card mb-3">
      <div class="card-header">
        <strong><?= $edition ? 'Modifier la période' : 'Nouvelle période' ?></strong>
      </div>
      <div class="card-body">
        <form method="post" action="periodes.php" novalidate>
          <?= csrfChamp() ?>
          <input type="hidden" name="action" value="enregistrer">
          <input type="hidden" name="id" value="<?= (int) ($edition['id'] ?? 0) ?>">

          <div class="mb-3">
            <label for="code" class="form-label">Code <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="code" name="code" required maxlength="20"
                   value="<?= e($edition['code'] ?? '') ?>" placeholder="2026-07">
            <div class="form-text">Convention conseillée : AAAA-MM.</div>
          </div>

          <div class="mb-3">
            <label for="libelle" class="form-label">Libellé <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="libelle" name="libelle" required
                   maxlength="80" value="<?= e($edition['libelle'] ?? '') ?>"
                   placeholder="Juillet 2026">
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label for="date_debut" class="form-label">Début <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="date_debut" name="date_debut" required
                     value="<?= e($edition['date_debut'] ?? '') ?>">
            </div>
            <div class="col-6">
              <label for="date_fin" class="form-label">Fin <span class="text-danger">*</span></label>
              <input type="date" class="form-control" id="date_fin" name="date_fin" required
                     value="<?= e($edition['date_fin'] ?? '') ?>">
            </div>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-brique">
              <?= $edition ? 'Enregistrer' : 'Créer' ?>
            </button>
            <?php if ($edition): ?>
              <a href="periodes.php" class="btn btn-outline-secondary">Annuler</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- ================= DUPLICATION ================= -->
    <div class="card">
      <div class="card-header"><strong>Dupliquer une période</strong></div>
      <div class="card-body">
        <p class="small text-muted">
          Recopie la structure d'une période vers une autre pour éviter une
          ressaisie complète. Les valeurs existantes de la cible sont écrasées.
        </p>
        <form method="post" action="periodes.php"
              onsubmit="return confirm('Confirmer la duplication ? Les données existantes de la période cible seront écrasées.');">
          <?= csrfChamp() ?>
          <input type="hidden" name="action" value="dupliquer">

          <div class="mb-3">
            <label for="source" class="form-label">Période source</label>
            <select class="form-select" id="source" name="source" required>
              <?php foreach ($periodes as $p): ?>
                <option value="<?= (int) $p['id'] ?>"><?= e($p['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="cible" class="form-label">Période cible</label>
            <select class="form-select" id="cible" name="cible" required>
              <?php foreach ($periodes as $p): ?>
                <option value="<?= (int) $p['id'] ?>"
                  <?= $p['statut'] === 'CLOTUREE' ? 'disabled' : '' ?>>
                  <?= e($p['libelle']) ?><?= $p['statut'] === 'CLOTUREE' ? ' (clôturée)' : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <p class="form-label mb-2">Éléments à copier</p>
          <?php foreach ([
              'montants' => 'Montants des ressources',
              'cles'     => 'Clés de répartition',
              'directs'  => 'Coûts directs et volumes',
              'conso'    => 'Consommations d\'inducteurs',
          ] as $cle => $lib): ?>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" value="<?= e($cle) ?>"
                     id="quoi_<?= e($cle) ?>" name="quoi[]"
                     <?= $cle === 'cles' ? 'checked' : '' ?>>
              <label class="form-check-label small" for="quoi_<?= e($cle) ?>">
                <?= e($lib) ?>
              </label>
            </div>
          <?php endforeach; ?>

          <button type="submit" class="btn btn-outline-secondary mt-3">Dupliquer</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ================= LISTE ================= -->
  <div class="col-12 col-xl-8">
    <div class="card">
      <div class="card-header"><strong><?= count($periodes) ?> période(s)</strong></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Code</th>
              <th>Libellé</th>
              <th>Du</th>
              <th>Au</th>
              <th class="text-center">Données saisies</th>
              <th class="text-center">Statut</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($periodes as $p):
              $total = (int) $p['nb_montants'] + (int) $p['nb_cles']
                     + (int) $p['nb_directs'] + (int) $p['nb_conso']; ?>
            <tr>
              <td><span class="badge bg-secondary"><?= e($p['code']) ?></span></td>
              <td class="small"><?= e($p['libelle']) ?></td>
              <td class="small text-muted"><?= e(date('d/m/Y', strtotime($p['date_debut']))) ?></td>
              <td class="small text-muted"><?= e(date('d/m/Y', strtotime($p['date_fin']))) ?></td>
              <td class="text-center small text-muted">
                <?= (int) $p['nb_montants'] ?> montants ·
                <?= (int) $p['nb_cles'] ?> clés ·
                <?= (int) $p['nb_directs'] ?> coûts ·
                <?= (int) $p['nb_conso'] ?> conso.
              </td>
              <td class="text-center">
                <?php if ($p['statut'] === 'OUVERTE'): ?>
                  <span class="badge bg-success">Ouverte</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Clôturée</span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary"
                   href="periodes.php?modifier=<?= (int) $p['id'] ?>">Modifier</a>

                <form method="post" action="periodes.php" class="d-inline"
                      onsubmit="return confirm('<?= $p['statut'] === 'OUVERTE'
                          ? 'Clôturer cette période ? Toute saisie sera bloquée.'
                          : 'Rouvrir cette période à la saisie ?' ?>');">
                  <?= csrfChamp() ?>
                  <input type="hidden" name="action" value="basculer">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <?= $p['statut'] === 'OUVERTE' ? 'Clôturer' : 'Rouvrir' ?>
                  </button>
                </form>

                <form method="post" action="periodes.php" class="d-inline"
                      onsubmit="return confirm('Supprimer définitivement cette période ?');">
                  <?= csrfChamp() ?>
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"
                    <?= $total > 0 ? 'disabled title="Période renseignée"' : '' ?>>
                    Supprimer
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-footer small text-muted">
        La clôture d'une période la met en lecture seule dans tous les écrans de
        saisie. C'est l'équivalent du verrouillage d'un exercice comptable :
        les coûts publiés ne peuvent plus être modifiés a posteriori.
      </div>
    </div>
  </div>
</div>

<?php
afficherPied();
