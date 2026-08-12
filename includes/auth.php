<?php
/**
 * Authentification, sessions sécurisées et gestion des rôles.
 *
 * Trois profils différenciés :
 *   ADMIN      : tout, y compris la gestion des utilisateurs et des périodes
 *   CONTROLEUR : saisie et modification des données de gestion
 *   LECTEUR    : consultation et exports uniquement
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';

// ---------------------------------------------------------------------
// Démarrage de la session avec cookie durci
// ---------------------------------------------------------------------
function sessionDemarrer(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,   // inaccessible au JavaScript
        'samesite' => 'Lax',  // limite les requêtes croisées
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_name('ABC_SESSID');
    session_start();
}

// ---------------------------------------------------------------------
// Expiration automatique par inactivité
// ---------------------------------------------------------------------
function sessionVerifierExpiration(): void
{
    if (!isset($_SESSION['utilisateur_id'])) {
        return;
    }

    $derniere = $_SESSION['derniere_activite'] ?? time();
    if (time() - $derniere > SESSION_TIMEOUT) {
        deconnecter('Session expirée après ' . (SESSION_TIMEOUT / 60) . ' minutes d\'inactivité.');
    }
    $_SESSION['derniere_activite'] = time();
}

// ---------------------------------------------------------------------
// Connexion / déconnexion
// ---------------------------------------------------------------------

/**
 * Tente une connexion. Retourne un message d'erreur ou null si succès.
 * Le même message est renvoyé pour un login inconnu et un mot de passe
 * erroné, afin de ne pas révéler l'existence d'un compte.
 */
function connecter(string $login, string $motDePasse): ?string
{
    if ($login === '' || $motDePasse === '') {
        return 'Veuillez renseigner votre identifiant et votre mot de passe.';
    }

    $sql = 'SELECT id, login, mot_de_passe, nom_complet, role, actif
              FROM utilisateurs WHERE login = :login LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([':login' => $login]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($motDePasse, $u['mot_de_passe'])) {
        audit('LOGIN_ECHEC', 'utilisateurs', null, 'Identifiant essayé : ' . $login);
        return 'Identifiant ou mot de passe incorrect.';
    }

    if ((int) $u['actif'] !== 1) {
        return 'Ce compte est désactivé. Contactez l\'administrateur.';
    }

    // Réhachage transparent si le coût bcrypt a changé
    if (password_needs_rehash($u['mot_de_passe'], PASSWORD_DEFAULT)) {
        $nouveau = password_hash($motDePasse, PASSWORD_DEFAULT);
        db()->prepare('UPDATE utilisateurs SET mot_de_passe = :h WHERE id = :id')
            ->execute([':h' => $nouveau, ':id' => $u['id']]);
    }

    // Régénération de l'identifiant de session : anti fixation de session
    session_regenerate_id(true);

    $_SESSION['utilisateur_id']    = (int) $u['id'];
    $_SESSION['login']             = $u['login'];
    $_SESSION['nom_complet']       = $u['nom_complet'];
    $_SESSION['role']              = $u['role'];
    $_SESSION['derniere_activite'] = time();

    db()->prepare('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = :id')
        ->execute([':id' => $u['id']]);

    audit('LOGIN', 'utilisateurs', (string) $u['id'], 'Connexion de ' . $u['login']);

    return null;
}

function deconnecter(?string $message = null): void
{
    if (isset($_SESSION['login'])) {
        audit('LOGOUT', 'utilisateurs', (string) ($_SESSION['utilisateur_id'] ?? ''),
              'Déconnexion de ' . $_SESSION['login']);
    }

    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'],
                  (bool) $p['secure'], (bool) $p['httponly']);
    }
    session_destroy();

    sessionDemarrer();
    if ($message !== null) {
        flash('warning', $message);
    }
    header('Location: login.php');
    exit;
}

// ---------------------------------------------------------------------
// Gardes d'accès
// ---------------------------------------------------------------------

function estConnecte(): bool
{
    return isset($_SESSION['utilisateur_id']);
}

function utilisateurRole(): string
{
    return $_SESSION['role'] ?? '';
}

function utilisateurNom(): string
{
    return $_SESSION['nom_complet'] ?? '';
}

/** Bloque l'accès à toute page réservée aux utilisateurs connectés. */
function exigerConnexion(): void
{
    sessionDemarrer();
    sessionVerifierExpiration();

    if (!estConnecte()) {
        flash('warning', 'Veuillez vous connecter pour accéder à cette page.');
        header('Location: login.php');
        exit;
    }
}

/**
 * Vérifie que l'utilisateur possède l'un des rôles attendus.
 * Exemple : exigerRole('ADMIN', 'CONTROLEUR');
 */
function exigerRole(string ...$roles): void
{
    exigerConnexion();

    if (!in_array(utilisateurRole(), $roles, true)) {
        audit('ACCES_REFUSE', null, null,
              'Rôle ' . utilisateurRole() . ' sur ' . ($_SERVER['REQUEST_URI'] ?? ''));
        http_response_code(403);
        include __DIR__ . '/../403.php';
        exit;
    }
}

/** Droit d'écriture sur les données de gestion. */
function peutEcrire(): bool
{
    return in_array(utilisateurRole(), ['ADMIN', 'CONTROLEUR'], true);
}

/** Droit d'administration. */
function estAdmin(): bool
{
    return utilisateurRole() === 'ADMIN';
}

/** Interrompt l'action si l'utilisateur n'a pas le droit d'écrire. */
function exigerEcriture(): void
{
    if (!peutEcrire()) {
        http_response_code(403);
        die('Votre profil ne permet pas de modifier les données.');
    }
}
