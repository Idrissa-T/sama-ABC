<?php
/**
 * Gabarit de mise en page (Bootstrap 5, responsive).
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Rend l'en-tête et la barre de navigation.
 *
 * @param string     $titre         Titre de la page
 * @param array|null $periodeActive Période courante (pour le sélecteur)
 */
function afficherEntete(string $titre, ?array $periodeActive = null): void
{
    $role = utilisateurRole();
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titre) ?> — <?= e(APP_NOM) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="bg-body-tertiary">

<nav class="navbar navbar-expand-lg navbar-dark bg-brique shadow-sm">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="index.php">
      <span class="logo-brique"></span> A &amp; P Briques
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
            data-bs-target="#navPrincipal" aria-controls="navPrincipal"
            aria-expanded="false" aria-label="Afficher le menu">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="navPrincipal">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link" href="index.php">Tableau de bord</a>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button">
            Référentiels
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="ressources.php">Ressources</a></li>
            <li><a class="dropdown-item" href="activites.php">Activités et inducteurs</a></li>
            <li><a class="dropdown-item" href="objets.php">Objets de coût</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button">
            Saisie
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="cles.php">Clés de répartition</a></li>
            <li><a class="dropdown-item" href="consommations.php">Consommation d'inducteurs</a></li>
            <li><a class="dropdown-item" href="couts_directs.php">Coûts directs et volumes</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button">
            Résultats
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="resultats.php">Coûts des activités</a></li>
            <li><a class="dropdown-item" href="comparaison.php">Classique vs ABC</a></li>
            <li><a class="dropdown-item" href="rentabilite.php">Rentabilité par objet</a></li>
          </ul>
        </li>
        <?php if (estAdmin()): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button">
            Administration
          </a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="periodes.php">Périodes</a></li>
            <li><a class="dropdown-item" href="utilisateurs.php">Utilisateurs</a></li>
            <li><a class="dropdown-item" href="audit.php">Journal d'audit</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>

      <?php if ($periodeActive !== null): ?>
      <form class="d-flex me-3" method="get" action="">
        <?php foreach ($_GET as $k => $v):
                if ($k === 'periode' || !is_string($v)) continue; ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
        <?php endforeach; ?>
        <label class="visually-hidden" for="selPeriode">Période</label>
        <select class="form-select form-select-sm" id="selPeriode" name="periode"
                onchange="this.form.submit()">
          <?php foreach (listerPeriodes() as $p): ?>
            <option value="<?= (int) $p['id'] ?>"
              <?= (int) $p['id'] === (int) $periodeActive['id'] ? 'selected' : '' ?>>
              <?= e($p['libelle']) ?><?= $p['statut'] === 'CLOTUREE' ? ' (clôturée)' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>

      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" role="button">
            <?= e(utilisateurNom()) ?>
            <span class="badge bg-light text-dark ms-1"><?= e($role) ?></span>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><a class="dropdown-item" href="profil.php">Mon profil</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php">Se déconnecter</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<main class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
    <h1 class="h4 mb-0"><?= e($titre) ?></h1>
    <?php if ($periodeActive !== null): ?>
      <span class="badge <?= $periodeActive['statut'] === 'OUVERTE' ? 'bg-success' : 'bg-secondary' ?>">
        Période : <?= e($periodeActive['libelle']) ?> — <?= e($periodeActive['statut']) ?>
      </span>
    <?php endif; ?>
  </div>
  <?= flashAfficher() ?>
    <?php
}

/** Rend le pied de page et ferme le document. */
function afficherPied(): void
{
    ?>
</main>

<footer class="border-top py-3 mt-4 bg-white">
  <div class="container-fluid small text-muted d-flex flex-wrap justify-content-between">
    <span><?= e(APP_ENTREPRISE) ?> — Comptabilité par activités (ABC) v<?= e(APP_VERSION) ?></span>
    <span>Master CCA — École Supérieure Polytechnique de Dakar</span>
  </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="assets/js/app.js"></script>
</body>
</html>
    <?php
}
