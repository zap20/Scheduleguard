<?php

declare(strict_types=1);

/**
 * Focus ScheduleGuard on CICT only:
 * - Keep dept-cict users
 * - Move Checker + HR onto CICT (needed campus roles)
 * - Delete every other user and orphaned non-CICT departments/data
 *
 * Usage: php database/focus_cict.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$cictId = 'dept-cict';

$pdo->beginTransaction();
try {
    // Ensure CICT department exists
    $exists = $pdo->prepare('SELECT uid FROM department WHERE uid = ? OR name = ? LIMIT 1');
    $exists->execute([$cictId, 'CICT']);
    $found = $exists->fetchColumn();
    if (!$found) {
        $pdo->prepare('INSERT INTO department (uid, name, createdAt) VALUES (?, \'CICT\', NOW())')
            ->execute([$cictId]);
        echo "Created department {$cictId}\n";
    } else {
        $cictId = (string) $found;
        if ($cictId !== 'dept-cict') {
            // normalize name
            $pdo->prepare('UPDATE department SET name = \'CICT\' WHERE uid = ?')->execute([$cictId]);
        }
        echo "Using CICT department {$cictId}\n";
    }

    // Move institutional roles onto CICT
    foreach (['user-checker', 'user-hr'] as $uid) {
        $stmt = $pdo->prepare(
            'INSERT INTO departmentUser (userId, departmentId, createdAt)
             VALUES (?, ?, NOW())
             ON DUPLICATE KEY UPDATE departmentId = VALUES(departmentId)'
        );
        $stmt->execute([$uid, $cictId]);
        if ($stmt->rowCount() > 0) {
            echo "Moved {$uid} → CICT\n";
        }
    }

    // Collect users to remove (not CICT)
    $toRemove = $pdo->prepare(
        'SELECT u.uid, u.email, u.role
         FROM `user` u
         LEFT JOIN departmentUser du ON du.userId = u.uid
         WHERE du.departmentId IS NULL OR du.departmentId <> ?
         ORDER BY u.email'
    );
    $toRemove->execute([$cictId]);
    $removeIds = [];
    foreach ($toRemove as $row) {
        $removeIds[] = (string) $row['uid'];
        echo "Will remove {$row['role']} {$row['email']}\n";
    }

    if ($removeIds !== []) {
        $ph = implode(',', array_fill(0, count($removeIds), '?'));

        // Dependent rows referencing those users
        $pdo->prepare("DELETE FROM auditLog WHERE userId IN ($ph) OR relatedRecordId IN ($ph)")
            ->execute(array_merge($removeIds, $removeIds));

        $pdo->prepare(
            "DELETE FROM enrollment WHERE studentId IN ($ph) OR assignedBy IN ($ph)"
        )->execute(array_merge($removeIds, $removeIds));

        $pdo->prepare(
            "DELETE cbm FROM classBlockMember cbm
             INNER JOIN classBlock cb ON cb.uid = cbm.classBlockId
             WHERE cbm.studentId IN ($ph) OR cbm.assignedBy IN ($ph) OR cb.createdBy IN ($ph)"
        )->execute(array_merge($removeIds, $removeIds, $removeIds));

        $pdo->prepare("DELETE FROM classBlock WHERE createdBy IN ($ph)")->execute($removeIds);

        $pdo->prepare(
            "DELETE FROM block WHERE studentId IN ($ph) OR issuedBy IN ($ph)"
        )->execute(array_merge($removeIds, $removeIds));

        // Attendance on schedules owned by removed faculty, or checked by removed checkers
        $pdo->prepare(
            "DELETE ar FROM attendanceRecord ar
             INNER JOIN schedule s ON s.uid = ar.scheduleId
             WHERE s.facultyId IN ($ph) OR ar.checkerId IN ($ph)"
        )->execute(array_merge($removeIds, $removeIds));

        $pdo->prepare("DELETE FROM schedule WHERE facultyId IN ($ph) OR createdBy IN ($ph)")
            ->execute(array_merge($removeIds, $removeIds));

        $pdo->prepare("DELETE FROM `user` WHERE uid IN ($ph)")->execute($removeIds);
        echo 'Removed users: ' . count($removeIds) . "\n";
    }

    // Move remaining curriculum/schedules onto CICT
    $pdo->prepare('UPDATE subject SET departmentId = ? WHERE departmentId <> ?')
        ->execute([$cictId, $cictId]);
    $pdo->prepare('UPDATE schedule SET departmentId = ? WHERE departmentId <> ?')
        ->execute([$cictId, $cictId]);
    $pdo->prepare('UPDATE classBlock SET departmentId = ? WHERE departmentId <> ?')
        ->execute([$cictId, $cictId]);
    $pdo->prepare('UPDATE block SET departmentId = ? WHERE departmentId <> ?')
        ->execute([$cictId, $cictId]);

    // Drop empty non-CICT departments
    $other = $pdo->query(
        "SELECT uid, name FROM department WHERE uid <> " . $pdo->quote($cictId)
    )->fetchAll(PDO::FETCH_ASSOC);
    foreach ($other as $dept) {
        $pdo->prepare('DELETE FROM department WHERE uid = ?')->execute([$dept['uid']]);
        echo "Removed department {$dept['name']}\n";
    }

    $pdo->commit();

    echo "\n=== Remaining users ===\n";
    foreach ($pdo->query(
        'SELECT u.role, u.email, d.name AS dept
         FROM `user` u
         LEFT JOIN departmentUser du ON du.userId = u.uid
         LEFT JOIN department d ON d.uid = du.departmentId
         ORDER BY u.role, u.email'
    ) as $u) {
        echo "{$u['role']}\t{$u['dept']}\t{$u['email']}\n";
    }
    echo "\nOK: CICT-only focus applied.\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
