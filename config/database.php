<?php

declare(strict_types=1);

require_once __DIR__ . '/env.php';

/**
 * Shared PDO connection for ScheduleGuard.
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = (string) env('DB_HOST', '127.0.0.1');
    $port = (string) env('DB_PORT', '3306');
    $name = (string) env('DB_NAME', 'scheduleguard');
    $user = (string) env('DB_USER', 'root');
    $pass = (string) env('DB_PASS', '');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
