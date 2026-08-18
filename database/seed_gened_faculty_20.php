<?php

declare(strict_types=1);

/**
 * Add 20 GenEd Faculty users (faculty02–faculty21). Skips rows that already exist.
 * Password for all: Password123!
 *
 * Usage: php database/seed_gened_faculty_20.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$dept = $pdo->query(
    "SELECT uid, name FROM department
     WHERE uid = 'dept-gened'
        OR name IN ('GenEd', 'GENED', 'GENed', 'General Education')
     ORDER BY CASE WHEN uid = 'dept-gened' THEN 0 ELSE 1 END
     LIMIT 1"
)->fetch();

if (!$dept) {
    fwrite(STDERR, "GenEd department not found. Run database/seed_gened_users.php or seed.sql first.\n");
    exit(1);
}

$departmentId = (string) $dept['uid'];
$deptName = (string) $dept['name'];
$passwordHash = '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.';

$firstNames = [
    'Ana', 'Ben', 'Clara', 'Diego', 'Eliza', 'Felix', 'Gina', 'Hector', 'Ivy', 'Jorge',
    'Karen', 'Leo', 'Maya', 'Nico', 'Olga', 'Paolo', 'Queenie', 'Rafael', 'Sonia', 'Tomas',
];

$lastNames = [
    'Alvarez', 'Bautista', 'Castro', 'Domingo', 'Evangelista', 'Francisco', 'Gutierrez',
    'Herrera', 'Ibarra', 'Jacinto', 'Kintanar', 'Lorenzo', 'Mercado', 'Nunez', 'Ocampo',
    'Padilla', 'Quintos', 'Romero', 'Salcedo', 'Tolentino',
];

$employmentTypes = ['Regular', 'Regular', 'Regular', 'PartTime'];

$startIndex = 2;
$count = 20;
$endIndex = $startIndex + $count - 1;

$findUser = $pdo->prepare(
    "SELECT uid FROM `user`
     WHERE uid = :uid OR email = :email
        OR (schoolId = :schoolId AND role = 'Faculty')
     LIMIT 1"
);
$insertUser = $pdo->prepare(
    "INSERT INTO `user`
        (uid, firstName, lastName, email, schoolId, role, phoneNumber, status, passwordHash, createdAt)
     VALUES
        (:uid, :firstName, :lastName, :email, :schoolId, 'Faculty', :phoneNumber, 'Active', :passwordHash, NOW())"
);
$insertDept = $pdo->prepare(
    'INSERT IGNORE INTO departmentUser (userId, departmentId, createdAt)
     VALUES (:uid, :departmentId, NOW())'
);
$insertFaculty = $pdo->prepare(
    'INSERT IGNORE INTO faculty (userId, employmentType, createdAt)
     VALUES (:uid, :employmentType, NOW())'
);

$created = 0;
$skipped = 0;

$pdo->beginTransaction();
try {
    echo "Department: {$deptName} ({$departmentId})\n";

    for ($i = $startIndex; $i <= $endIndex; $i++) {
        $n = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $uid = 'user-gened-fac-' . $n;
        $email = 'gened.faculty' . $n . '@scheduleguard.test';
        $schoolId = '2020-6' . str_pad((string) (1 + $i), 2, '0', STR_PAD_LEFT);
        $phoneNumber = '090100006' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);

        $findUser->execute([
            ':uid' => $uid,
            ':email' => $email,
            ':schoolId' => $schoolId,
        ]);
        if ($findUser->fetchColumn()) {
            $skipped++;
            echo "Exists: {$email}\n";
            continue;
        }

        $idx = $i - $startIndex;
        $firstName = $firstNames[$idx % count($firstNames)];
        $lastName = $lastNames[$idx % count($lastNames)];
        $empType = $employmentTypes[$idx % count($employmentTypes)];

        $insertUser->execute([
            ':uid' => $uid,
            ':firstName' => $firstName,
            ':lastName' => $lastName,
            ':email' => $email,
            ':schoolId' => $schoolId,
            ':phoneNumber' => $phoneNumber,
            ':passwordHash' => $passwordHash,
        ]);
        $insertDept->execute([
            ':uid' => $uid,
            ':departmentId' => $departmentId,
        ]);
        $insertFaculty->execute([
            ':uid' => $uid,
            ':employmentType' => $empType,
        ]);
        $created++;
        echo "Created: {$firstName} {$lastName} <{$email}> ({$empType})\n";
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$stmt = $pdo->prepare(
    "SELECT COUNT(*) FROM departmentUser du
     INNER JOIN `user` u ON u.uid = du.userId
     WHERE du.departmentId = :departmentId AND u.role = 'Faculty'"
);
$stmt->execute([':departmentId' => $departmentId]);
$genedFaculty = (int) $stmt->fetchColumn();

echo "Created: {$created}, Skipped: {$skipped}\n";
echo "GenEd faculty now: {$genedFaculty}\n";
echo "Password: Password123!\n";
echo "Emails: gened.faculty02@scheduleguard.test through gened.faculty21@scheduleguard.test\n";
