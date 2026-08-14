<?php
/**
 * Profil de l'utilisateur connecté : informations et changement de mot de passe.
 *
 * Le changement exige le mot de passe actuel, afin qu'une session laissée
 * ouverte ne permette pas à un tiers de s'approprier le compte.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

exigerConnexion();

$periode = periodeActive();
$moi     = (int) ($_SESSION['utilisateur_id'] ?? 0);

$erreurs = [];

// ---------------------------------------------------------------------
// Changement de mot de passe
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();

    if (postTexte('action', 20) === 'mot_de_passe') {
        $actuel   = (string) ($_POST['actuel'] ?? '');
        $nouveau  = (string) ($_POST['nouveau'] ?? '');
        $nouveau2 = (string) ($_POST['nouveau2'] ?? '');

        $st = db()->prepare('SELECT mot_de_passe FROM utilisateurs WHERE id = :i');
        $st->execute([':i' => $moi]);
        $hache = (string) ($st->fetchColumn() ?: '');

        if ($hache === '' || !password_verify($actuel, $hache)) {
            $erreurs[] = 'Le mot de passe actuel est incorrect.';
            audit('MDP_ECHEC', 'utilisateurs', (string) $moi,
                  'Tentative de changement de mot de passe avec un mot de passe actuel erroné');
        }
        if (mb_strlen($nouveau) < 8) {
            $erreurs[] = 'Le nouveau mot de passe doit comporter au moins 8 caractères.';
        }
        if ($nouveau !== $nouveau2) {
            $erreurs[] = 'Les deux saisies du nouveau mot de passe diffèrent.';
        }
        if ($nouveau !== '' && $nouveau === $actuel) {
            $erreurs[] = 'Le nouveau mot de passe doit être différent de l\'ancien.';
        }

        if (!$erreurs) {
            db()->prepare('UPDATE utilisateurs SET mot_de_passe = :m WHERE id = :i')
                ->execute([':m' => password_hash($nouveau, PASSWORD_DEFAULT), ':i' => $moi]);

            audit('MDP_CHANGE', 'utilisateurs', (string) $moi, 'Mot de passe modifié par l\'utilisateur');
            flash('success', 'Mot de passe modifié.');
            header('Location: profil.php');
            exit;
        }
    }
}

// ---------------------------------------------------------------------
// Informations du compte
// ---------------------------------------------------------------------
$st = db()->prepare(
    'SELECT login, nom_complet, email, role, actif, derniere_connexion, date_creation
       FROM utilisateurs WHERE id = :i'
);
$st->execute([':i' => $moi]);
$compte = $st->fetch();

// Dernières actions de l'utilisateur
$st = db()->prepare(
    'SELECT action, table_cible, details, date_action
       FROM journal_audit WHERE utilisateur_id = :i
      ORDER BY date_action DESC LIMIT 10'
);
$st->execute([':i' => $moi]);
$actions = $st->fetchAll();

$droits = [
    'ADMIN' => [
        'Consulter tous les états et exports',
        'Saisir et modifier les données de gestion',
        'Gérer les périodes, les utilisateurs et le journal d\'audit',
    ],
    'CONTROLEUR' => [
        'Consulter tous les états et exports',
        'Saisir et modifier les données de gestion des périodes ouvertes',
    ],
    'LECTEUR' => [
        'Consulter tous les états',
        'Produire les exports PDF et CSV',
    ],
];

afficherEntete('Mon profil', $periode);
?>

<?php if ($erreurs): ?>
  <div class="alert alert-danger">
    <ul class="mb-0 small">
      <?php foreach ($erreurs as $err): ?><li><?= e($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="row g-3">
  <!-- ================= INFORMATIONS ================= -->
  <div class="col-12 col-lg-5">
    <div class="card mb-3">
      <div class="card-header"><strong>Informations du compte</strong></div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr>
            <th class="fw-normal text-muted">Identifiant</th>
            <td><code><?= e($compte['login'] ?? '') ?></code></td>
          </tr>
          <tr>
            <th class="fw-normal text-muted">Nom complet</th>
            <td><?= e($compte['nom_complet'] ?? '') ?></td>
          </tr>
          <tr>
            <th class="fw-normal text-muted">Adresse électronique</th>
            <td><?= e($compte['email'] ?? '') ?></td>
          </tr>
          <tr>
            <th class="fw-normal text-muted">Rôle</th>
            <td>
              <span class="badge <?= ($compte['role'] ?? '') === 'ADMIN' ? 'bg-danger'
                  : (($compte['role'] ?? '') === 'CONTROLEUR' ? 'bg-primary' : 'bg-secondary') ?>">
                <?= e($compte['role'] ?? '') ?>
              </span>
            </td>
          </tr>
          <tr>
            <th class="fw-normal text-muted">Compte créé le</th>
            <td class="small">
              <?= $compte && $compte['date_creation']
                  ? e(date('d/m/Y à H:i', strtotime($compte['date_creation']))) : '—' ?>
            </td>
          </tr>
          <tr>
            <th class="fw-normal text-muted">Dernière connexion</th>
            <td class="small">
              <?= $compte && $compte['derniere_connexion']
                  ? e(date('d/m/Y à H:i', strtotime($compte['derniere_connexion']))) : '—' ?>
            </td>
          </tr>
        </table>
      </div>
      <div class="card-footer small text-muted">
        Pour modifier votre identifiant, votre nom ou votre rôle, adressez-vous
        à un administrateur.
      </div>
    </div>

    <div class="card">
      <div class="card-header"><strong>Droits associés à votre rôle</strong></div>
      <div class="card-body">
        <ul class="mb-0 small">
          <?php foreach ($droits[$compte['role'] ?? 'LECTEUR'] ?? [] as $d): ?>
            <li class="mb-1"><?= e($d) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    </div>
  </div>

  <!-- ================= MOT DE PASSE ================= -->
  <div class="col-12 col-lg-7">
    <div class="card mb-3">
      <div class="card-header"><strong>Changer mon mot de passe</strong></div>
      <div class="card-body">
        <form method="post" action="profil.php" id="formMdp" novalidate>
          <?= csrfChamp() ?>
          <input type="hidden" name="action" value="mot_de_passe">

          <div class="mb-3">
            <label for="actuel" class="form-label">
              Mot de passe actuel <span class="text-danger">*</span>
            </label>
            <input type="password" class="form-control" id="actuel" name="actuel"
                   required autocomplete="current-password">
          </div>

          <div class="mb-3">
            <label for="nouveau" class="form-label">
              Nouveau mot de passe <span class="text-danger">*</span>
            </label>
            <input type="password" class="form-control" id="nouveau" name="nouveau"
                   required minlength="8" autocomplete="new-password">
            <div class="form-text">Au moins 8 caractères.</div>
          </div>

          <div class="mb-3">
            <label for="nouveau2" class="form-label">
              Confirmation <span class="text-danger">*</span>
            </label>
            <input type="password" class="form-control" id="nouveau2" name="nouveau2"
                   required minlength="8" autocomplete="new-password">
          </div>

          <button type="submit" class="btn btn-brique">Modifier mon mot de passe</button>
        </form>
      </div>
      <div class="card-footer small text-muted">
        Le mot de passe actuel est exigé : une session laissée ouverte ne suffit
        pas à s'approprier le compte.
      </div>
    </div>

    <div class="card">
      <div class="card-header"><strong>Mes dernières actions</strong></div>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <thead class="table-light">
            <tr>
              <th>Date</th>
              <th>Action</th>
              <th>Détail</th>
            </tr>
          </thead>
          <tbody>
          <?php if (!$actions): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">
              Aucune action enregistrée.
            </td></tr>
          <?php endif; ?>
          <?php foreach ($actions as $a): ?>
            <tr>
              <td class="small text-nowrap">
                <?= e(date('d/m H:i', strtotime($a['date_action']))) ?>
              </td>
              <td class="small"><span class="badge bg-light text-dark border">
                <?= e($a['action']) ?>
              </span></td>
              <td class="small text-muted"><?= e($a['details'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('formMdp')?.addEventListener('submit', function (ev) {
  var n1 = document.getElementById('nouveau');
  var n2 = document.getElementById('nouveau2');

  n2.setCustomValidity(n1.value !== n2.value ? 'Les mots de passe ne correspondent pas.' : '');

  if (!this.checkValidity()) {
    ev.preventDefault();
    ev.stopPropagation();
  }
  this.classList.add('was-validated');
});
</script>

<?php
afficherPied();
