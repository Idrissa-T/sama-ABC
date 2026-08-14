<?php
/**
 * Saisie des coûts directs, des volumes produits et des prix de vente.
 *
 * Ces données servent deux fois :
 *   - les coûts directs s'ajoutent aux charges indirectes imputées pour
 *     former le coût de revient complet ;
 *   - le coût de main-d'œuvre directe constitue la clé unique de la méthode
 *     classique, ce qui rend la comparaison avec l'ABC possible.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/abc.php';

exigerConnexion();

$periode   = periodeActive();
$periodeId = (int) $periode['id'];
$editable  = periodeModifiable($periode) && peutEcrire();

$objets = db()->query(
    'SELECT id, code, libelle, unite FROM objets_cout
      WHERE actif = 1 ORDER BY type_objet, code'
)->fetchAll();

$erreurs = [];

// ---------------------------------------------------------------------
// Enregistrement
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();
    exigerEcriture();

    if (!periodeModifiable($periode)) {
        $erreurs[] = 'La période ' . $periode['libelle'] . ' est clôturée.';
    }

    $saisie  = is_array($_POST['cd'] ?? null) ? $_POST['cd'] : [];
    $valeurs = [];

    foreach ($objets as $o) {
        $oid    = (int) $o['id'];
        $ligne  = is_array($saisie[$oid] ?? null) ? $saisie[$oid] : [];
        $champs = [];

        foreach (['quantite', 'prix', 'matieres', 'mod'] as $champ) {
            $brut = str_replace([' ', ','], ['', '.'], (string) ($ligne[$champ] ?? '0'));
            if ($brut === '') {
                $brut = '0';
            }
            if (!is_numeric($brut)) {
                $erreurs[] = sprintf('Objet %s : « %s » n\'est pas un nombre valide.',
                    $o['code'], $champ);
                $brut = '0';
            }
            $valeur = (float) $brut;
            if ($valeur < 0) {
                $erreurs[] = sprintf('Objet %s : « %s » ne peut pas être négatif.',
                    $o['code'], $champ);
                $valeur = 0.0;
            }
            $champs[$champ] = $valeur;
        }

        // Cohérence économique : alerte, pas blocage
        if ($champs['quantite'] > 0 && $champs['prix'] > 0) {
            $ca      = $champs['quantite'] * $champs['prix'];
            $directs = $champs['matieres'] + $champs['mod'];
            if ($directs > $ca) {
                $erreurs[] = sprintf(
                    'Objet %s : les coûts directs (%s) dépassent le chiffre d\'affaires (%s). '
                    . 'Vérifiez les montants avant d\'enregistrer.',
                    $o['code'], fcfa($directs), fcfa($ca)
                );
            }
        }

        $valeurs[$oid] = $champs;
    }

    if (!$erreurs) {
        $sql = 'INSERT INTO couts_directs
                  (objet_cout_id, periode_id, quantite_produite,
                   prix_vente_unitaire, cout_matieres, cout_mod)
                VALUES (:o, :p, :q, :pv, :ma, :mo)
                ON DUPLICATE KEY UPDATE
                  quantite_produite   = VALUES(quantite_produite),
                  prix_vente_unitaire = VALUES(prix_vente_unitaire),
                  cout_matieres       = VALUES(cout_matieres),
                  cout_mod            = VALUES(cout_mod)';
        try {
            db()->beginTransaction();
            $ins = db()->prepare($sql);

            foreach ($valeurs as $oid => $c) {
                $ins->execute([
                    ':o'  => $oid, ':p' => $periodeId,
                    ':q'  => $c['quantite'], ':pv' => $c['prix'],
                    ':ma' => $c['matieres'], ':mo' => $c['mod'],
                ]);
            }
            db()->commit();

            audit('UPDATE', 'couts_directs', $periode['code'],
                  'Mise à jour des coûts directs et volumes');
            flash('success', 'Coûts directs enregistrés. Les coûts de revient ont été recalculés.');

            header('Location: couts_directs.php?periode=' . $periodeId);
            exit;

        } catch (PDOException $ex) {
            db()->rollBack();
            $erreurs[] = 'Erreur d\'enregistrement : aucune modification appliquée.';
            error_log('Couts directs KO : ' . $ex->getMessage());
        }
    }
}

// ---------------------------------------------------------------------
// Chargement
// ---------------------------------------------------------------------
$st = db()->prepare(
    'SELECT objet_cout_id, quantite_produite, prix_vente_unitaire,
            cout_matieres, cout_mod
       FROM couts_directs WHERE periode_id = :p'
);
$st->execute([':p' => $periodeId]);

$donnees = [];
foreach ($st->fetchAll() as $c) {
    $donnees[(int) $c['objet_cout_id']] = [
        'quantite' => (float) $c['quantite_produite'],
        'prix'     => (float) $c['prix_vente_unitaire'],
        'matieres' => (float) $c['cout_matieres'],
        'mod'      => (float) $c['cout_mod'],
    ];
}

if ($erreurs && isset($valeurs)) {
    $donnees = $valeurs;
}

$totMod = array_sum(array_column($donnees, 'mod'));
$totInd = 0.0;
foreach (coutsActivites($periodeId) as $a) {
    $totInd += (float) $a['cout_activite'];
}

afficherEntete('Coûts directs, volumes et prix de vente', $periode);
?>

<div class="alert alert-info small">
  <strong>Rôle de la main-d'œuvre directe :</strong> au-delà de son entrée dans
  le coût de revient, elle sert de clé unique à la méthode classique.
  Taux d'imputation classique =
  charges indirectes ÷ total MOD =
  <strong><?= $totMod > 0 ? nb($totInd / $totMod, 4) : '—' ?></strong>
  pour la période.
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

<?php if (!$editable): ?>
  <div class="alert alert-secondary">
    <?php if (!periodeModifiable($periode)): ?>
      Période clôturée : consultation seule.
    <?php else: ?>
      Votre profil <strong><?= e(utilisateurRole()) ?></strong> ne permet pas la saisie.
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$objets): ?>
  <div class="alert alert-warning">
    Aucun objet de coût actif.
    <a href="objets.php?periode=<?= $periodeId ?>" class="alert-link">Créer un objet de coût</a>.
  </div>
<?php else: ?>

<form method="post" action="couts_directs.php?periode=<?= $periodeId ?>" id="formCd">
  <?= csrfChamp() ?>

  <div class="card mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-bordered align-middle mb-0" id="tableCd">
        <thead class="table-light">
          <tr>
            <th style="min-width:200px">Objet de coût</th>
            <th class="text-center" style="min-width:110px">Quantité<br>produite</th>
            <th class="text-center" style="min-width:110px">Prix de vente<br>unitaire</th>
            <th class="text-center" style="min-width:130px">Coût des<br>matières</th>
            <th class="text-center" style="min-width:130px">Main-d'œuvre<br>directe</th>
            <th class="text-end" style="min-width:120px">Chiffre<br>d'affaires</th>
            <th class="text-end" style="min-width:120px">Coûts directs</th>
            <th class="text-end" style="min-width:110px">Marge sur<br>coûts directs</th>
          </tr>
        </thead>

        <tbody>
        <?php foreach ($objets as $o):
            $oid = (int) $o['id'];
            $d   = $donnees[$oid] ?? ['quantite' => 0, 'prix' => 0, 'matieres' => 0, 'mod' => 0];
            $fmt = static fn(float $v, int $d2) =>
                rtrim(rtrim(number_format($v, $d2, '.', ''), '0'), '.') ?: '0'; ?>
          <tr data-objet="<?= $oid ?>">
            <td>
              <span class="badge bg-secondary"><?= e($o['code']) ?></span>
              <span class="small"><?= e($o['libelle']) ?></span>
              <?php if ($o['unite'] !== null): ?>
                <br><span class="small text-muted">en <?= e($o['unite']) ?></span>
              <?php endif; ?>
            </td>

            <?php foreach ([
                'quantite' => ['Quantité', 4],
                'prix'     => ['Prix de vente', 2],
                'matieres' => ['Coût matières', 2],
                'mod'      => ['Main-d\'œuvre directe', 2],
            ] as $champ => [$libelle, $dec]): ?>
              <td class="p-1">
                <label class="visually-hidden" for="<?= $champ ?>_<?= $oid ?>">
                  <?= e($libelle) ?> de <?= e($o['code']) ?>
                </label>
                <input type="number" class="form-control form-control-sm text-end cd-input"
                       id="<?= $champ ?>_<?= $oid ?>"
                       name="cd[<?= $oid ?>][<?= $champ ?>]"
                       data-champ="<?= $champ ?>"
                       value="<?= e($fmt((float) $d[$champ], $dec)) ?>"
                       min="0" step="any" <?= $editable ? '' : 'readonly' ?>>
              </td>
            <?php endforeach; ?>

            <td class="text-end ca-ligne"></td>
            <td class="text-end directs-ligne"></td>
            <td class="text-end marge-ligne fw-semibold"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>

        <tfoot class="table-light fw-semibold">
          <tr>
            <th>Totaux</th>
            <th class="text-end" id="totQuantite"></th>
            <th></th>
            <th class="text-end" id="totMatieres"></th>
            <th class="text-end" id="totMod"></th>
            <th class="text-end" id="totCa"></th>
            <th class="text-end" id="totDirects"></th>
            <th class="text-end" id="totMarge"></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>

  <?php if ($editable): ?>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <button type="submit" class="btn btn-brique">Enregistrer</button>
    <a href="couts_directs.php?periode=<?= $periodeId ?>" class="btn btn-outline-secondary">
      Annuler les modifications
    </a>
    <span class="align-self-center small text-muted" id="messageCd"></span>
  </div>
  <?php endif; ?>
</form>

<div class="card">
  <div class="card-header"><strong>Incidence sur la méthode classique</strong></div>
  <div class="card-body small">
    <p class="mb-2">
      Charges indirectes de la période : <strong><?= fcfa($totInd) ?></strong>.
      Total de la main-d'œuvre directe saisie :
      <strong id="recapMod"><?= fcfa($totMod) ?></strong>.
    </p>
    <p class="mb-0 text-muted">
      Un objet à main-d'œuvre directe nulle ne recevrait aucune charge indirecte
      en méthode classique, alors que l'ABC lui en imputerait selon les activités
      qu'il consomme réellement. C'est la limite majeure de la clé unique.
    </p>
  </div>
</div>

<script>
(function () {
  'use strict';
  var table = document.getElementById('tableCd');
  if (!table) { return; }

  var fcfa = function (v) {
    return v.toLocaleString('fr-FR', { maximumFractionDigits: 0 }) + ' FCFA';
  };
  var val = function (ligne, champ) {
    var ch = ligne.querySelector('.cd-input[data-champ="' + champ + '"]');
    var v = ch ? parseFloat(String(ch.value).replace(',', '.')) : 0;
    return isNaN(v) ? 0 : v;
  };

  function recalculer() {
    var tQ = 0, tMa = 0, tMo = 0, tCa = 0, tDir = 0, alertes = 0;

    table.querySelectorAll('tbody tr').forEach(function (ligne) {
      var q  = val(ligne, 'quantite');
      var pv = val(ligne, 'prix');
      var ma = val(ligne, 'matieres');
      var mo = val(ligne, 'mod');

      var ca      = q * pv;
      var directs = ma + mo;
      var marge   = ca - directs;

      ligne.querySelector('.ca-ligne').textContent      = fcfa(ca);
      ligne.querySelector('.directs-ligne').textContent = fcfa(directs);

      var cel = ligne.querySelector('.marge-ligne');
      cel.textContent = fcfa(marge);
      cel.classList.toggle('text-danger', marge < 0);
      cel.classList.toggle('text-success', marge > 0);
      if (marge < 0) { alertes += 1; }

      tQ += q; tMa += ma; tMo += mo; tCa += ca; tDir += directs;
    });

    document.getElementById('totQuantite').textContent = tQ.toLocaleString('fr-FR');
    document.getElementById('totMatieres').textContent = fcfa(tMa);
    document.getElementById('totMod').textContent      = fcfa(tMo);
    document.getElementById('totCa').textContent       = fcfa(tCa);
    document.getElementById('totDirects').textContent  = fcfa(tDir);
    document.getElementById('totMarge').textContent    = fcfa(tCa - tDir);
    document.getElementById('recapMod').textContent    = fcfa(tMo);

    var msg = document.getElementById('messageCd');
    if (msg) {
      if (tMo <= 0) {
        msg.textContent = 'Main-d\'œuvre directe totale nulle : la méthode classique ne pourra rien imputer.';
        msg.className = 'align-self-center small text-danger';
      } else if (alertes > 0) {
        msg.textContent = alertes + ' objet(s) dont les coûts directs dépassent déjà le chiffre d\'affaires.';
        msg.className = 'align-self-center small text-danger';
      } else {
        msg.textContent = 'Saisie cohérente.';
        msg.className = 'align-self-center small text-success';
      }
    }
  }

  table.addEventListener('input', function (ev) {
    if (ev.target.classList.contains('cd-input')) { recalculer(); }
  });
  table.addEventListener('focusin', function (ev) {
    if (ev.target.classList.contains('cd-input')) { ev.target.select(); }
  });

  recalculer();
})();
</script>

<?php endif; ?>

<?php
afficherPied();
