<?php

declare(strict_types=1);

/**
 * CLI/browser installer: creates schema and loads seed data.
 *
 * Usage:
 *   php database/install.php
 */

require_once dirname(__DIR__) . '/config/env.php';

$host = (string) env('DB_HOST', '127.0.0.1');
$port = (string) env('DB_PORT', '3306');
$name = (string) env('DB_NAME', 'scheduleguard');
$user = (string) env('DB_USER', 'root');
$pass = (string) env('DB_PASS', '');

function runSqlFile(PDO $pdo, string $path): void
{
    $sql = file_get_contents($path);
    if ($sql === false) {
        throw new RuntimeException("Unable to read SQL file: {$path}");
    }

    // Strip USE statements when already connected to a server-level PDO
    // (schema.sql creates the database itself).
    $pdo->exec($sql);
}

echo "Connecting to MySQL at {$host}:{$port} …\n";

try {
    $server = new PDO(
        "mysql:host={$host};port={$port};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "Applying schema …\n";
    runSqlFile($server, __DIR__ . '/schema.sql');

    echo "Applying seed …\n";
    runSqlFile($server, __DIR__ . '/seed.sql');

    echo "Done. Database '{$name}' is ready.\n";
    echo "Test login: cict.dean@scheduleguard.test / Password123!\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "Install failed: " . $e->getMessage() . "\n");
    exit(1);
}
