<?php

declare(strict_types=1);

/**
 * Seed 2000 Active CICT Student users.
 * Skips any that already exist. Password for all: Password123!
 *
 * Usage: php database/seed_students_2000.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$departmentId = 'dept-cict';
$dept = $pdo->prepare('SELECT uid FROM department WHERE uid = ? OR name = ? LIMIT 1');
$dept->execute([$departmentId, 'CICT']);
$found = $dept->fetchColumn();
if (!$found) {
    fwrite(STDERR, "CICT department not found. Run seed.sql first.\n");
    exit(1);
}
$departmentId = (string) $found;

$passwordHash = '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.';

$firstNames = [
    'Alex', 'Blair', 'Casey', 'Dana', 'Eden', 'Flynn', 'Gray', 'Harper', 'Indigo', 'Jordan',
    'Kai', 'Logan', 'Morgan', 'Noah', 'Oakley', 'Parker', 'Quinn', 'Reese', 'Sage', 'Taylor',
    'Ava', 'Ben', 'Cara', 'Diego', 'Elena', 'Felix', 'Gina', 'Hugo', 'Ivy', 'Jake',
    'Kara', 'Leo', 'Mia', 'Nina', 'Omar', 'Pia', 'Rico', 'Sara', 'Tess', 'Uma',
    'Vince', 'Wren', 'Xian', 'Yara', 'Zoe', 'Aaron', 'Bea', 'Cole', 'Dawn', 'Earl',
];
$lastNames = [
    'Santos', 'Reyes', 'Cruz', 'Bautista', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Ramos', 'Lopez',
    'Gonzales', 'Rivera', 'Aquino', 'Diaz', 'Castillo', 'Fernandez', 'Morales', 'Navarro', 'Perez', 'Salazar',
    'Domingo', 'Villanueva', 'Castro', 'Jimenez', 'Pascual', 'Del Rosario', 'Aguilar', 'Lim', 'Tan', 'Sy',
    'Ong', 'Chua', 'Manalo', 'David', 'Soriano', 'Santiago', 'Tolentino', 'Alcantara', 'Hernandez', 'Macaraeg',
    'Buenaventura', 'Concepcion', 'Enriquez', 'Galang', 'Ignacio', 'Lacson', 'Magno', 'Natividad', 'Ocampo', 'Ponce',
];

$yearLevels = ['1st Year', '1st Year', '2nd Year', '2nd Year', '3rd Year', '3rd Year', '4th Year', '4th Year'];
$studentTypes = ['Regular', 'Regular', 'Regular', 'Irregular'];

$target = 2000;
$existing = (int) $pdo->query("SELECT COUNT(*) FROM `user` WHERE role = 'Student'")->fetchColumn();
echo "Existing students: {$existing}\n";

$insertUser = $pdo->prepare(
    "INSERT INTO `user`
        (uid, firstName, lastName, email, schoolId, role, phoneNumber, status, passwordHash, createdAt)
     VALUES
        (:uid, :firstName, :lastName, :email, :schoolId, 'Student', NULL, 'Active', :passwordHash, NOW())"
);
$insertDept = $pdo->prepare(
    'INSERT INTO departmentUser (userId, departmentId, createdAt)
     VALUES (:uid, :departmentId, NOW())'
);
$insertStudent = $pdo->prepare(
    "INSERT INTO student (userId, yearLevel, studentType, enrollmentEvalStatus, createdAt)
     VALUES (:uid, :yearLevel, :studentType, 'Pending', NOW())"
);

$created = 0;
$skipped = 0;
$year = (int) date('Y');

// Insert in batches of 100 for performance
$batchSize = 100;
$pdo->beginTransaction();
try {
    for ($i = 1; $i <= $target; $i++) {
        $n        = str_pad((string) $i, 4, '0', STR_PAD_LEFT);
        $uid      = sprintf('user-cict-stu-%s', $n);
        $email    = sprintf('student.cict.%s@scheduleguard.test', $n);
        $schoolId = sprintf('%d-S%s', $year, $n);

        $exists = $pdo->prepare(
            "SELECT uid FROM `user`
             WHERE uid = :uid OR email = :email
                OR (schoolId = :schoolId AND role = 'Student')
             LIMIT 1"
        );
        $exists->execute([':uid' => $uid, ':email' => $email, ':schoolId' => $schoolId]);
        if ($exists->fetchColumn()) {
            $skipped++;
            continue;
        }

        $firstName   = $firstNames[($i - 1) % count($firstNames)];
        $lastName    = $lastNames[($i - 1) % count($lastNames)];
        $yearLevel   = $yearLevels[($i - 1) % count($yearLevels)];
        $studentType = $studentTypes[($i - 1) % count($studentTypes)];

        $insertUser->execute([
            ':uid'          => $uid,
            ':firstName'    => $firstName,
            ':lastName'     => $lastName,
            ':email'        => $email,
            ':schoolId'     => $schoolId,
            ':passwordHash' => $passwordHash,
        ]);
        $insertDept->execute([':uid' => $uid, ':departmentId' => $departmentId]);
        $insertStudent->execute([
            ':uid'         => $uid,
            ':yearLevel'   => $yearLevel,
            ':studentType' => $studentType,
        ]);
        $created++;

        if ($created % $batchSize === 0) {
            $pdo->commit();
            $pdo->beginTransaction();
            echo "  {$created} created...\n";
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$total = (int) $pdo->query("SELECT COUNT(*) FROM `user` WHERE role = 'Student'")->fetchColumn();
echo "Created: {$created}, Skipped (already exist): {$skipped}\n";
echo "Total students now: {$total}\n";
echo "Done. Password for all: Password123!\n";
