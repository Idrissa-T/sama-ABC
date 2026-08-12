<?php
/**
 * Page 403 : accès refusé par le contrôle des rôles.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

afficherEntete('Accès refusé');
?>
<div class="card border-danger">
  <div class="card-body">
    <h2 class="h5 text-danger">Accès refusé (403)</h2>
    <p class="mb-2">
      Votre profil <strong><?= e(utilisateurRole()) ?></strong> ne permet pas
      d'accéder à cette page. Cette tentative a été enregistrée dans le
      journal d'audit.
    </p>
    <a href="index.php" class="btn btn-sm btn-brique">Retour au tableau de bord</a>
  </div>
</div>
<?php
afficherPied();
