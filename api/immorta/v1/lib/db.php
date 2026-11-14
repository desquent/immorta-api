<?php
declare(strict_types=1);

/**
 * Connexion PDO locale (mutualisé) — session UTC obligatoire.
 * Aucun accès MySQL direct depuis le VPS.
 */

function immorta_db_connect(array $dbConfig): PDO
{
    $host = $dbConfig['host'] ?? 'localhost';
    $name = $dbConfig['name'] ?? '';
    $user = $dbConfig['user'] ?? '';
    $pass = $dbConfig['pass'] ?? '';
    $charset = $dbConfig['charset'] ?? 'utf8mb4';

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $host, $name, $charset);
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    // UTC systématique à chaque connexion (CDC)
    $pdo->exec("SET time_zone = '+00:00'");
    $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_0900_ai_ci");
    return $pdo;
}
