<?php

declare(strict_types=1);

/**
 * Remove ALL data created by the semester schedule import:
 *   - attendanceRecord
 *   - enrollment
 *   - schedule  (all meetings, TBF + assigned)
 *   - classBlockMember
 *   - classBlock
 *   - rooms auto-created by the importer (not seeded)
 *   - faculty stub users auto-created by the importer (email ends in @scheduleguard.test)
 *
 * Seed rooms (room-lab101, room-205, room-lab201, room-301, room-206, room-207)
 * and real user accounts are kept.
 *
 * Run:  php database/clear_import_data.php
 */

require dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Snapshot counts before.
$snap = static function (PDO $pdo, string $table, string $where = ''): int {
    $sql = "SELECT COUNT(*) FROM `{$table}`" . ($where ? " WHERE {$where}" : '');
    return (int) $pdo->query($sql)->fetchColumn();
};

$before = [
    'attendanceRecord'  => $snap($pdo, 'attendanceRecord'),
    'enrollment'        => $snap($pdo, 'enrollment'),
    'schedule'          => $snap($pdo, 'schedule'),
    'classBlockMember'  => $snap($pdo, 'classBlockMember'),
    'classBlock'        => $snap($pdo, 'classBlock'),
    'room'              => $snap($pdo, 'room'),
    'subject'           => $snap($pdo, 'subject'),
    'faculty stub users'=> $snap($pdo, 'user', "email LIKE '%@scheduleguard.test' AND firstName = 'Teacher'"),
];

echo "Before:\n";
foreach ($before as $label => $n) {
    echo "  {$label}: {$n}\n";
}
echo "\n";

$pdo->beginTransaction();
try {
    // 1. Attendance (depends on schedule)
    $pdo->exec('DELETE FROM attendanceRecord');

    // 2. Enrollment (depends on schedule)
    $pdo->exec('DELETE FROM enrollment');

    // 3. Schedules (depends on classBlock, room, user, subject)
    $pdo->exec('DELETE FROM schedule');

    // 4. Class block members + blocks
    $pdo->exec('DELETE FROM classBlockMember');
    $pdo->exec('DELETE FROM classBlock');

    // 5. All rooms (re-created on import)
    $pdo->exec('DELETE FROM room');

    // 6. Subjects (re-created / updated on import)
    $pdo->exec('DELETE FROM subject');

    // 7. Faculty stub users created by the importer
    //    Must delete departmentUser, faculty profile, and user rows.
    $stubUids = $pdo->query(
        "SELECT uid FROM `user` WHERE email LIKE '%@scheduleguard.test' AND firstName = 'Teacher'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if ($stubUids) {
        $in = implode(',', array_map(fn($u) => $pdo->quote($u), $stubUids));
        // Audit log rows that reference stub users (FK ON UPDATE CASCADE but not ON DELETE).
        $pdo->exec("DELETE FROM auditLog WHERE userId IN ({$in})");
        $pdo->exec("DELETE FROM departmentUser WHERE userId IN ({$in})");
        $pdo->exec("DELETE FROM faculty WHERE userId IN ({$in})");
        $pdo->exec("DELETE FROM `user` WHERE uid IN ({$in})");
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$after = [
    'attendanceRecord'  => $snap($pdo, 'attendanceRecord'),
    'enrollment'        => $snap($pdo, 'enrollment'),
    'schedule'          => $snap($pdo, 'schedule'),
    'classBlockMember'  => $snap($pdo, 'classBlockMember'),
    'classBlock'        => $snap($pdo, 'classBlock'),
    'room'              => $snap($pdo, 'room'),
    'subject'           => $snap($pdo, 'subject'),
    'faculty stub users'=> $snap($pdo, 'user', "email LIKE '%@scheduleguard.test' AND firstName = 'Teacher'"),
];

echo "After:\n";
foreach ($after as $label => $n) {
    echo "  {$label}: {$n}\n";
}
echo "\nDone. Re-import the XLSX — subjects will get lecture/lab hours from LEC/LAB meetings.\n";
