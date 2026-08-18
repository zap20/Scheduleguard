<?php

declare(strict_types=1);

/**
 * Re-assign same-room overlapping meetings to free rooms/times/days (7:00 AM–9:00 PM).
 * Prefers another room same day; if none, moves the later meeting to another day.
 *
 * Usage: php database/reassign_room_overlaps.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Schedule.php';

$term = currentTermWindow();
echo sprintf(
    "Re-assigning same-room overlaps for AY%d / %s …\n",
    $term['academicYear'],
    $term['semester']
);

$result = reassignSameRoomOverlapsForCurrentTerm();

echo sprintf(
    "Pairs found: %d · moved: %d · failed: %d\n\n",
    $result['pairsFound'],
    count($result['moved']),
    count($result['failed'])
);

foreach ($result['moved'] as $row) {
    echo sprintf(
        "MOVED %s (%s %s)\n  from %s\n  to   %s → %s\n",
        $row['uid'],
        $row['subjectCode'],
        $row['day'],
        $row['from'],
        $row['to'],
        $row['status']
    );
}

foreach ($result['failed'] as $row) {
    echo sprintf(
        "FAILED %s (%s %s) from %s\n  %s\n",
        $row['uid'],
        $row['subjectCode'],
        $row['day'],
        $row['from'],
        $row['reason']
    );
}

if ($result['moved'] === [] && $result['failed'] === [] && $result['pairsFound'] === 0) {
    echo "No same-room overlaps to re-assign.\n";
}

exit(count($result['failed']) > 0 ? 1 : 0);
