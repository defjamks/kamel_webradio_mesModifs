<?php
// ============================================================
//  WebRadio – Configuration de la connexion MariaDB
// ============================================================
//  Adaptez ces valeurs à votre environnement.
//  Idéalement, stockez les secrets dans des variables d'env
//  et n'incluez JAMAIS ce fichier dans un dépôt public.
// ============================================================

define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'webradio');
define('DB_USER', getenv('DB_USER') ?: 'webradio_user');
define('DB_PASS', getenv('DB_PASS') ?: 'ChangeMe!');
define('DB_CHAR', 'utf8mb4');

/**
 * Retourne une connexion PDO partagée (singleton).
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s',
                       DB_HOST, DB_PORT, DB_NAME, DB_CHAR);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
