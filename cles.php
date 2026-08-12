<?php
/**
 * Saisie des clés de répartition Ressources → Activités.
 *
 * Écran central du modèle ABC : la matrice complète est éditable sur une
 * seule page, avec un contrôle de la somme à 100 % côté client (JavaScript,
 * immédiat) ET côté serveur (PHP, bloquant). Aucun enregistrement partiel
 * n'est possible : la sauvegarde s'effectue dans une transaction.
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
$ressources = db()->query(
    'SELECT id, code, libelle, nature FROM ressources WHERE actif = 1 ORDER BY code'
)->fetchAll();

$activites = db()->query(
    'SELECT id, code, libelle FROM activites WHERE actif = 1 ORDER BY code'
)->fetchAll();

// Montant de chaque ressource sur la période
$stmt = db()->prepare(
    'SELECT ressource_id, montant FROM ressource_montants WHERE periode_id = :p'
);
$stmt->execute([':p' => $periodeId]);
$montants = [];
foreach ($stmt->fetchAll() as $m) {
    $montants[(int) $m['ressource_id']] = (float) $m['montant'];
}

// ---------------------------------------------------------------------
// Enregistrement
// ---------------------------------------------------------------------
$erreurs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();
    exigerEcriture();

    if (!periodeModifiable($periode)) {
        $erreurs[] = 'La période ' . $periode['libelle'] . ' est clôturée : aucune modification possible.';
    }

    $saisie = $_POST['cles'] ?? [];
    if (!is_array($saisie)) {
        $saisie = [];
    }

    // --- Validation serveur : bornes puis somme par ressource ---
    $valeurs = [];
    foreach ($ressources as $r) {
        $rid   = (int) $r['id'];
        $somme = 0.0;

        foreach ($activites as $a) {
            $aid   = (int) $a['id'];
            $brut  = str_replace([' ', ','], ['', '.'], (string) ($saisie[$rid][$aid] ?? '0'));
            $valeur = is_numeric($brut) ? (float) $brut : -1.0;

            if ($valeur < 0 || $valeur > 100) {
                $erreurs[] = sprintf(
                    'Ressource %s / activité %s : la valeur doit être comprise entre 0 et 100.',
                    $r['code'], $a['code']
                );
                $valeur = 0.0;
            }

            $valeurs[$rid][$aid] = round($valeur, 4);
            $somme += $valeur;
        }

        if (abs($somme - 100) > TOLERANCE_CLES) {
            $erreurs[] = sprintf(
                'Ressource %s (%s) : la somme des clés vaut %s au lieu de 100 %%.',
                $r['code'], $r['libelle'], nb($somme, 2)
            );
        }
    }

    // --- Écriture transactionnelle ---
    if (!$erreurs) {
        $sql = 'INSERT INTO cles_ressources
                  (ressource_id, activite_id, periode_id, pourcentage)
                VALUES (:r, :a, :p, :pct)
                ON DUPLICATE KEY UPDATE pourcentage = VALUES(pourcentage)';
        try {
            db()->beginTransaction();
            $ins = db()->prepare($sql);

            foreach ($valeurs as $rid => $parActivite) {
                foreach ($parActivite as $aid => $pct) {
                    $ins->execute([
                        ':r'   => $rid,
                        ':a'   => $aid,
                        ':p'   => $periodeId,
                        ':pct' => $pct,
                    ]);
                }
            }
            db()->commit();

            audit('UPDATE', 'cles_ressources', $periode['code'],
                  'Mise à jour de la matrice des clés de répartition');
            flash('success', 'Clés de répartition enregistrées. Les coûts ont été recalculés.');

            header('Location: cles.php?periode=' . $periodeId);
            exit;

        } catch (PDOException $ex) {
            db()->rollBack();
            $erreurs[] = 'Erreur d\'enregistrement : aucune modification appliquée.';
            error_log('Clés KO : ' . $ex->getMessage());
        }
    }
}

// ---------------------------------------------------------------------
// Chargement des clés existantes
// ---------------------------------------------------------------------
$stmt = db()->prepare(
    'SELECT ressource_id, activite_id, pourcentage, justification
       FROM cles_ressources WHERE periode_id = :p'
);
$stmt->execute([':p' => $periodeId]);

$cles = [];
foreach ($stmt->fetchAll() as $c) {
    $cles[(int) $c['ressource_id']][(int) $c['activite_id']] = (float) $c['pourcentage'];
}

// En cas d'erreur, on réaffiche la saisie de l'utilisateur plutôt que la base
if ($erreurs && isset($valeurs)) {
    $cles = $valeurs;
}

$controle = controleCles($periodeId);

afficherEntete('Clés de répartition — Ressources vers activités', $periode);
?>

<div class="alert alert-info small">
  <strong>Règle de gestion :</strong> chaque ressource doit être répartie à
  100 % entre les activités. Le coût d'une activité vaut
  <em>Σ (montant de la ressource × clé %)</em>. Tant qu'une ligne n'atteint pas
  100 %, les coûts de revient sont faux — la sauvegarde est alors refusée.
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

<form method="post" action="cles.php?periode=<?= $periodeId ?>" id="formCles">
  <?= csrfChamp() ?>

  <div class="card mb-3">
    <div class="table-responsive">
      <table class="table table-sm table-bordered table-matrice mb-0" id="tableCles">
        <thead class="table-light">
          <tr>
            <th style="min-width:230px">Ressource</th>
            <th class="text-end" style="min-width:130px">Montant période</th>
            <?php foreach ($activites as $a): ?>
              <th class="text-center" title="<?= e($a['libelle']) ?>">
                <?= e($a['code']) ?>
              </th>
            <?php endforeach; ?>
            <th class="text-center" style="min-width:90px">Total</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($ressources as $r):
            $rid = (int) $r['id']; ?>
          <tr data-ressource="<?= $rid ?>">
            <td>
              <span class="badge bg-secondary"><?= e($r['code']) ?></span>
              <span class="small"><?= e($r['libelle']) ?></span>
            </td>
            <td class="text-end small text-nowrap">
              <?= fcfa($montants[$rid] ?? 0) ?>
            </td>

            <?php foreach ($activites as $a):
                $aid = (int) $a['id'];
                $val = $cles[$rid][$aid] ?? 0; ?>
              <td class="p-1">
                <label class="visually-hidden" for="c<?= $rid ?>_<?= $aid ?>">
                  Clé <?= e($r['code']) ?> / <?= e($a['code']) ?>
                </label>
                <input type="number" class="form-control form-control-sm text-end cle-input"
                       id="c<?= $rid ?>_<?= $aid ?>"
                       name="cles[<?= $rid ?>][<?= $aid ?>]"
                       value="<?= e(rtrim(rtrim(number_format($val, 2, '.', ''), '0'), '.')) ?>"
                       min="0" max="100" step="0.01"
                       <?= $editable ? '' : 'readonly' ?>>
              </td>
            <?php endforeach; ?>

            <td class="text-center total-ligne fw-semibold align-middle"></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($editable): ?>
  <div class="d-flex flex-wrap gap-2 mb-4">
    <button type="submit" class="btn btn-brique" id="btnEnregistrer">
      Enregistrer la matrice
    </button>
    <button type="button" class="btn btn-outline-secondary" id="btnRepartirEgal">
      Répartir également les lignes vides
    </button>
    <a href="cles.php?periode=<?= $periodeId ?>" class="btn btn-outline-secondary">
      Annuler les modifications
    </a>
    <span class="align-self-center small text-muted" id="messageControle"></span>
  </div>
  <?php endif; ?>
</form>

<!-- Rappel du contrôle enregistré en base -->
<div class="card">
  <div class="card-header"><strong>Contrôle de cohérence enregistré</strong></div>
  <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead class="table-light">
        <tr>
          <th>Ressource</th>
          <th class="text-end">Somme des clés</th>
          <th class="text-end">Écart</th>
          <th class="text-center">Statut</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($controle as $c): ?>
        <tr>
          <td>
            <span class="badge bg-secondary"><?= e($c['ressource_code']) ?></span>
            <span class="small"><?= e($c['ressource_libelle']) ?></span>
          </td>
          <td class="text-end"><?= pct((float) $c['total_pourcentage']) ?></td>
          <td class="text-end"><?= pct((float) $c['ecart']) ?></td>
          <td class="text-center">
            <?php if ($c['statut'] === 'CONFORME'): ?>
              <span class="badge bg-success">Conforme</span>
            <?php else: ?>
              <span class="badge bg-danger">Anomalie</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<p class="small text-muted mt-3 mb-0">
  Légende des activités :
  <?php foreach ($activites as $a): ?>
    <span class="me-3"><strong><?= e($a['code']) ?></strong> <?= e($a['libelle']) ?></span>
  <?php endforeach; ?>
</p>

<?php
afficherPied();
