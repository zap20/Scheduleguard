<?php

declare(strict_types=1);

/**
 * Add Program Head enrollment evaluation fields on student users.
 *
 * Usage: php database/migrate_student_evaluation.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$studentTable = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'student' AND TABLE_TYPE = 'BASE TABLE'"
)->fetchColumn();
if ((int) $studentTable > 0) {
    echo "Skipped: user side already normalized (student table exists).\n";
    exit(0);
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!columnExists($pdo, 'user', 'enrollmentEvalStatus')) {
    $pdo->exec(
        "ALTER TABLE `user`
         ADD COLUMN enrollmentEvalStatus ENUM('Pending', 'Approved', 'Rejected') NULL
           AFTER studentType,
         ADD COLUMN enrollmentEvalBy VARCHAR(36) NULL
           AFTER enrollmentEvalStatus,
         ADD COLUMN enrollmentEvalAt DATETIME NULL
           AFTER enrollmentEvalBy,
         ADD COLUMN enrollmentEvalNotes VARCHAR(500) NULL
           AFTER enrollmentEvalAt"
    );
    echo "Added user.enrollmentEval* columns\n";
} else {
    echo "user.enrollmentEvalStatus already exists\n";
}

$pdo->exec(
    "UPDATE `user`
     SET enrollmentEvalStatus = 'Pending'
     WHERE role = 'Student'
       AND (enrollmentEvalStatus IS NULL OR enrollmentEvalStatus = '')"
);
echo "Backfilled Student enrollmentEvalStatus=Pending\n";
echo "Done.\n";
