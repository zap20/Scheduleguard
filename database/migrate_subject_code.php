<?php

declare(strict_types=1);

/**
 * Add schedule.subjectCode and backfill existing rows.
 *   php database/migrate_subject_code.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();

$hasColumn = false;
foreach ($pdo->query('SHOW COLUMNS FROM schedule') as $col) {
    if (strcasecmp((string) $col['Field'], 'subjectCode') === 0) {
        $hasColumn = true;
        break;
    }
}

if (!$hasColumn) {
    $pdo->exec(
        'ALTER TABLE schedule
         ADD COLUMN subjectCode VARCHAR(50) NOT NULL DEFAULT \'\' AFTER createdBy'
    );
    echo "Added subjectCode column.\n";
} else {
    echo "subjectCode column already exists.\n";
}

$known = [
    'sched-db101' => 'DB101',
    'sched-it205' => 'IT205',
];

foreach ($known as $uid => $code) {
    $stmt = $pdo->prepare('UPDATE schedule SET subjectCode = :code WHERE uid = :uid');
    $stmt->execute([':code' => $code, ':uid' => $uid]);
}

// Any remaining empty codes: derive a short uppercase token from subjectName.
$rows = $pdo->query(
    "SELECT uid, subjectName FROM schedule WHERE subjectCode = '' OR subjectCode IS NULL"
)->fetchAll();

$update = $pdo->prepare('UPDATE schedule SET subjectCode = :code WHERE uid = :uid');
foreach ($rows as $row) {
    $name = (string) $row['subjectName'];
    if (preg_match('/\b([A-Z]{2,5}\d{2,4})\b/', strtoupper($name), $m)) {
        $code = $m[1];
    } else {
        $words = preg_split('/\s+/', $name) ?: [];
        $initials = '';
        foreach ($words as $word) {
            if ($word !== '') {
                $initials .= strtoupper(substr($word, 0, 1));
            }
            if (strlen($initials) >= 4) {
                break;
            }
        }
        $code = ($initials !== '' ? $initials : 'SUBJ') . substr((string) $row['uid'], 0, 4);
    }
    $update->execute([':code' => $code, ':uid' => $row['uid']]);
    echo "Backfilled {$row['uid']} => {$code}\n";
}

echo "Done.\n";
