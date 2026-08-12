<?php
/**
 * Déconnexion explicite.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

sessionDemarrer();
deconnecter('Vous avez été déconnecté.');
