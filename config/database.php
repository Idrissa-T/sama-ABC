<?php
/**
 * Connexion à la base de données via PDO (singleton).
 *
 * Toutes les requêtes de l'application passent par des requêtes préparées :
 * ATTR_EMULATE_PREPARES est désactivé pour obtenir de vraies requêtes
 * préparées côté serveur MySQL, ce qui neutralise les injections SQL.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

final class Database
{
    private static ?PDO $instance = null;

    private function __construct() {}

    public static function getConnexion(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASS, $options);
            } catch (PDOException $e) {
                if (MODE_DEBUG) {
                    die('Erreur de connexion à la base : ' . $e->getMessage());
                }
                die('Impossible de se connecter à la base de données.');
            }
        }

        return self::$instance;
    }

    /** Empêche le clonage et la désérialisation du singleton. */
    private function __clone() {}

    public function __wakeup(): void
    {
        throw new RuntimeException('Désérialisation interdite.');
    }
}

/** Raccourci utilisé dans toute l'application. */
function db(): PDO
{
    return Database::getConnexion();
}
