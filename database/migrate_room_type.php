<?php

declare(strict_types=1);

/**
 * Add room.roomType and subject.preferredRoomType (LAB / LECTURE).
 * Safe to re-run.
 *
 *   php database/migrate_room_type.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function columnExistsRoomMig(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!columnExistsRoomMig($pdo, 'room', 'roomType')) {
    $pdo->exec(
        "ALTER TABLE room
         ADD COLUMN roomType ENUM('LAB','LECTURE') NOT NULL DEFAULT 'LECTURE' AFTER capacity"
    );
    echo "Added room.roomType.\n";

    // Heuristic backfill: names containing LAB → LAB.
    $pdo->exec(
        "UPDATE room
         SET roomType = 'LAB'
         WHERE UPPER(name) LIKE '%LAB%' OR UPPER(building) LIKE '%LAB%'"
    );
    echo "Backfilled LAB rooms from name/building heuristics.\n";
} else {
    echo "room.roomType already exists.\n";
}

if (!columnExistsRoomMig($pdo, 'subject', 'preferredRoomType')) {
    $pdo->exec(
        "ALTER TABLE subject
         ADD COLUMN preferredRoomType ENUM('LAB','LECTURE') NOT NULL DEFAULT 'LECTURE' AFTER units"
    );
    echo "Added subject.preferredRoomType.\n";

    $pdo->exec(
        "UPDATE subject
         SET preferredRoomType = 'LAB'
         WHERE UPPER(code) LIKE '%LAB%'
            OR UPPER(title) LIKE '%LAB%'
            OR UPPER(title) LIKE '%LABORATORY%'"
    );
    echo "Backfilled subject preferredRoomType heuristics.\n";
} else {
    echo "subject.preferredRoomType already exists.\n";
}

echo "Room type migration complete.\n";
