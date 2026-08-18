<?php

declare(strict_types=1);

/**
 * Seed 50 Active CICT Faculty users (including the 2 already seeded).
 * Skips any that already exist. Password for all: Password123!
 *
 * Usage: php database/seed_faculty_50.php
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
    'Jose', 'Maria', 'Juan', 'Rosa', 'Miguel', 'Luz', 'Ramon', 'Elena', 'Carlos', 'Lorna',
    'Antonio', 'Cristina', 'Eduardo', 'Patricia', 'Roberto', 'Marilyn', 'Ferdinand', 'Gloria',
    'Emmanuel', 'Sheila', 'Rodrigo', 'Maribel', 'Allan', 'Rowena', 'Dennis', 'Cynthia',
    'Renato', 'Joanna', 'Ernesto', 'Felicia', 'Arnel', 'Teresita', 'Joel', 'Lydia',
    'Danilo', 'Cecilia', 'Alfredo', 'Natividad', 'Rodel', 'Maricel', 'Edgardo', 'Remedios',
    'Nelson', 'Corazon', 'Virgilio', 'Rosario', 'Noel', 'Annaliza', 'Ricky', 'Delia',
];

$lastNames = [
    'Dela Cruz', 'Reyes', 'Santos', 'Garcia', 'Mendoza', 'Torres', 'Flores', 'Bautista',
    'Ramos', 'Lopez', 'Gonzales', 'Rivera', 'Aquino', 'Diaz', 'Castillo', 'Fernandez',
    'Morales', 'Navarro', 'Perez', 'Salazar', 'Domingo', 'Villanueva', 'Castro', 'Jimenez',
    'Pascual', 'Del Rosario', 'Aguilar', 'Lim', 'Tan', 'Sy', 'Ong', 'Chua',
    'Manalo', 'David', 'Soriano', 'Santiago', 'Tolentino', 'Alcantara', 'Hernandez', 'Macaraeg',
    'Buenaventura', 'Concepcion', 'Enriquez', 'Galang', 'Ignacio', 'Lacson', 'Magno', 'Natividad',
    'Ocampo', 'Ponce',
];

$employmentTypes = ['Regular', 'Regular', 'Regular', 'PartTime']; // 75% regular

$target = 50;
$existing = (int) $pdo->query(
    "SELECT COUNT(*) FROM `user` WHERE role = 'Faculty'"
)->fetchColumn();
echo "Existing faculty: {$existing}\n";

$insertUser = $pdo->prepare(
    "INSERT INTO `user`
        (uid, firstName, lastName, email, schoolId, role, phoneNumber, status, passwordHash, createdAt)
     VALUES
        (:uid, :firstName, :lastName, :email, :schoolId, 'Faculty', NULL, 'Active', :passwordHash, NOW())"
);
$insertDept = $pdo->prepare(
    'INSERT INTO departmentUser (userId, departmentId, createdAt)
     VALUES (:uid, :departmentId, NOW())'
);
$insertFaculty = $pdo->prepare(
    'INSERT INTO faculty (userId, employmentType, createdAt)
     VALUES (:uid, :employmentType, NOW())'
);

$created = 0;
$skipped = 0;
$year = (int) date('Y');

$pdo->beginTransaction();
try {
    for ($i = 1; $i <= $target; $i++) {
        $n    = str_pad((string) $i, 2, '0', STR_PAD_LEFT);
        $uid  = sprintf('user-cict-fac-%s', $n);
        $email = sprintf('faculty.cict.%s@scheduleguard.test', $n);
        $schoolId = sprintf('%d-F%s', $year, $n);

        $exists = $pdo->prepare(
            "SELECT uid FROM `user`
             WHERE uid = :uid OR email = :email
                OR (schoolId = :schoolId AND role = 'Faculty')
             LIMIT 1"
        );
        $exists->execute([':uid' => $uid, ':email' => $email, ':schoolId' => $schoolId]);
        if ($exists->fetchColumn()) {
            $skipped++;
            continue;
        }

        $firstName = $firstNames[($i - 1) % count($firstNames)];
        $lastName  = $lastNames[($i - 1) % count($lastNames)];
        $empType   = $employmentTypes[($i - 1) % count($employmentTypes)];

        $insertUser->execute([
            ':uid'          => $uid,
            ':firstName'    => $firstName,
            ':lastName'     => $lastName,
            ':email'        => $email,
            ':schoolId'     => $schoolId,
            ':passwordHash' => $passwordHash,
        ]);
        $insertDept->execute([':uid' => $uid, ':departmentId' => $departmentId]);
        $insertFaculty->execute([':uid' => $uid, ':employmentType' => $empType]);
        $created++;
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$total = (int) $pdo->query("SELECT COUNT(*) FROM `user` WHERE role = 'Faculty'")->fetchColumn();
echo "Created: {$created}, Skipped (already exist): {$skipped}\n";
echo "Total faculty now: {$total}\n";
echo "Done. Password for all: Password123!\n";
