<?php
/**
 * Saisie des consommations d'inducteurs : matrice objets de coût × activités.
 *
 * Étape 3 du modèle ABC. Chaque cellule indique combien d'unités d'œuvre
 * l'objet de coût a consommé sur l'activité. Le total d'une colonne constitue
 * le volume de l'inducteur, dénominateur du coût unitaire.
 *
 * Contrairement aux clés de répartition, il n'y a pas de contrainte de somme :
 * les volumes sont des grandeurs physiques. En revanche, une colonne à zéro
 * rend le coût unitaire incalculable et laisse les charges de l'activité
 * non imputées — la page le signale explicitement.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];
$editable  = periodeModifiable($periode) && peutEcrire();

// ---------------------------------------------------------------------
// Référentiels
// ---------------------------------------------------------------------
$objets = db()->query(
    'SELECT id, code, libelle, type_objet FROM objets_cout
      WHERE actif = 1 ORDER BY type_objet, code'
)->fetchAll();

$inducteurs = db()->query(
    'SELECT i.id, i.libelle, i.unite_oeuvre, a.code AS activite_code,
            a.libelle AS activite_libelle
       FROM inducteurs i
       JOIN activites a ON a.id = i.activite_id
      WHERE a.actif = 1
      ORDER BY a.code'
)->fetchAll();

// Coût de chaque activité, pour afficher le coût unitaire résultant
$coutParActivite = [];
foreach (coutsActivites($periodeId) as $a) {
    $coutParActivite[$a['activite_code']] = (float) $a['cout_activite'];
}

$erreurs = [];

// ---------------------------------------------------------------------
// Enregistrement
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();
    exigerEcriture();

    if (!periodeModifiable($periode)) {
        $erreurs[] = 'La période ' . $periode['libelle'] . ' est clôturée : aucune modification possible.';
    }

    $saisie  = is_array($_POST['conso'] ?? null) ? $_POST['conso'] : [];
    $valeurs = [];

    foreach ($objets as $o) {
        $oid = (int) $o['id'];
        foreach ($inducteurs as $i) {
            $iid  = (int) $i['id'];
            $brut = str_replace([' ', ','], ['', '.'], (string) ($saisie[$oid][$iid] ?? '0'));

            if ($brut === '') {
                $brut = '0';
            }
            if (!is_numeric($brut)) {
                $erreurs[] = sprintf(
                    'Objet %s / activité %s : valeur non numérique.',
                    $o['code'], $i['activite_code']
                );
                $brut = '0';
            }
            $valeur = (float) $brut;

            if ($valeur < 0) {
                $erreurs[] = sprintf(
                    'Objet %s / activité %s : la quantité ne peut pas être négative.',
                    $o['code'], $i['activite_code']
                );
                $valeur = 0.0;
            }
            $valeurs[$oid][$iid] = round($valeur, 4);
        }
    }

    if (!$erreurs) {
        $sql = 'INSERT INTO consommations (inducteur_id, objet_cout_id, periode_id, quantite)
                VALUES (:i, :o, :p, :q)
                ON DUPLICATE KEY UPDATE quantite = VALUES(quantite)';
        try {
            db()->beginTransaction();
            $ins = db()->prepare($sql);

            foreach ($valeurs as $oid => $parInducteur) {
                foreach ($parInducteur as $iid => $qte) {
                    $ins->execute([':i' => $iid, ':o' => $oid, ':p' => $periodeId, ':q' => $qte]);
                }
            }
            db()->commit();

            audit('UPDATE', 'consommations', $periode['code'],
                  'Mise à jour de la matrice des consommations d\'inducteurs');
            flash('success', 'Consommations enregistrées. Les coûts de revient ont été recalculés.');

            header('Location: consommations.php?periode=' . $periodeId);
            exit;

        } catch (PDOException $ex) {
            db()->rollBack();
            $erreurs[] = 'Erreur d\'enregistrement : aucune modification appliquée.';
            error_log('Consommations KO : ' . $ex->getMessage());
        }
    }
}

// ---------------------------------------------------------------------
// Chargement des consommations existantes
// ---------------------------------------------------------------------
$st = db()->prepare(
    'SELECT inducteur_id, objet_cout_id, quantite FROM consommations WHERE periode_id = :p'
);
$st->execute([':p' => $periodeId]);

$conso = [];
foreach ($st->fetchAll() as $c) {
    $conso[(int) $c['objet_cout_id']][(int) $c['inducteur_id']] = (float) $c['quantite'];
}

if ($erreurs && isset($valeurs)) {
    $conso = $valeurs;
}

// Volumes par inducteur (pour l'affichage des totaux serveur)
$volumes = [];
foreach ($inducteurs as $i) {
    $iid = (int) $i['id'];
    $volumes[$iid] = 0.0;
    foreach ($objets as $o) {
        $volumes[$iid] += $conso[(int) $o['id']][$iid] ?? 0.0;
    }
}

$bouclage = controleBouclage($periodeId);

afficherEntete('Consommations d\'inducteurs', $periode);
?>

<div class="alert alert-info small">
  <strong>Règle de gestion :</strong> chaque cellule exprime la quantité d'unités
  d'œuvre consommée par l'objet de coût sur l'activité. Le total de la colonne
  forme le volume de l'inducteur, et
  <em>coût unitaire = coût de l'activité ÷ volume total</em>.
  Une colonne vide laisse les charges de l'activité non imputées.
</div>

<?php if ($erreurs): ?>
  <div class="alert alert-danger">
    <p class="fw-semibold mb-2">Enregistrement refusé :</p>
    <ul class="mb-0 small">
      <?php foreach (array_slice($erreurs, 0, 10) as $err): ?>
        <li><?= e($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($bouclage !== null && abs((float) $bouclage['ecart_imputation']) > 0.01): ?>
  <div class="alert alert-warning small mb-3">
    <strong>Écart d'imputation actuel :</strong>
    <?= fcfa((float) $bouclage['ecart_imputation']) ?>.
    Une ou plusieurs activités n'ont aucune consommation saisie.
  </div>
<?php endif; ?>

<?php if (!$editable): ?>
  <div class="alert alert-secondary">
    <?php if (!periodeModifiable($periode)): ?>
      Période clôturée : consultation seule.
    <?php else: ?>
      Votre profil <strong><?= e(utilisateurRole()) ?></strong> ne permet pas la saisie.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$objets || !$inducteurs): ?>
  <div class="alert alert-warning">
    Il faut au moins un objet de coût actif et une activité dotée d'un inducteur.
    <a href="objets.php?periode=<?= $periodeId ?>" class="alert-link">Objets de coût</a> ·
    <a href="activites.php?periode=<?= $periodeId ?>" class="alert-link">Activités</a>
  </div>
<?php else: ?>

<form method="post" action="consommations.php?periode=<?= $periodeId ?>" id="formConso">
  <?= csrfChamp() ?>

  <div class="card mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-bordered table-matrice mb-0" id="tableConso">
        <thead class="table-light">
          <tr>
            <th style="min-width:230px">Objet de coût</th>
            <?php foreach ($inducteurs as $i): ?>
              <th class="text-center"
                  title="<?= e($i['activite_libelle']) ?> — <?= e($i['libelle']) ?>">
                <?= e($i['activite_code']) ?><br>
                <span class="fw-normal text-muted" style="font-size:.72rem">
                  <?= e($i['unite_oeuvre']) ?>
                </span>
              </th>
            <?php endforeach; ?>
          </tr>
        </thead>

        <tbody>
        <?php foreach ($objets as $o):
            $oid = (int) $o['id']; ?>
          <tr>
            <td>
              <span class="badge bg-secondary"><?= e($o['code']) ?></span>
              <span class="small"><?= e($o['libelle']) ?></span>
            </td>
            <?php foreach ($inducteurs as $i):
                $iid = (int) $i['id'];
                $val = $conso[$oid][$iid] ?? 0; ?>
              <td class="p-1">
                <label class="visually-hidden" for="q<?= $oid ?>_<?= $iid ?>">
                  <?= e($o['code']) ?> / <?= e($i['activite_code']) ?>
                </label>
                <input type="number" class="form-control form-control-sm text-end conso-input"
                       id="q<?= $oid ?>_<?= $iid ?>"
                       name="conso[<?= $oid ?>][<?= $iid ?>]"
                       data-inducteur="<?= $iid ?>"
                       value="<?= e(rtrim(rtrim(number_format($val, 4, '.', ''), '0'), '.')) ?>"
                       min="0" step="any" <?= $editable ? '' : 'readonly' ?>>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>

        <tfoot class="table-light">
          <tr>
            <th>Volume total de l'inducteur</th>
            <?php foreach ($inducteurs as $i): ?>
              <th class="text-end volume-total fw-semibold"
                  data-inducteur="<?= (int) $i['id'] ?>"></th>
            <?php endforeach; ?>
          </tr>
          <tr>
            <th class="fw-normal small text-muted">Coût de l'activité</th>
            <?php foreach ($inducteurs as $i): ?>
              <td class="text-end small text-muted"
                  data-cout="<?= e(number_format($coutParActivite[$i['activite_code']] ?? 0, 2, '.', '')) ?>">
                <?= nb($coutParActivite[$i['activite_code']] ?? 0) ?>
              </td>
            <?php endforeach; ?>
          </tr>
          <tr>
            <th class="fw-normal small">Coût unitaire résultant</th>
            <?php foreach ($inducteurs as $i): ?>
              <td class="text-end small cout-unitaire"
                  data-inducteur="<?= (int) $i['id'] ?>"
                  data-cout="<?= e(number_format($coutParActivite[$i['activite_code']] ?? 0, 2, '.', '')) ?>"></td>
            <?php endforeach; ?>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?php if ($editable): ?>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <button type="submit" class="btn btn-brique">Enregistrer la matrice</button>
    <a href="consommations.php?periode=<?= $periodeId ?>" class="btn btn-outline-secondary">
      Annuler les modifications
    </a>
    <span class="align-self-center small text-muted" id="messageConso"></span>
  </div>
  <?php endif; ?>
</form>

<p class="small text-muted">
  Légende des activités :
  <?php foreach ($inducteurs as $i): ?>
    <span class="me-3"><strong><?= e($i['activite_code']) ?></strong>
    <?= e($i['activite_libelle']) ?></span>
  <?php endforeach; ?>
</p>

<script>
(function () {
  'use strict';
  var table = document.getElementById('tableConso');
  if (!table) { return; }

  var fmt = function (v, d) {
    return v.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: d });
  };

  function recalculer() {
    var vides = 0;

    table.querySelectorAll('.volume-total').forEach(function (cel) {
      var id = cel.dataset.inducteur;
      var total = 0;

      table.querySelectorAll('.conso-input[data-inducteur="' + id + '"]').forEach(function (ch) {
        var v = parseFloat(String(ch.value).replace(',', '.'));
        if (!isNaN(v)) { total += v; }
      });

      cel.textContent = fmt(total, 2);
      cel.classList.toggle('text-danger', total <= 0);
      if (total <= 0) { vides += 1; }

      var cu = table.querySelector('.cout-unitaire[data-inducteur="' + id + '"]');
      if (cu) {
        var cout = parseFloat(cu.dataset.cout) || 0;
        if (total > 0) {
          cu.textContent = fmt(cout / total, 2);
          cu.classList.remove('text-danger');
          cu.classList.add('fw-semibold');
        } else {
          cu.textContent = 'non calculable';
          cu.classList.add('text-danger');
          cu.classList.remove('fw-semibold');
        }
      }
    });

    var msg = document.getElementById('messageConso');
    if (msg) {
      if (vides === 0) {
        msg.textContent = 'Toutes les activités ont un volume : les charges seront intégralement imputées.';
        msg.className = 'align-self-center small text-success';
      } else {
        msg.textContent = vides + ' activité(s) sans volume : leurs charges resteront non imputées.';
        msg.className = 'align-self-center small text-danger';
      }
    }
  }

  table.addEventListener('input', function (ev) {
    if (ev.target.classList.contains('conso-input')) { recalculer(); }
  });
  table.addEventListener('focusin', function (ev) {
    if (ev.target.classList.contains('conso-input')) { ev.target.select(); }
  });

  recalculer();
})();
</script>

<?php endif; ?>

<?php
afficherPied();
