<?php

declare(strict_types=1);

/**
 * Report same-room overlapping meetings for the current term (read-only).
 *
 * Usage: php database/report_room_overlaps.php
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/Term.php';

$term = currentTermWindow();

$stmt = db()->prepare(
    "SELECT s.uid,
            s.roomId,
            s.day,
            s.startTime,
            s.endTime,
            s.status,
            s.blockName,
            sub.code AS subjectCode,
            r.building,
            r.name AS roomName
     FROM schedule s
     INNER JOIN subject sub ON sub.uid = s.subjectId
     INNER JOIN room r ON r.uid = s.roomId
     WHERE s.academicYear = :academicYear
       AND s.semester = :semester
       AND LOWER(s.status) IN ('confirmed', 'conflict', 'draft')
       AND s.roomId IS NOT NULL
       AND s.roomId <> ''
     ORDER BY r.building, r.name,
              FIELD(s.day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
              s.startTime ASC,
              s.createdAt ASC"
);
$stmt->execute([
    ':academicYear' => $term['academicYear'],
    ':semester' => $term['semester'],
]);
$rows = $stmt->fetchAll();

function roomTimesOverlap(array $a, array $b): bool
{
    if (strcasecmp((string) $a['day'], (string) $b['day']) !== 0) {
        return false;
    }
    if ((string) $a['roomId'] !== (string) $b['roomId']) {
        return false;
    }
    return $a['startTime'] < $b['endTime'] && $b['startTime'] < $a['endTime'];
}

$pairs = [];
$n = count($rows);
for ($i = 0; $i < $n; $i++) {
    for ($j = $i + 1; $j < $n; $j++) {
        if (!roomTimesOverlap($rows[$i], $rows[$j])) {
            continue;
        }
        $pairs[] = [$rows[$i], $rows[$j]];
    }
}

echo sprintf(
    "Term AY%d / %s — %d schedule row(s), %d same-room overlap pair(s).\n\n",
    $term['academicYear'],
    $term['semester'],
    $n,
    count($pairs)
);

if ($pairs === []) {
    echo "No same-room overlaps.\n";
    exit(0);
}

foreach ($pairs as $idx => $pair) {
    [$a, $b] = $pair;
    $room = trim((string) $a['building'] . ' / ' . (string) $a['roomName']);
    echo sprintf(
        "%d) %s · %s\n   - %s %s–%s [%s] %s %s\n   - %s %s–%s [%s] %s %s\n\n",
        $idx + 1,
        $room,
        (string) $a['day'],
        (string) $a['subjectCode'],
        substr((string) $a['startTime'], 0, 5),
        substr((string) $a['endTime'], 0, 5),
        (string) $a['status'],
        (string) ($a['blockName'] ?? ''),
        (string) $a['uid'],
        (string) $b['subjectCode'],
        substr((string) $b['startTime'], 0, 5),
        substr((string) $b['endTime'], 0, 5),
        (string) $b['status'],
        (string) ($b['blockName'] ?? ''),
        (string) $b['uid']
    );
}

echo "Read-only report. Fix by editing/removing conflicting rows or regenerating with free rooms.\n";
exit(0);
