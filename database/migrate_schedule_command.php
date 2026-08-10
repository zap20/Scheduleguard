<?php

declare(strict_types=1);

/**
 * Add subject.curriculumYear and schedule block naming columns.
 * Safe to re-run.
 *
 *   php database/migrate_schedule_command.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function columnExistsMig(PDO $pdo, string $table, string $column): bool
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

function indexExistsMig(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND INDEX_NAME = :index'
    );
    $stmt->execute([':table' => $table, ':index' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!columnExistsMig($pdo, 'subject', 'curriculumYear')) {
    $pdo->exec(
        'ALTER TABLE subject
         ADD COLUMN curriculumYear SMALLINT UNSIGNED NOT NULL DEFAULT 2026 AFTER semester'
    );
    echo "Added subject.curriculumYear.\n";
} else {
    echo "subject.curriculumYear already exists.\n";
}

if (indexExistsMig($pdo, 'subject', 'uq_subject_dept_code')) {
    try {
        $pdo->exec('ALTER TABLE subject DROP INDEX uq_subject_dept_code');
        echo "Dropped uq_subject_dept_code.\n";
    } catch (Throwable $e) {
        echo "Could not drop uq_subject_dept_code: " . $e->getMessage() . "\n";
    }
}

if (!indexExistsMig($pdo, 'subject', 'uq_subject_dept_code_year')) {
    try {
        $pdo->exec(
            'ALTER TABLE subject
             ADD UNIQUE KEY uq_subject_dept_code_year (departmentId, code, curriculumYear)'
        );
        echo "Added uq_subject_dept_code_year.\n";
    } catch (Throwable $e) {
        echo "Could not add unique key: " . $e->getMessage() . "\n";
    }
}

if (!indexExistsMig($pdo, 'subject', 'idx_subject_curriculum_year')) {
    $pdo->exec('ALTER TABLE subject ADD KEY idx_subject_curriculum_year (curriculumYear)');
    echo "Added idx_subject_curriculum_year.\n";
}

$scheduleCols = [
    'yearLevel' => "ADD COLUMN yearLevel ENUM('1st Year','2nd Year','3rd Year','4th Year') NULL AFTER semester",
    'blockNumber' => 'ADD COLUMN blockNumber INT UNSIGNED NULL AFTER yearLevel',
    'blockName' => 'ADD COLUMN blockName VARCHAR(100) NULL AFTER blockNumber',
    'studentType' => 'ADD COLUMN studentType VARCHAR(20) NULL AFTER blockName',
];

foreach ($scheduleCols as $col => $ddl) {
    if (!columnExistsMig($pdo, 'schedule', $col)) {
        // Order-dependent: yearLevel first, then others relative to previous.
        if ($col === 'yearLevel') {
            $pdo->exec('ALTER TABLE schedule ' . $ddl);
        } elseif ($col === 'blockNumber') {
            $after = columnExistsMig($pdo, 'schedule', 'yearLevel') ? 'yearLevel' : 'semester';
            $pdo->exec("ALTER TABLE schedule ADD COLUMN blockNumber INT UNSIGNED NULL AFTER {$after}");
        } elseif ($col === 'blockName') {
            $after = columnExistsMig($pdo, 'schedule', 'blockNumber') ? 'blockNumber' : 'semester';
            $pdo->exec("ALTER TABLE schedule ADD COLUMN blockName VARCHAR(100) NULL AFTER {$after}");
        } else {
            $after = columnExistsMig($pdo, 'schedule', 'blockName') ? 'blockName' : 'semester';
            $pdo->exec("ALTER TABLE schedule ADD COLUMN studentType VARCHAR(20) NULL AFTER {$after}");
        }
        echo "Added schedule.{$col}.\n";
    } else {
        echo "schedule.{$col} already exists.\n";
    }
}

if (!indexExistsMig($pdo, 'schedule', 'idx_schedule_block')) {
    $pdo->exec(
        'ALTER TABLE schedule
         ADD KEY idx_schedule_block (departmentId, yearLevel, academicYear, semester, blockNumber)'
    );
    echo "Added idx_schedule_block.\n";
}

echo "Schedule command migration complete.\n";
