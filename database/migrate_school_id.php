<?php

declare(strict_types=1);

/**
 * Add user.schoolId (format YYYY-NNN), unique per role so Student and Faculty
 * may share the same ID without colliding.
 *   php database/migrate_school_id.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();

$cols = [];
foreach ($pdo->query('SHOW COLUMNS FROM `user`') as $col) {
    $cols[strtolower((string) $col['Field'])] = true;
}

if (!isset($cols['schoolid'])) {
    $pdo->exec(
        "ALTER TABLE `user`
         ADD COLUMN schoolId VARCHAR(20) NULL AFTER email"
    );
    echo "Added schoolId column.\n";
} else {
    echo "schoolId column already exists.\n";
}

$known = [
    'user-checker' => '2020-101',
    'user-faculty' => '2020-001', // same as student — allowed across roles
    'user-dean' => '2020-201',
    'user-hr' => '2020-301',
    'user-ph' => '2020-401',
    'user-student' => '2020-001', // same as faculty — allowed across roles
];

$update = $pdo->prepare('UPDATE `user` SET schoolId = :schoolId WHERE uid = :uid');
foreach ($known as $uid => $schoolId) {
    $update->execute([':schoolId' => $schoolId, ':uid' => $uid]);
}
echo "Backfilled known seed users.\n";

// Any remaining null schoolIds get a placeholder derived from role + uid suffix.
$remaining = $pdo->query(
    "SELECT uid, role FROM `user` WHERE schoolId IS NULL OR schoolId = ''"
)->fetchAll();
$year = (int) date('Y');
$seq = 500;
foreach ($remaining as $row) {
    $schoolId = sprintf('%04d-%03d', $year, $seq++);
    $update->execute([':schoolId' => $schoolId, ':uid' => $row['uid']]);
    echo "Backfilled {$row['uid']} ({$row['role']}) => {$schoolId}\n";
}

$pdo->exec('ALTER TABLE `user` MODIFY COLUMN schoolId VARCHAR(20) NOT NULL');

$indexExists = false;
foreach ($pdo->query('SHOW INDEX FROM `user`') as $idx) {
    if (strcasecmp((string) $idx['Key_name'], 'uq_user_school_role') === 0) {
        $indexExists = true;
        break;
    }
}
if (!$indexExists) {
    $pdo->exec(
        'ALTER TABLE `user`
         ADD UNIQUE KEY uq_user_school_role (schoolId, role)'
    );
    echo "Created unique key uq_user_school_role (schoolId, role).\n";
} else {
    echo "uq_user_school_role already exists.\n";
}

echo "Done.\n";
