<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(
    "INSERT IGNORE INTO department (uid, name, createdAt)
     VALUES ('dept-crim', 'Criminology', NOW())"
);
echo "Criminology department ready.\n";

$stmt = $pdo->prepare('SELECT uid FROM subject WHERE uid = ?');
$stmt->execute(['subj-if211']);
if (!$stmt->fetchColumn()) {
    $pdo->exec(
        "INSERT INTO subject (
            uid, departmentId, servingDepartmentId, code, title, yearLevel, semester,
            curriculumYear, subjectType, units, lectureHours, labHours, labSessionCount,
            preferredRoomType, status, createdAt
         ) VALUES (
            'subj-if211', 'dept-cict', 'dept-crim', 'IF211', 'IT Fundamentals',
            '1st Year', '1st Semester', 2026, 'MINOR', 3.0, 1.5, 1.5, 1,
            'LECTURE', 'Active', NOW()
         )"
    );
    echo "Created IF211 minor (CICT → Criminology).\n";
} else {
    echo "IF211 already exists.\n";
}

echo "OK.\n";
