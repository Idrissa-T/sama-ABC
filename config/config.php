<?php
/**
 * A & P BRIQUES - Plateforme de comptabilité par activités (ABC)
 * Configuration centrale
 *
 * Master CCA - École Supérieure Polytechnique de Dakar
 */

declare(strict_types=1);

// ---------------------------------------------------------------------
// Base de données (paramètres XAMPP par défaut)
// ---------------------------------------------------------------------
const DB_HOST = '127.0.0.1';
const DB_PORT = '3306';
const DB_NAME = 'abc_costing';
const DB_USER = 'root';
const DB_PASS = '';          // XAMPP : mot de passe root vide par défaut
const DB_CHARSET = 'utf8mb4';

// ---------------------------------------------------------------------
// Application
// ---------------------------------------------------------------------
const APP_NOM       = 'A & P Briques — Comptabilité par activités';
const APP_ENTREPRISE = 'A & P BRIQUES SUARL';
const APP_VERSION   = '1.0';
const APP_DEVISE    = 'FCFA';

// Durée d'inactivité avant déconnexion automatique (secondes)
const SESSION_TIMEOUT = 1800;   // 30 minutes

// Nombre de lignes par page dans les listes (exigence : >= 20)
const LIGNES_PAR_PAGE = 20;

// Tolérance sur le contrôle « somme des clés = 100 % »
const TOLERANCE_CLES = 0.0001;

// Affichage des erreurs : à passer à false en production
const MODE_DEBUG = true;

// ---------------------------------------------------------------------
// Gestion des erreurs
// ---------------------------------------------------------------------
if (MODE_DEBUG) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}

date_default_timezone_set('Africa/Dakar');
