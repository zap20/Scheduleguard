<?php

declare(strict_types=1);

/**
 * Wipe all teaching schedule rows (faculty + TBF) and dependent attendance/enrollment.
 * Class blocks are left in place (can be empty afterward).
 */

require dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$beforeSched = (int) $pdo->query('SELECT COUNT(*) FROM schedule')->fetchColumn();
$beforeAtt = (int) $pdo->query('SELECT COUNT(*) FROM attendanceRecord')->fetchColumn();
$beforeEnr = (int) $pdo->query('SELECT COUNT(*) FROM enrollment')->fetchColumn();
$beforeBlocks = (int) $pdo->query('SELECT COUNT(*) FROM classBlock')->fetchColumn();

$pdo->beginTransaction();
try {
    $pdo->exec('DELETE FROM attendanceRecord');
    $pdo->exec('DELETE FROM enrollment');
    $pdo->exec('DELETE FROM schedule');
    $pdo->exec('DELETE FROM classBlockMember');
    $pdo->exec('DELETE FROM classBlock');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$afterSched = (int) $pdo->query('SELECT COUNT(*) FROM schedule')->fetchColumn();
$afterAtt = (int) $pdo->query('SELECT COUNT(*) FROM attendanceRecord')->fetchColumn();
$afterEnr = (int) $pdo->query('SELECT COUNT(*) FROM enrollment')->fetchColumn();
$afterBlocks = (int) $pdo->query('SELECT COUNT(*) FROM classBlock')->fetchColumn();

echo "Deleted schedules: {$beforeSched} → {$afterSched}\n";
echo "Deleted attendance: {$beforeAtt} → {$afterAtt}\n";
echo "Deleted enrollment: {$beforeEnr} → {$afterEnr}\n";
echo "Deleted class blocks: {$beforeBlocks} → {$afterBlocks}\n";
echo "OK\n";
