<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

echo "Tables:\n";
foreach (db()->query('SHOW TABLES') as $row) {
    echo ' - ' . array_values($row)[0] . "\n";
}

echo "\nUsers:\n";
$stmt = db()->query('SELECT role, email, status FROM `user` ORDER BY role');
foreach ($stmt as $row) {
    echo " - {$row['role']}: {$row['email']} ({$row['status']})\n";
}

$hash = db()->query("SELECT passwordHash FROM `user` WHERE email = 'faculty@scheduleguard.test'")->fetchColumn();
echo "\npassword_verify(Password123!): " . (password_verify('Password123!', (string) $hash) ? 'ok' : 'FAIL') . "\n";

$count = (int) db()->query('SELECT COUNT(*) FROM attendanceRecord')->fetchColumn();
echo "attendanceRecord rows: {$count}\n";
