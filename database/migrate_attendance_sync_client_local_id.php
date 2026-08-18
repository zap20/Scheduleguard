<?php

declare(strict_types=1);

/**
 * Additive columns for Checker mobile attendance sync idempotency.
 * Does not change existing attendance read APIs.
 *
 * Usage: php database/migrate_attendance_sync_client_local_id.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $stmt->execute([':t' => $table, ':i' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!columnExists($pdo, 'attendanceRecord', 'clientLocalId')) {
    $pdo->exec(
        'ALTER TABLE attendanceRecord
         ADD COLUMN clientLocalId VARCHAR(36) NULL AFTER syncedAt'
    );
    echo "Added attendanceRecord.clientLocalId\n";
} else {
    echo "attendanceRecord.clientLocalId already exists\n";
}

if (!indexExists($pdo, 'attendanceRecord', 'uq_attendance_client_local_id')) {
    $pdo->exec(
        'ALTER TABLE attendanceRecord
         ADD UNIQUE KEY uq_attendance_client_local_id (clientLocalId)'
    );
    echo "Added unique key uq_attendance_client_local_id\n";
} else {
    echo "uq_attendance_client_local_id already exists\n";
}

if (!columnExists($pdo, 'attendanceRecord', 'deviceMeta')) {
    $pdo->exec(
        'ALTER TABLE attendanceRecord
         ADD COLUMN deviceMeta VARCHAR(500) NULL AFTER clientLocalId'
    );
    echo "Added attendanceRecord.deviceMeta\n";
} else {
    echo "attendanceRecord.deviceMeta already exists\n";
}

echo "Done.\n";
