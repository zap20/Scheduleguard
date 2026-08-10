<?php

declare(strict_types=1);

/**
 * Add schedule.academicYear + schedule.semester, expand attendance Absent status.
 *   php database/migrate_semester.php
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/env.php';

$pdo = db();

$year = (int) env('CURRENT_ACADEMIC_YEAR', (string) date('Y'));
$semester = trim((string) env('CURRENT_SEMESTER', '1'));
if (!in_array($semester, ['1', '2', 'Summer'], true)) {
    $semester = '1';
}

$cols = [];
foreach ($pdo->query('SHOW COLUMNS FROM schedule') as $col) {
    $cols[strtolower((string) $col['Field'])] = true;
}

if (!isset($cols['academicyear'])) {
    $pdo->exec(
        'ALTER TABLE schedule
         ADD COLUMN academicYear SMALLINT UNSIGNED NOT NULL DEFAULT ' . $year . ' AFTER endTime'
    );
    echo "Added academicYear column.\n";
} else {
    echo "academicYear column already exists.\n";
}

if (!isset($cols['semester'])) {
    $pdo->exec(
        "ALTER TABLE schedule
         ADD COLUMN semester VARCHAR(10) NOT NULL DEFAULT '{$semester}' AFTER academicYear"
    );
    echo "Added semester column.\n";
} else {
    echo "semester column already exists.\n";
}

$pdo->exec(
    'UPDATE schedule
     SET academicYear = ' . $year . ",
         semester = " . $pdo->quote($semester) . '
     WHERE academicYear IS NULL OR academicYear = 0 OR semester IS NULL OR semester = \'\''
);
echo "Backfilled schedule term fields to AY{$year} Sem {$semester}.\n";

try {
    $pdo->exec(
        "ALTER TABLE attendanceRecord
         MODIFY COLUMN status ENUM('Present','Late','WrongRoom','NoSchedule','Absent') NOT NULL"
    );
    echo "Expanded attendance status enum to include Absent.\n";
} catch (Throwable $e) {
    echo "Attendance enum update skipped/failed: " . $e->getMessage() . "\n";
}

$indexExists = false;
foreach ($pdo->query('SHOW INDEX FROM schedule') as $idx) {
    if (strcasecmp((string) $idx['Key_name'], 'idx_schedule_term') === 0) {
        $indexExists = true;
        break;
    }
}
if (!$indexExists) {
    $pdo->exec('CREATE INDEX idx_schedule_term ON schedule (academicYear, semester)');
    echo "Created idx_schedule_term.\n";
} else {
    echo "idx_schedule_term already exists.\n";
}

echo "Done.\n";
