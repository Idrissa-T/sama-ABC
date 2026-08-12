<?php
/**
 * Fonctions utilitaires transverses.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

// =====================================================================
//  1. PROTECTION XSS
// =====================================================================

/**
 * Échappe une valeur avant affichage HTML.
 * Toute donnée issue de la base ou de l'utilisateur doit passer par e().
 */
function e(mixed $valeur): string
{
    return htmlspecialchars((string) ($valeur ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// =====================================================================
//  2. FORMATAGE
// =====================================================================

/** Formate un montant en FCFA (séparateur d'espace, sans décimale). */
function fcfa(?float $montant, int $decimales = 0): string
{
    if ($montant === null) {
        return '—';
    }
    return number_format($montant, $decimales, ',', ' ') . ' ' . APP_DEVISE;
}

/** Formate un nombre sans unité. */
function nb(?float $valeur, int $decimales = 0): string
{
    if ($valeur === null) {
        return '—';
    }
    return number_format($valeur, $decimales, ',', ' ');
}

/** Formate un pourcentage. */
function pct(?float $valeur, int $decimales = 2): string
{
    if ($valeur === null) {
        return '—';
    }
    return number_format($valeur, $decimales, ',', ' ') . ' %';
}

/**
 * Classe Bootstrap selon le signe d'une valeur.
 * Utilisée pour colorer les marges et les subventionnements croisés.
 */
function classeSigne(?float $valeur): string
{
    if ($valeur === null) {
        return '';
    }
    if ($valeur < 0) {
        return 'text-danger fw-semibold';
    }
    if ($valeur > 0) {
        return 'text-success';
    }
    return 'text-muted';
}

// =====================================================================
//  3. JETON CSRF
// =====================================================================

function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfChamp(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrfToken()) . '">';
}

/** Vérifie le jeton CSRF ; interrompt la requête en cas d'échec. */
function csrfVerifier(): void
{
    $recu = $_POST['csrf_token'] ?? '';
    if (!is_string($recu) || !hash_equals($_SESSION['csrf_token'] ?? '', $recu)) {
        http_response_code(419);
        die('Jeton de sécurité invalide ou expiré. Rechargez la page.');
    }
}

// =====================================================================
//  4. MESSAGES FLASH
// =====================================================================

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flashAfficher(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $html .= '<div class="alert alert-' . e($f['type'])
              . ' alert-dismissible fade show" role="alert">'
              . e($f['message'])
              . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

// =====================================================================
//  5. JOURNAL D'AUDIT
// =====================================================================

/**
 * Enregistre une action sensible dans le journal d'audit horodaté.
 */
function audit(string $action, ?string $table = null, ?string $idCible = null, ?string $details = null): void
{
    $sql = 'INSERT INTO journal_audit
              (utilisateur_id, action, table_cible, id_cible, details, adresse_ip)
            VALUES (:uid, :action, :table, :id, :details, :ip)';
    try {
        db()->prepare($sql)->execute([
            ':uid'     => $_SESSION['utilisateur_id'] ?? null,
            ':action'  => $action,
            ':table'   => $table,
            ':id'      => $idCible,
            ':details' => $details,
            ':ip'      => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {
        // Le journal ne doit jamais bloquer l'action métier.
        error_log('Audit KO : ' . $e->getMessage());
    }
}

// =====================================================================
//  6. VALIDATION SERVEUR
// =====================================================================

/** Récupère une chaîne nettoyée depuis $_POST. */
function postTexte(string $cle, int $maxLen = 255): string
{
    $v = trim((string) ($_POST[$cle] ?? ''));
    return mb_substr($v, 0, $maxLen);
}

/** Récupère un décimal depuis $_POST (accepte la virgule décimale). */
function postDecimal(string $cle, float $defaut = 0.0): float
{
    $v = str_replace([' ', ','], ['', '.'], (string) ($_POST[$cle] ?? ''));
    return is_numeric($v) ? (float) $v : $defaut;
}

/** Récupère un entier positif depuis $_GET ou $_POST. */
function entierPositif(mixed $valeur, int $defaut = 0): int
{
    $i = filter_var($valeur, FILTER_VALIDATE_INT);
    return ($i !== false && $i > 0) ? $i : $defaut;
}

// =====================================================================
//  7. PAGINATION
// =====================================================================

/**
 * Calcule les bornes de pagination.
 *
 * @return array{page:int, offset:int, total_pages:int, total:int}
 */
function paginer(int $totalLignes, int $pageDemandee, int $parPage = LIGNES_PAR_PAGE): array
{
    $totalPages = max(1, (int) ceil($totalLignes / $parPage));
    $page       = min(max(1, $pageDemandee), $totalPages);

    return [
        'page'        => $page,
        'offset'      => ($page - 1) * $parPage,
        'total_pages' => $totalPages,
        'total'       => $totalLignes,
    ];
}

/** Rend la barre de pagination Bootstrap. */
function paginationHtml(array $p, string $urlBase): string
{
    if ($p['total_pages'] <= 1) {
        return '';
    }
    $sep  = str_contains($urlBase, '?') ? '&' : '?';
    $html = '<nav><ul class="pagination pagination-sm mb-0">';

    $prev = $p['page'] - 1;
    $html .= '<li class="page-item' . ($p['page'] <= 1 ? ' disabled' : '') . '">'
           . '<a class="page-link" href="' . e($urlBase . $sep . 'page=' . $prev) . '">&laquo;</a></li>';

    for ($i = 1; $i <= $p['total_pages']; $i++) {
        $actif = $i === $p['page'] ? ' active' : '';
        $html .= '<li class="page-item' . $actif . '">'
               . '<a class="page-link" href="' . e($urlBase . $sep . 'page=' . $i) . '">' . $i . '</a></li>';
    }

    $next = $p['page'] + 1;
    $html .= '<li class="page-item' . ($p['page'] >= $p['total_pages'] ? ' disabled' : '') . '">'
           . '<a class="page-link" href="' . e($urlBase . $sep . 'page=' . $next) . '">&raquo;</a></li>';

    return $html . '</ul></nav>';
}

// =====================================================================
//  8. PÉRIODES
// =====================================================================

/** Liste des périodes, la plus récente d'abord. */
function listerPeriodes(): array
{
    return db()->query('SELECT id, code, libelle, statut FROM periodes ORDER BY date_debut DESC')
               ->fetchAll();
}

/**
 * Période active : celle passée en GET si elle existe, sinon la plus récente
 * période ouverte, sinon la plus récente.
 */
function periodeActive(): array
{
    $periodes = listerPeriodes();
    if (!$periodes) {
        die('Aucune période enregistrée : importez le script sql/abc_costing.sql.');
    }

    $demandee = entierPositif($_GET['periode'] ?? 0);
    if ($demandee) {
        foreach ($periodes as $p) {
            if ((int) $p['id'] === $demandee) {
                return $p;
            }
        }
    }
    foreach ($periodes as $p) {
        if ($p['statut'] === 'OUVERTE') {
            return $p;
        }
    }
    return $periodes[0];
}

/** Une période clôturée est en lecture seule. */
function periodeModifiable(array $periode): bool
{
    return $periode['statut'] === 'OUVERTE';
}
