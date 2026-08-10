<?php

declare(strict_types=1);

/**
 * Ensure Rooms 206 & 207 exist and move unassigned (TBF) conflict schedules
 * onto a free overflow lecture room.
 *
 * Usage: php database/migrate_add_room_206.php
 */

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/Term.php';
require_once dirname(__DIR__) . '/includes/Schedule.php';

$pdo = db();

$overflowRooms = [
    ['uid' => 'room-206', 'name' => 'Room 206'],
    ['uid' => 'room-207', 'name' => 'Room 207'],
];

foreach ($overflowRooms as $room) {
    $exists = $pdo->prepare('SELECT uid FROM room WHERE uid = :uid LIMIT 1');
    $exists->execute([':uid' => $room['uid']]);
    if (!$exists->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO room (uid, name, building, capacity, roomType, createdAt)
             VALUES (:uid, :name, 'Annex', 40, 'LECTURE', NOW())"
        )->execute([':uid' => $room['uid'], ':name' => $room['name']]);
        echo "Created {$room['uid']} (Annex / {$room['name']}).\n";
    } else {
        echo "{$room['uid']} already exists.\n";
    }
}

$term = currentTermWindow();
$stmt = $pdo->prepare(
    "SELECT uid, day, startTime, endTime, roomId, status, blockName
     FROM schedule
     WHERE (facultyId IS NULL OR facultyId = '')
       AND academicYear = :ay
       AND semester = :sem
       AND LOWER(status) = 'conflict'
     ORDER BY day, startTime"
);
$stmt->execute([
    ':ay' => $term['academicYear'],
    ':sem' => $term['semester'],
]);
$tbfRows = $stmt->fetchAll();

$moved = 0;
foreach ($tbfRows as $row) {
    $start = normalizeScheduleTime((string) $row['startTime']);
    $end = normalizeScheduleTime((string) $row['endTime']);
    if ($start === null || $end === null) {
        continue;
    }

    $target = null;
    foreach ($overflowRooms as $room) {
        if ((string) $row['roomId'] === $room['uid']) {
            continue;
        }
        try {
            assertRoomAvailableAt(
                $room['uid'],
                (string) $row['day'],
                $start,
                $end,
                (int) $term['academicYear'],
                (string) $term['semester'],
                (string) $row['uid']
            );
            $target = $room['uid'];
            break;
        } catch (InvalidArgumentException $e) {
            continue;
        }
    }

    if ($target === null) {
        echo 'Skip ' . ($row['blockName'] ?: $row['uid']) . " (no free overflow room).\n";
        continue;
    }

    $upd = $pdo->prepare('UPDATE schedule SET roomId = :roomId WHERE uid = :uid');
    $upd->execute([':roomId' => $target, ':uid' => $row['uid']]);
    $moved++;
    echo 'Moved TBF ' . ($row['blockName'] ?: $row['uid']) . " → {$target}\n";

    try {
        $result = attemptConfirmSchedule((string) $row['uid']);
        echo '  → ' . ($result['schedule']['status'] ?? '?') . "\n";
    } catch (Throwable $e) {
        echo '  refresh skip: ' . $e->getMessage() . "\n";
    }
}

echo "Done. Relocated {$moved} unassigned schedule(s).\n";
