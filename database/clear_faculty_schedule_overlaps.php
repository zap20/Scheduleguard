<?php

declare(strict_types=1);

/**
 * Clear facultyId on schedules that are in faculty time-conflict pairs,
 * then re-confirm. Room bookings stay; instructor becomes TBF on the later row.
 *
 * Usage: php database/clear_faculty_schedule_overlaps.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Schedule.php';

$term = currentTermWindow();
$stmt = db()->prepare(
    "SELECT uid, facultyId, day, startTime, endTime, blockName, status, createdAt
     FROM schedule
     WHERE academicYear = :ay AND semester = :sem
       AND facultyId IS NOT NULL AND facultyId <> ''
       AND LOWER(status) IN ('confirmed', 'conflict', 'draft')
     ORDER BY facultyId,
              FIELD(day,'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
              startTime ASC, createdAt ASC"
);
$stmt->execute([':ay' => $term['academicYear'], ':sem' => $term['semester']]);
$rows = $stmt->fetchAll();

$byFaculty = [];
foreach ($rows as $row) {
    $byFaculty[(string) $row['facultyId']][] = $row;
}

$clearIds = [];
foreach ($byFaculty as $fid => $list) {
    $n = count($list);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $list[$i];
            $b = $list[$j];
            if (strcasecmp((string) $a['day'], (string) $b['day']) !== 0) {
                continue;
            }
            if (!($a['startTime'] < $b['endTime'] && $b['startTime'] < $a['endTime'])) {
                continue;
            }
            // Keep earlier; clear later.
            $clearIds[(string) $b['uid']] = true;
        }
    }
}

if ($clearIds === []) {
    echo "No faculty time overlaps to clear.\n";
    exit(0);
}

$upd = db()->prepare('UPDATE schedule SET facultyId = NULL WHERE uid = :uid');
foreach (array_keys($clearIds) as $uid) {
    $upd->execute([':uid' => $uid]);
    echo "Cleared faculty (TBF): {$uid}\n";
    try {
        $r = attemptConfirmSchedule($uid);
        echo '  → ' . ($r['schedule']['status'] ?? '?') . "\n";
    } catch (Throwable $e) {
        echo '  refresh skip: ' . $e->getMessage() . "\n";
    }
}

echo 'Done. Cleared ' . count($clearIds) . " overlapping faculty assignment(s).\n";
