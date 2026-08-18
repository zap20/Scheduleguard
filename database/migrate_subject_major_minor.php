<?php

declare(strict_types=1);

/**
 * Add major/minor subject typing, lecture/lab hours, lab session split,
 * and optional serving department (for cross-program minors).
 *
 * Usage: php database/migrate_subject_major_minor.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function columnExists(PDO $pdo, string $table, string $column): bool
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

function fkExists(PDO $pdo, string $name): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = \'subject\'
           AND CONSTRAINT_NAME = :name
           AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $stmt->execute([':name' => $name]);
    return (int) $stmt->fetchColumn() > 0;
}

$alters = [];

if (!columnExists($pdo, 'subject', 'subjectType')) {
    $alters[] = "ADD COLUMN subjectType ENUM('MAJOR','MINOR') NOT NULL DEFAULT 'MAJOR' AFTER curriculumYear";
}
if (!columnExists($pdo, 'subject', 'lectureHours')) {
    $alters[] = 'ADD COLUMN lectureHours DECIMAL(4,2) NOT NULL DEFAULT 0 AFTER units';
}
if (!columnExists($pdo, 'subject', 'labHours')) {
    $alters[] = 'ADD COLUMN labHours DECIMAL(4,2) NOT NULL DEFAULT 0 AFTER lectureHours';
}
if (!columnExists($pdo, 'subject', 'labSessionCount')) {
    $alters[] = 'ADD COLUMN labSessionCount TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER labHours';
}
if (!columnExists($pdo, 'subject', 'servingDepartmentId')) {
    $alters[] = 'ADD COLUMN servingDepartmentId VARCHAR(36) NULL AFTER departmentId';
}

if ($alters !== []) {
    $pdo->exec('ALTER TABLE subject ' . implode(",\n  ", $alters));
    echo "Added subject columns.\n";
} else {
    echo "Subject columns already present.\n";
}

if (columnExists($pdo, 'subject', 'servingDepartmentId') && !fkExists($pdo, 'fk_subject_serving_department')) {
    $pdo->exec(
        'ALTER TABLE subject
         ADD CONSTRAINT fk_subject_serving_department
           FOREIGN KEY (servingDepartmentId) REFERENCES department (uid)
           ON UPDATE CASCADE
           ON DELETE SET NULL'
    );
    echo "Added servingDepartment FK.\n";
}

// Backfill sensible defaults from preferredRoomType / units.
$pdo->exec(
    "UPDATE subject
     SET lectureHours = CASE
           WHEN preferredRoomType = 'LECTURE' AND lectureHours = 0 THEN GREATEST(units, 1.5)
           ELSE lectureHours
         END,
         labHours = CASE
           WHEN preferredRoomType = 'LAB' AND labHours = 0 THEN GREATEST(units, 1.5)
           ELSE labHours
         END,
         labSessionCount = GREATEST(labSessionCount, 1)"
);
echo "Backfilled lecture/lab hours from existing room preference.\n";
echo "OK.\n";
