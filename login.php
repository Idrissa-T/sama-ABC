<?php
/**
 * Page de connexion.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

sessionDemarrer();

// Déjà connecté : on redirige vers le tableau de bord
if (estConnecte()) {
    header('Location: index.php');
    exit;
}

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();
    $erreur = connecter(postTexte('login', 50), (string) ($_POST['mot_de_passe'] ?? ''));

    if ($erreur === null) {
        flash('success', 'Bienvenue ' . utilisateurNom() . '.');
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Connexion — <?= e(APP_NOM) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="page-login d-flex align-items-center">

<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-sm-10 col-md-7 col-lg-5">

      <div class="card shadow-lg border-0">
        <div class="card-body p-4 p-sm-5">

          <div class="text-center mb-4">
            <span class="logo-brique logo-lg"></span>
            <h1 class="h4 mt-3 mb-1">A &amp; P BRIQUES</h1>
            <p class="text-muted small mb-0">
              Comptabilité par activités — Activity Based Costing
            </p>
          </div>

          <?= flashAfficher() ?>

          <?php if ($erreur !== null): ?>
            <div class="alert alert-danger" role="alert"><?= e($erreur) ?></div>
          <?php endif; ?>

          <form method="post" action="login.php" id="formLogin" novalidate>
            <?= csrfChamp() ?>

            <div class="mb-3">
              <label for="login" class="form-label">Identifiant</label>
              <input type="text" class="form-control" id="login" name="login"
                     value="<?= e($_POST['login'] ?? '') ?>"
                     required maxlength="50" autocomplete="username" autofocus>
              <div class="invalid-feedback">Votre identifiant est obligatoire.</div>
            </div>

            <div class="mb-4">
              <label for="mot_de_passe" class="form-label">Mot de passe</label>
              <div class="input-group">
                <input type="password" class="form-control" id="mot_de_passe"
                       name="mot_de_passe" required autocomplete="current-password">
                <button class="btn btn-outline-secondary" type="button"
                        id="btnVoirMdp" aria-label="Afficher le mot de passe">Voir</button>
              </div>
              <div class="invalid-feedback">Votre mot de passe est obligatoire.</div>
            </div>

            <button type="submit" class="btn btn-brique w-100 py-2">Se connecter</button>
          </form>

          <hr class="my-4">
          <p class="small text-muted mb-1 fw-semibold">Comptes de démonstration</p>
          <ul class="small text-muted mb-0 ps-3">
            <li><code>admin</code> / <code>Admin@2026</code> — administrateur</li>
            <li><code>controleur</code> / <code>Controle@2026</code> — contrôleur de gestion</li>
            <li><code>lecteur</code> / <code>Lecture@2026</code> — consultation</li>
          </ul>

        </div>
      </div>

      <p class="text-center text-muted small mt-3">
        Master CCA — École Supérieure Polytechnique de Dakar
      </p>

    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Validation côté client
document.getElementById('formLogin').addEventListener('submit', function (ev) {
  if (!this.checkValidity()) {
    ev.preventDefault();
    ev.stopPropagation();
  }
  this.classList.add('was-validated');
});

// Affichage du mot de passe
document.getElementById('btnVoirMdp').addEventListener('click', function () {
  const champ = document.getElementById('mot_de_passe');
  const visible = champ.type === 'text';
  champ.type = visible ? 'password' : 'text';
  this.textContent = visible ? 'Voir' : 'Cacher';
});
</script>
</body>
</html>
