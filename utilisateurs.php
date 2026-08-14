<?php
/**
 * Administration des utilisateurs et des rôles.
 *
 * Réservé au profil ADMIN. Garde-fous implémentés :
 *   - impossible de supprimer ou de désactiver son propre compte ;
 *   - impossible de retirer le dernier administrateur actif ;
 *   - mot de passe haché avec password_hash(), jamais stocké en clair ;
 *   - un mot de passe laissé vide en modification reste inchangé.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';

exigerRole('ADMIN');

$periode = periodeActive();
$roles   = ['ADMIN', 'CONTROLEUR', 'LECTEUR'];
$libRoles = [
    'ADMIN'      => 'Administrateur — accès complet',
    'CONTROLEUR' => 'Contrôleur de gestion — saisie et modification',
    'LECTEUR'    => 'Lecteur — consultation et exports',
];

$moi     = (int) ($_SESSION['utilisateur_id'] ?? 0);
$erreurs = [];
$edition = null;

/** Nombre d'administrateurs actifs, hors identifiant donné. */
function nbAdminsActifs(int $sauf = 0): int
{
    $st = db()->prepare(
        'SELECT COUNT(*) FROM utilisateurs WHERE role = :r AND actif = 1 AND id <> :i'
    );
    $st->execute([':r' => 'ADMIN', ':i' => $sauf]);
    return (int) $st->fetchColumn();
}

