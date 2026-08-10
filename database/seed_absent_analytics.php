<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/Term.php';
require_once dirname(__DIR__) . '/includes/Attendance.php';

$pdo = db();
$rows = [
    ['att-4', 'sched-db101', 'Absent', 3],
    ['att-5', 'sched-it205', 'Absent', 4],
    ['att-6', 'sched-it205', 'NoSchedule', 5],
];

$chk = $pdo->prepare('SELECT uid FROM attendanceRecord WHERE uid = ?');
$ins = $pdo->prepare(
    'INSERT INTO attendanceRecord (uid, scheduleId, checkerId, status, isOffline, timestamp, syncedAt)
     VALUES (?, ?, ?, ?, 0, DATE_SUB(NOW(), INTERVAL ? DAY), DATE_SUB(NOW(), INTERVAL ? DAY))'
);

foreach ($rows as $r) {
    $chk->execute([$r[0]]);
    if (!$chk->fetchColumn()) {
        $ins->execute([
            $r[0],
            $r[1],
            'user-checker',
            $r[2],
            $r[3],
            $r[3],
        ]);
        echo "inserted {$r[0]}\n";
    } else {
        echo "exists {$r[0]}\n";
    }
}

$t = currentTermWindow();
$top = fetchTopAbsentFaculty($t['academicYear'], $t['semester']);
echo json_encode(['term' => $t, 'top' => $top], JSON_PRETTY_PRINT) . "\n";
