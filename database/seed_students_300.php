<?php

declare(strict_types=1);

/**
 * Seed 300 Active CICT Student users (regular cohort).
 * Password for all: Password123!
 *
 * Usage: php database/seed_students_300.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$departmentId = 'dept-cict';
$dept = $pdo->prepare('SELECT uid FROM department WHERE uid = ? OR name = ? LIMIT 1');
$dept->execute([$departmentId, 'CICT']);
$found = $dept->fetchColumn();
if (!$found) {
    fwrite(STDERR, "CICT department not found. Run seed.sql / focus_cict.php first.\n");
    exit(1);
}
$departmentId = (string) $found;

// Same bcrypt as database/seed.sql (Password123!)
$passwordHash = '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.';

$firstNames = [
    'Alex', 'Blair', 'Casey', 'Dana', 'Eden', 'Flynn', 'Gray', 'Harper', 'Indigo', 'Jordan',
    'Kai', 'Logan', 'Morgan', 'Noah', 'Oakley', 'Parker', 'Quinn', 'Reese', 'Sage', 'Taylor',
    'Ava', 'Ben', 'Cara', 'Diego', 'Elena', 'Felix', 'Gina', 'Hugo', 'Ivy', 'Jake',
    'Kara', 'Leo', 'Mia', 'Nina', 'Omar', 'Pia', 'Rico', 'Sara', 'Tess', 'Uma',
];
$lastNames = [
    'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Lopez',
    'Gonzales', 'Rivera', 'Aquino', 'Diaz', 'Castillo', 'Fernandez', 'Morales', 'Navarro', 'Perez', 'Salazar',
    'Domingo', 'Villanueva', 'Castro', 'Jimenez', 'Pascual', 'Del Rosario', 'Aguilar', 'Lim', 'Tan', 'Sy',
];

$target = 300;
$existing = (int) $pdo->query(
    "SELECT COUNT(*) FROM userProfile WHERE role = 'Student' AND departmentId = " . $pdo->quote($departmentId)
)->fetchColumn();

echo "Existing CICT students: {$existing}\n";

$insertUser = $pdo->prepare(
    'INSERT INTO `user`
        (uid, firstName, lastName, email, schoolId, role, phoneNumber, status, passwordHash, createdAt)
     VALUES
        (:uid, :firstName, :lastName, :email, :schoolId, \'Student\', :phone, \'Active\', :passwordHash, NOW())'
);
$insertDept = $pdo->prepare(
    'INSERT INTO departmentUser (userId, departmentId, createdAt)
     VALUES (:uid, :departmentId, NOW())'
);
$insertStudent = $pdo->prepare(
    'INSERT INTO student (userId, yearLevel, studentType, enrollmentEvalStatus, createdAt)
     VALUES (:uid, :yearLevel, \'Regular\', \'Pending\', NOW())'
);

$created = 0;
$skipped = 0;
$year = (int) date('Y');
$years = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

$pdo->beginTransaction();
try {
    for ($i = 1; $i <= $target; $i++) {
        $n = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        $schoolId = sprintf('%d-S%s', $year, $n); // e.g. 2026-S001
        $email = sprintf('student.regular.%s@scheduleguard.test', $n);
        $uid = sprintf('user-cict-stu-%s', $n);

        $exists = $pdo->prepare(
            'SELECT uid FROM `user`
             WHERE uid = :uid OR email = :email OR (schoolId = :schoolId AND role = \'Student\')
             LIMIT 1'
        );
        $exists->execute([
            ':uid' => $uid,
            ':email' => $email,
            ':schoolId' => $schoolId,
        ]);
        if ($exists->fetchColumn()) {
            $skipped++;
            continue;
        }

        $first = $firstNames[($i - 1) % count($firstNames)];
        $last = $lastNames[(int) floor(($i - 1) / count($firstNames)) % count($lastNames)];
        if ($i > count($firstNames) * count($lastNames)) {
            $first = $first . $n;
        }

        $insertUser->execute([
            ':uid' => $uid,
            ':firstName' => $first,
            ':lastName' => $last,
            ':email' => $email,
            ':schoolId' => $schoolId,
            ':phone' => sprintf('0917%07d', 1000000 + $i),
            ':passwordHash' => $passwordHash,
        ]);
        $insertDept->execute([
            ':uid' => $uid,
            ':departmentId' => $departmentId,
        ]);
        $insertStudent->execute([
            ':uid' => $uid,
            ':yearLevel' => $years[($i - 1) % 4],
        ]);
        $created++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$total = (int) $pdo->query(
    "SELECT COUNT(*) FROM userProfile WHERE role = 'Student' AND departmentId = " . $pdo->quote($departmentId)
)->fetchColumn();

echo "Created: {$created}\n";
echo "Skipped (already present): {$skipped}\n";
echo "Total CICT students now: {$total}\n";
echo "Password for seeded students: Password123!\n";
echo "OK\n";