// =====================================================================
//  ACTIONS
// =====================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfVerifier();
    $action = postTexte('action', 20);

    if ($action === 'enregistrer') {
        $id      = entierPositif($_POST['id'] ?? 0);
        $login   = strtolower(postTexte('login', 50));
        $nom     = postTexte('nom_complet', 120);
        $email   = postTexte('email', 150);
        $role    = postTexte('role', 20);
        $actif   = isset($_POST['actif']) ? 1 : 0;
        $mdp     = (string) ($_POST['mot_de_passe'] ?? '');
        $mdp2    = (string) ($_POST['mot_de_passe2'] ?? '');

        if ($login === '')  { $erreurs[] = 'L\'identifiant est obligatoire.'; }
        if ($nom === '')    { $erreurs[] = 'Le nom complet est obligatoire.'; }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $erreurs[] = 'L\'adresse électronique est invalide.';
        }
        if (!in_array($role, $roles, true)) {
            $erreurs[] = 'Rôle invalide.';
        }
        if (!preg_match('/^[a-z0-9._-]+$/', $login)) {
            $erreurs[] = 'L\'identifiant n\'accepte que lettres minuscules, chiffres, point, tiret et souligné.';
        }

        // Mot de passe : obligatoire à la création, facultatif en modification
        if ($id === 0 || $mdp !== '') {
            if (mb_strlen($mdp) < 8) {
                $erreurs[] = 'Le mot de passe doit comporter au moins 8 caractères.';
            }
            if ($mdp !== $mdp2) {
                $erreurs[] = 'Les deux mots de passe ne correspondent pas.';
            }
        }

        // Garde-fous sur son propre compte et sur le dernier administrateur
        if ($id === $moi && $actif === 0) {
            $erreurs[] = 'Vous ne pouvez pas désactiver votre propre compte.';
        }
        if ($id === $moi && $role !== 'ADMIN') {
            $erreurs[] = 'Vous ne pouvez pas retirer votre propre rôle d\'administrateur.';
        }
        if ($id > 0 && ($role !== 'ADMIN' || $actif === 0) && nbAdminsActifs($id) === 0) {
            $erreurs[] = 'Opération refusée : il doit rester au moins un administrateur actif.';
        }

        if (!$erreurs) {
            $st = db()->prepare('SELECT id FROM utilisateurs WHERE login = :l AND id <> :i');
            $st->execute([':l' => $login, ':i' => $id]);
            if ($st->fetch()) {
                $erreurs[] = 'L\'identifiant ' . $login . ' est déjà pris.';
            }
            $st = db()->prepare('SELECT id FROM utilisateurs WHERE email = :e AND id <> :i');
            $st->execute([':e' => $email, ':i' => $id]);
            if ($st->fetch()) {
                $erreurs[] = 'Cette adresse électronique est déjà utilisée.';
            }
        }

        if (!$erreurs) {
            try {
                if ($id > 0) {
                    if ($mdp !== '') {
                        $sql = 'UPDATE utilisateurs
                                   SET login = :l, nom_complet = :n, email = :e,
                                       role = :r, actif = :a, mot_de_passe = :m
                                 WHERE id = :i';
                        db()->prepare($sql)->execute([
                            ':l' => $login, ':n' => $nom, ':e' => $email,
                            ':r' => $role, ':a' => $actif,
                            ':m' => password_hash($mdp, PASSWORD_DEFAULT), ':i' => $id,
                        ]);
                        audit('UPDATE', 'utilisateurs', (string) $id,
                              'Compte ' . $login . ' modifié, mot de passe réinitialisé');
                    } else {
                        $sql = 'UPDATE utilisateurs
                                   SET login = :l, nom_complet = :n, email = :e,
                                       role = :r, actif = :a
                                 WHERE id = :i';
                        db()->prepare($sql)->execute([
                            ':l' => $login, ':n' => $nom, ':e' => $email,
                            ':r' => $role, ':a' => $actif, ':i' => $id,
                        ]);
                        audit('UPDATE', 'utilisateurs', (string) $id, 'Compte ' . $login . ' modifié');
                    }
                    flash('success', 'Compte ' . $login . ' mis à jour.');
                } else {
                    $sql = 'INSERT INTO utilisateurs
                              (login, mot_de_passe, nom_complet, email, role, actif)
                            VALUES (:l, :m, :n, :e, :r, :a)';
                    db()->prepare($sql)->execute([
                        ':l' => $login, ':m' => password_hash($mdp, PASSWORD_DEFAULT),
                        ':n' => $nom, ':e' => $email, ':r' => $role, ':a' => $actif,
                    ]);
                    audit('CREATE', 'utilisateurs', (string) db()->lastInsertId(),
                          'Création du compte ' . $login . ' (' . $role . ')');
                    flash('success', 'Compte ' . $login . ' créé.');
                }
                header('Location: utilisateurs.php');
                exit;

            } catch (PDOException $ex) {
                $erreurs[] = 'Erreur d\'enregistrement.';
                error_log('Utilisateurs KO : ' . $ex->getMessage());
            }
        }
    }

    if ($action === 'supprimer') {
        $id = entierPositif($_POST['id'] ?? 0);

        if ($id === $moi) {
            flash('danger', 'Vous ne pouvez pas supprimer votre propre compte.');
        } elseif (nbAdminsActifs($id) === 0) {
            flash('danger', 'Suppression refusée : il doit rester un administrateur actif.');
        } else {
            // Le journal d'audit conserve la trace : la clé étrangère est
            // définie ON DELETE SET NULL, l'historique n'est donc pas perdu.
            db()->prepare('DELETE FROM utilisateurs WHERE id = :i')->execute([':i' => $id]);
            audit('DELETE', 'utilisateurs', (string) $id, 'Suppression de compte');
            flash('success', 'Compte supprimé. Le journal d\'audit conserve son historique.');
        }
        header('Location: utilisateurs.php');
        exit;
    }
}

