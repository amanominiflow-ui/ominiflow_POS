<?php
/**
 * Database connection handler for OminiFlow POS
 * Returns a PDO instance connected to `ominiflow_pos` database.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

function get_db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Auto-attempt migration if database does not exist
            if ($e->getCode() === 1049) {
                require_once __DIR__ . '/../database/migrate.php';
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
            } else {
                throw $e;
            }
        }
    }

    return $pdo;
}
