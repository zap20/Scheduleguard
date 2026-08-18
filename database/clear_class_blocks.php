<?php

declare(strict_types=1);

/**
 * Remove class blocks and the meetings tied to them (enrollment + attendance
 * on those meetings only). Faculty schedules with no classBlockId are kept.
 *
 *   php database/clear_class_blocks.php
 */

require dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$beforeBlocks = (int) $pdo->query('SELECT COUNT(*) FROM classBlock')->fetchColumn();
$beforeMembers = (int) $pdo->query('SELECT COUNT(*) FROM classBlockMember')->fetchColumn();
$beforeLinked = (int) $pdo->query(
    'SELECT COUNT(*) FROM schedule WHERE classBlockId IS NOT NULL AND classBlockId <> \'\''
)->fetchColumn();
$beforeSched = (int) $pdo->query('SELECT COUNT(*) FROM schedule')->fetchColumn();

$pdo->beginTransaction();
try {
    $pdo->exec(
        'DELETE a FROM attendanceRecord a
         INNER JOIN schedule s ON s.uid = a.scheduleId
         WHERE s.classBlockId IS NOT NULL AND s.classBlockId <> \'\''
    );
    $pdo->exec(
        'DELETE e FROM enrollment e
         INNER JOIN schedule s ON s.uid = e.scheduleId
         WHERE s.classBlockId IS NOT NULL AND s.classBlockId <> \'\''
    );
    $pdo->exec(
        'DELETE FROM schedule WHERE classBlockId IS NOT NULL AND classBlockId <> \'\''
    );
    $pdo->exec('DELETE FROM classBlockMember');
    $pdo->exec('DELETE FROM classBlock');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$afterBlocks = (int) $pdo->query('SELECT COUNT(*) FROM classBlock')->fetchColumn();
$afterMembers = (int) $pdo->query('SELECT COUNT(*) FROM classBlockMember')->fetchColumn();
$afterLinked = (int) $pdo->query(
    'SELECT COUNT(*) FROM schedule WHERE classBlockId IS NOT NULL AND classBlockId <> \'\''
)->fetchColumn();
$afterSched = (int) $pdo->query('SELECT COUNT(*) FROM schedule')->fetchColumn();

echo "Class blocks: {$beforeBlocks} → {$afterBlocks}\n";
echo "Block members: {$beforeMembers} → {$afterMembers}\n";
echo "Block meetings: {$beforeLinked} → {$afterLinked}\n";
echo "All schedules remaining: {$beforeSched} → {$afterSched}\n";
echo "OK\n";