// ---------- Chargement pour modification ----------
$idEdition = entierPositif($_GET['modifier'] ?? 0);
if ($idEdition > 0) {
    $st = db()->prepare('SELECT id, login, nom_complet, email, role, actif
                           FROM utilisateurs WHERE id = :i');
    $st->execute([':i' => $idEdition]);
    $edition = $st->fetch() ?: null;
}

// =====================================================================
//  LISTE
// =====================================================================
$recherche = trim((string) ($_GET['q'] ?? ''));
$filtreRol = (string) ($_GET['role'] ?? '');

$where  = ['1 = 1'];
$params = [];

if ($recherche !== '') {
    $where[] = '(login LIKE :q OR nom_complet LIKE :q2 OR email LIKE :q3)';
    $params[':q']  = '%' . $recherche . '%';
    $params[':q2'] = '%' . $recherche . '%';
    $params[':q3'] = '%' . $recherche . '%';
}
if (in_array($filtreRol, $roles, true)) {
    $where[]        = 'role = :rol';
    $params[':rol'] = $filtreRol;
}
$clause = implode(' AND ', $where);

$stNb = db()->prepare('SELECT COUNT(*) FROM utilisateurs WHERE ' . $clause);
foreach ($params as $k => $v) {
    $stNb->bindValue($k, $v);
}
$stNb->execute();
$total = (int) $stNb->fetchColumn();

$p = paginer($total, entierPositif($_GET['page'] ?? 1));

$sql = 'SELECT id, login, nom_complet, email, role, actif,
               derniere_connexion, date_creation
          FROM utilisateurs
         WHERE ' . $clause . '
      ORDER BY role, login
         LIMIT ' . LIGNES_PAR_PAGE . ' OFFSET ' . $p['offset'];
$st = db()->prepare($sql);
foreach ($params as $k => $v) {
    $st->bindValue($k, $v);
}
$st->execute();
$utilisateurs = $st->fetchAll();

$qs = http_build_query(array_filter([
    'q' => $recherche, 'role' => $filtreRol,
], static fn($v) => $v !== '' && $v !== null));

afficherEntete('Administration des utilisateurs', $periode);
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
    <div class="card">
      <div class="card-header">
        <strong><?= $edition ? 'Modifier le compte' : 'Nouveau compte' ?></strong>
      </div>
      <div class="card-body">
        <form method="post" action="utilisateurs.php" id="formUtilisateur" novalidate>
          <?= csrfChamp() ?>
          <input type="hidden" name="action" value="enregistrer">
          <input type="hidden" name="id" value="<?= (int) ($edition['id'] ?? 0) ?>">

          <div class="mb-3">
            <label for="login" class="form-label">Identifiant <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="login" name="login" required
                   maxlength="50" pattern="[a-z0-9._\-]+"
                   value="<?= e($edition['login'] ?? '') ?>" autocomplete="off">
            <div class="form-text">Minuscules, chiffres, point, tiret ou souligné.</div>
          </div>

          <div class="mb-3">
            <label for="nom_complet" class="form-label">Nom complet <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="nom_complet" name="nom_complet" required
                   maxlength="120" value="<?= e($edition['nom_complet'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label for="email" class="form-label">Adresse électronique <span class="text-danger">*</span></label>
            <input type="email" class="form-control" id="email" name="email" required
                   maxlength="150" value="<?= e($edition['email'] ?? '') ?>">
          </div>

          <div class="mb-3">
            <label for="role" class="form-label">Rôle <span class="text-danger">*</span></label>
            <select class="form-select" id="role" name="role" required>
              <?php foreach ($roles as $r): ?>
                <option value="<?= e($r) ?>" title="<?= e($libRoles[$r]) ?>"
                  <?= ($edition['role'] ?? 'LECTEUR') === $r ? 'selected' : '' ?>>
                  <?= e($libRoles[$r]) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label for="mot_de_passe" class="form-label">
              Mot de passe <?= $edition ? '' : '<span class="text-danger">*</span>' ?>
            </label>
            <input type="password" class="form-control" id="mot_de_passe" name="mot_de_passe"
                   minlength="8" <?= $edition ? '' : 'required' ?> autocomplete="new-password">
            <div class="form-text">
              <?= $edition
                  ? 'Laissez vide pour conserver le mot de passe actuel.'
                  : 'Au moins 8 caractères.' ?>
            </div>
          </div>

          <div class="mb-3">
            <label for="mot_de_passe2" class="form-label">Confirmation</label>
            <input type="password" class="form-control" id="mot_de_passe2" name="mot_de_passe2"
                   minlength="8" autocomplete="new-password">
          </div>

          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" id="actif" name="actif" value="1"
              <?= (int) ($edition['actif'] ?? 1) === 1 ? 'checked' : '' ?>
              <?= (int) ($edition['id'] ?? 0) === $moi ? 'disabled checked' : '' ?>>
            <label class="form-check-label" for="actif">Compte actif</label>
            <?php if ((int) ($edition['id'] ?? 0) === $moi): ?>
              <input type="hidden" name="actif" value="1">
              <div class="form-text">Vous ne pouvez pas désactiver votre propre compte.</div>
            <?php endif; ?>
          </div>

          <div class="d-flex gap-2">
            <button type="submit" class="btn btn-brique">
              <?= $edition ? 'Enregistrer' : 'Créer' ?>
            </button>
            <?php if ($edition): ?>
              <a href="utilisateurs.php" class="btn btn-outline-secondary">Annuler</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- ================= LISTE ================= -->
  <div class="col-12 col-xl-8">
    <div class="card mb-3">
      <div class="card-body py-3">
        <form method="get" action="utilisateurs.php" class="row g-2 align-items-end">
          <div class="col-12 col-sm-6">
            <label for="q" class="form-label small mb-1">Recherche</label>
            <input type="search" class="form-control form-control-sm" id="q" name="q"
                   value="<?= e($recherche) ?>" placeholder="Identifiant, nom ou courriel">
          </div>
          <div class="col-7 col-sm-4">
            <label for="frole" class="form-label small mb-1">Rôle</label>
            <select class="form-select form-select-sm" id="frole" name="role">
              <option value="">Tous</option>
              <?php foreach ($roles as $r): ?>
                <option value="<?= e($r) ?>" <?= $filtreRol === $r ? 'selected' : '' ?>>
                  <?= e($r) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-5 col-sm-2 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-brique w-100">Filtrer</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><strong><?= $total ?> compte(s)</strong></div>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr>
              <th>Identifiant</th>
              <th>Nom complet</th>
              <th>Rôle</th>
              <th>Dernière connexion</th>
              <th class="text-center">État</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($utilisateurs as $u):
              $estMoi = (int) $u['id'] === $moi; ?>
            <tr>
              <td>
                <code><?= e($u['login']) ?></code>
                <?php if ($estMoi): ?>
                  <span class="badge bg-brique-bar text-white">vous</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?= e($u['nom_complet']) ?><br>
                <span class="text-muted"><?= e($u['email']) ?></span>
              </td>
              <td>
                <span class="badge <?= $u['role'] === 'ADMIN' ? 'bg-danger'
                    : ($u['role'] === 'CONTROLEUR' ? 'bg-primary' : 'bg-secondary') ?>">
                  <?= e($u['role']) ?>
                </span>
              </td>
              <td class="small text-muted">
                <?= $u['derniere_connexion']
                    ? e(date('d/m/Y H:i', strtotime($u['derniere_connexion'])))
                    : 'jamais' ?>
              </td>
              <td class="text-center">
                <?php if ((int) $u['actif'] === 1): ?>
                  <span class="badge bg-light text-dark border">Actif</span>
                <?php else: ?>
                  <span class="badge bg-secondary">Désactivé</span>
                <?php endif; ?>
              </td>
              <td class="text-end text-nowrap">
                <a class="btn btn-sm btn-outline-secondary"
                   href="utilisateurs.php?modifier=<?= (int) $u['id'] ?>">Modifier</a>
                <form method="post" action="utilisateurs.php" class="d-inline"
                      onsubmit="return confirm('Supprimer définitivement le compte <?= e($u['login']) ?> ?');">
                  <?= csrfChamp() ?>
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"
                    <?= $estMoi ? 'disabled title="Votre propre compte"' : '' ?>>
                    Supprimer
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($p['total_pages'] > 1): ?>
      <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="small text-muted">Page <?= $p['page'] ?> sur <?= $p['total_pages'] ?></span>
        <?= paginationHtml($p, 'utilisateurs.php?' . $qs) ?>
      </div>
      <?php endif; ?>
    </div>

    <p class="small text-muted mt-3 mb-0">
      Les mots de passe sont hachés avec <code>password_hash()</code> et ne sont
      jamais consultables, y compris par un administrateur. En cas d'oubli, la
      seule option est la définition d'un nouveau mot de passe.
    </p>
  </div>
</div>

<script>
document.getElementById('formUtilisateur')?.addEventListener('submit', function (ev) {
  var m1 = document.getElementById('mot_de_passe');
  var m2 = document.getElementById('mot_de_passe2');

  if (m1.value !== m2.value) {
    m2.setCustomValidity('Les mots de passe ne correspondent pas.');
  } else {
    m2.setCustomValidity('');
  }
  if (!this.checkValidity()) {
    ev.preventDefault();
    ev.stopPropagation();
  }
  this.classList.add('was-validated');
});
</script>

<?php
afficherPied();
