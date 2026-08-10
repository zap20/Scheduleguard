<?php

declare(strict_types=1);

/**
 * One-shot: for each overlapping faculty load, keep one meeting on the instructor
 * and mark the rest unassigned (TBF). Then re-confirm affected rows.
 *
 * Usage: php database/unassign_faculty_overlaps.php [facultyId]
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/Term.php';
require_once dirname(__DIR__) . '/includes/Schedule.php';

$facultyId = $argv[1] ?? 'user-faculty';
$term = currentTermWindow();

$stmt = db()->prepare(
    "SELECT uid, facultyId, day, startTime, endTime, blockName, status, createdAt
     FROM schedule
     WHERE facultyId = :facultyId
       AND academicYear = :academicYear
       AND semester = :semester
       AND LOWER(status) IN ('confirmed', 'conflict', 'draft')
     ORDER BY FIELD(day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
              startTime ASC,
              createdAt ASC"
);
$stmt->execute([
    ':facultyId' => $facultyId,
    ':academicYear' => $term['academicYear'],
    ':semester' => $term['semester'],
]);
$rows = $stmt->fetchAll();

function overlap(array $a, array $b): bool
{
    if (strcasecmp((string) $a['day'], (string) $b['day']) !== 0) {
        return false;
    }
    return $a['startTime'] < $b['endTime'] && $b['startTime'] < $a['endTime'];
}

/** Prefer keep seed (no block) then earlier created. */
function keepScore(array $row): array
{
    $hasBlock = trim((string) ($row['blockName'] ?? '')) !== '';
    return [
        $hasBlock ? 1 : 0,
        (string) $row['createdAt'],
        (string) $row['uid'],
    ];
}

$unassignIds = [];
$n = count($rows);
for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        if (!overlap($rows[$i], $rows[$j])) {
            continue;
        }
        $si = keepScore($rows[$i]);
        $sj = keepScore($rows[$j]);
        // Higher score = unassign (prefer keeping non-block / earlier)
        if ($si > $sj) {
            $unassignIds[$rows[$i]['uid']] = true;
        } else {
            $unassignIds[$rows[$j]['uid']] = true;
        }
    }
}

if ($unassignIds === []) {
    echo "No overlapping faculty loads for {$facultyId}.\n";
    exit(0);
}

$upd = db()->prepare(
    "UPDATE schedule
     SET facultyId = NULL
     WHERE uid = :uid"
);

$affected = [];
foreach (array_keys($unassignIds) as $uid) {
    $upd->execute([':uid' => $uid]);
    $affected[] = $uid;
    echo "Unassigned (TBF): {$uid}\n";
}

// Re-run conflict check / confirm on kept + unassigned rows for this faculty term.
$allIds = array_map(static fn (array $r): string => (string) $r['uid'], $rows);
foreach ($allIds as $uid) {
    try {
        $result = attemptConfirmSchedule($uid);
        $status = $result['schedule']['status'] ?? '?';
        $label = !empty($result['confirmed']) ? 'confirmed' : $status;
        echo "Refresh {$uid} → {$label}\n";
    } catch (Throwable $e) {
        echo "Skip {$uid}: {$e->getMessage()}\n";
    }
}

echo 'Done. Unassigned ' . count($affected) . " overlapping meeting(s).\n";
