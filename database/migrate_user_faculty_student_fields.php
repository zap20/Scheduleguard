<?php

declare(strict_types=1);

/**
 * Add faculty employmentType (Regular/PartTime) and student yearLevel + studentType.
 *
 * Usage: php database/migrate_user_faculty_student_fields.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$facultyTable = $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'faculty' AND TABLE_TYPE = 'BASE TABLE'"
)->fetchColumn();
if ((int) $facultyTable > 0) {
    echo "Skipped: user side already normalized (faculty table exists).\n";
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

if (!columnExists($pdo, 'user', 'employmentType')) {
    $pdo->exec(
        "ALTER TABLE `user`
         ADD COLUMN employmentType ENUM('Regular', 'PartTime') NULL
           AFTER status"
    );
    echo "Added user.employmentType\n";
} else {
    echo "user.employmentType already exists\n";
}

if (!columnExists($pdo, 'user', 'yearLevel')) {
    $pdo->exec(
        "ALTER TABLE `user`
         ADD COLUMN yearLevel ENUM('1st Year', '2nd Year', '3rd Year', '4th Year') NULL
           AFTER employmentType"
    );
    echo "Added user.yearLevel\n";
} else {
    echo "user.yearLevel already exists\n";
}

if (!columnExists($pdo, 'user', 'studentType')) {
    $pdo->exec(
        "ALTER TABLE `user`
         ADD COLUMN studentType ENUM('Regular', 'Irregular') NULL
           AFTER yearLevel"
    );
    echo "Added user.studentType\n";
} else {
    echo "user.studentType already exists\n";
}

// Backfill faculty → Regular employment
$pdo->exec(
    "UPDATE `user`
     SET employmentType = 'Regular'
     WHERE role = 'Faculty' AND (employmentType IS NULL OR employmentType = '')"
);
echo "Backfilled Faculty employmentType=Regular\n";

// Backfill students → Regular + distribute year levels if missing
$students = $pdo->query(
    "SELECT uid FROM `user` WHERE role = 'Student' ORDER BY schoolId ASC, createdAt ASC"
)->fetchAll(PDO::FETCH_COLUMN);

$years = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
$i = 0;
$upd = $pdo->prepare(
    "UPDATE `user`
     SET studentType = COALESCE(NULLIF(studentType, ''), 'Regular'),
         yearLevel = COALESCE(NULLIF(yearLevel, ''), :yearLevel)
     WHERE uid = :uid AND role = 'Student'"
);
foreach ($students as $uid) {
    $upd->execute([
        ':uid' => $uid,
        ':yearLevel' => $years[$i % 4],
    ]);
    $i++;
}
echo "Backfilled " . count($students) . " students (Regular + yearLevel)\n";
echo "OK\n";
