<?php

declare(strict_types=1);

/**
 * Add GenEd department plus 1 Dean, 1 Faculty, and 1 Program Head.
 * Skips rows that already exist. Password: Password123!
 *
 * Usage: php database/seed_gened_users.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$passwordHash = '$2y$12$EuTcOG/HaILb.m3MOInBMehesZPzyiIaDR8MxH7kvfuv01IKf7uh.';

$dept = $pdo->query(
    "SELECT uid, name FROM department
     WHERE uid = 'dept-gened'
        OR name IN ('GenEd', 'GENED', 'GENed', 'General Education')
     ORDER BY CASE WHEN uid = 'dept-gened' THEN 0 ELSE 1 END
     LIMIT 1"
)->fetch();

if (!$dept) {
    $pdo->exec(
        "INSERT INTO department (uid, name, createdAt)
         VALUES ('dept-gened', 'GenEd', NOW())"
    );
    $departmentId = 'dept-gened';
    $deptName = 'GenEd';
} else {
    $departmentId = (string) $dept['uid'];
    $deptName = (string) $dept['name'];
}

$users = [
    [
        'uid' => 'user-gened-dean',
        'firstName' => 'Gina',
        'lastName' => 'Dean',
        'email' => 'gened.dean@scheduleguard.test',
        'schoolId' => '2020-202',
        'role' => 'Dean',
        'phoneNumber' => '09010000013',
        'faculty' => false,
    ],
    [
        'uid' => 'user-gened-ph',
        'firstName' => 'Grace',
        'lastName' => 'ProgramHead',
        'email' => 'gened.programhead@scheduleguard.test',
        'schoolId' => '2020-402',
        'role' => 'ProgramHead',
        'phoneNumber' => '09010000015',
        'faculty' => false,
    ],
    [
        'uid' => 'user-gened-fac-01',
        'firstName' => 'Cara',
        'lastName' => 'Reyes',
        'email' => 'gened.faculty01@scheduleguard.test',
        'schoolId' => '2020-601',
        'role' => 'Faculty',
        'phoneNumber' => '09010000601',
        'faculty' => true,
    ],
];

$pdo->beginTransaction();
try {
    echo "Department: {$deptName} ({$departmentId})\n";

    $findUser = $pdo->prepare(
        "SELECT uid FROM `user`
         WHERE uid = :uid OR email = :email
            OR (schoolId = :schoolId AND role = :role)
         LIMIT 1"
    );
    $insertUser = $pdo->prepare(
        "INSERT INTO `user`
            (uid, firstName, lastName, email, schoolId, role, phoneNumber, status, passwordHash, createdAt)
         VALUES
            (:uid, :firstName, :lastName, :email, :schoolId, :role, :phoneNumber, 'Active', :passwordHash, NOW())"
    );
    $insertDept = $pdo->prepare(
        'INSERT IGNORE INTO departmentUser (userId, departmentId, createdAt)
         VALUES (:uid, :departmentId, NOW())'
    );
    $insertFaculty = $pdo->prepare(
        "INSERT IGNORE INTO faculty (userId, employmentType, createdAt)
         VALUES (:uid, 'Regular', NOW())"
    );

    foreach ($users as $row) {
        $findUser->execute([
            ':uid' => $row['uid'],
            ':email' => $row['email'],
            ':schoolId' => $row['schoolId'],
            ':role' => $row['role'],
        ]);
        $existing = $findUser->fetchColumn();
        if ($existing) {
            $uid = (string) $existing;
            echo "Exists: {$row['role']} {$row['email']} ({$uid})\n";
        } else {
            $insertUser->execute([
                ':uid' => $row['uid'],
                ':firstName' => $row['firstName'],
                ':lastName' => $row['lastName'],
                ':email' => $row['email'],
                ':schoolId' => $row['schoolId'],
                ':role' => $row['role'],
                ':phoneNumber' => $row['phoneNumber'],
                ':passwordHash' => $passwordHash,
            ]);
            $uid = $row['uid'];
            echo "Created: {$row['role']} {$row['firstName']} {$row['lastName']} <{$row['email']}>\n";
        }

        $insertDept->execute([
            ':uid' => $uid,
            ':departmentId' => $departmentId,
        ]);
        if ($row['faculty']) {
            $insertFaculty->execute([':uid' => $uid]);
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

echo "Password: Password123!\n";
