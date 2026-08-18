<?php

declare(strict_types=1);

/**
 * Split user-side columns into departmentUser, faculty, and student.
 *
 * Usage: php database/migrate_normalize_user_side.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND TABLE_TYPE = \'BASE TABLE\''
    );
    $stmt->execute([':t' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $table, ':c' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function foreignKeyExists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :t
           AND CONSTRAINT_NAME = :c
           AND CONSTRAINT_TYPE = \'FOREIGN KEY\''
    );
    $stmt->execute([':t' => $table, ':c' => $constraint]);
    return (int) $stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND INDEX_NAME = :i'
    );
    $stmt->execute([':t' => $table, ':i' => $index]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!tableExists($pdo, 'departmentUser')) {
    $pdo->exec(
        "CREATE TABLE departmentUser (
          userId VARCHAR(36) NOT NULL,
          departmentId VARCHAR(36) NOT NULL,
          createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (userId),
          KEY idx_department_user_department (departmentId),
          CONSTRAINT fk_department_user_user
            FOREIGN KEY (userId) REFERENCES `user` (uid)
            ON UPDATE CASCADE
            ON DELETE CASCADE,
          CONSTRAINT fk_department_user_department
            FOREIGN KEY (departmentId) REFERENCES department (uid)
            ON UPDATE CASCADE
            ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "Created departmentUser\n";
} else {
    echo "departmentUser already exists\n";
}

if (!tableExists($pdo, 'faculty')) {
    $pdo->exec(
        "CREATE TABLE faculty (
          userId VARCHAR(36) NOT NULL,
          employmentType ENUM('Regular', 'PartTime') NOT NULL DEFAULT 'Regular',
          createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (userId),
          KEY idx_faculty_employment (employmentType),
          CONSTRAINT fk_faculty_user
            FOREIGN KEY (userId) REFERENCES `user` (uid)
            ON UPDATE CASCADE
            ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "Created faculty\n";
} else {
    echo "faculty already exists\n";
}

if (!tableExists($pdo, 'student')) {
    $pdo->exec(
        "CREATE TABLE student (
          userId VARCHAR(36) NOT NULL,
          yearLevel ENUM('1st Year', '2nd Year', '3rd Year', '4th Year') NOT NULL,
          studentType ENUM('Regular', 'Irregular') NOT NULL DEFAULT 'Regular',
          enrollmentEvalStatus ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
          enrollmentEvalBy VARCHAR(36) NULL,
          enrollmentEvalAt DATETIME NULL,
          enrollmentEvalNotes VARCHAR(500) NULL,
          createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (userId),
          KEY idx_student_year (yearLevel),
          KEY idx_student_type (studentType),
          KEY idx_student_eval (enrollmentEvalStatus),
          KEY idx_student_eval_by (enrollmentEvalBy),
          CONSTRAINT fk_student_user
            FOREIGN KEY (userId) REFERENCES `user` (uid)
            ON UPDATE CASCADE
            ON DELETE CASCADE,
          CONSTRAINT fk_student_eval_by
            FOREIGN KEY (enrollmentEvalBy) REFERENCES `user` (uid)
            ON UPDATE CASCADE
            ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "Created student\n";
} else {
    echo "student already exists\n";
}

if (columnExists($pdo, 'user', 'departmentId')) {
    $pdo->exec(
        "INSERT INTO departmentUser (userId, departmentId, createdAt)
         SELECT uid, departmentId, createdAt
         FROM `user`
         WHERE departmentId IS NOT NULL AND departmentId <> ''
         ON DUPLICATE KEY UPDATE departmentId = VALUES(departmentId)"
    );
    echo "Copied department membership\n";
}

if (columnExists($pdo, 'user', 'employmentType')) {
    $pdo->exec(
        "INSERT INTO faculty (userId, employmentType, createdAt)
         SELECT uid, COALESCE(NULLIF(employmentType, ''), 'Regular'), createdAt
         FROM `user`
         WHERE role = 'Faculty'
         ON DUPLICATE KEY UPDATE employmentType = VALUES(employmentType)"
    );
    echo "Copied faculty profiles\n";
} elseif ((int) $pdo->query("SELECT COUNT(*) FROM faculty")->fetchColumn() === 0) {
    $pdo->exec(
        "INSERT INTO faculty (userId, employmentType, createdAt)
         SELECT uid, 'Regular', createdAt
         FROM `user`
         WHERE role = 'Faculty'"
    );
    echo "Created faculty profiles from role\n";
}

$hasStudentSource = columnExists($pdo, 'user', 'yearLevel')
    || columnExists($pdo, 'user', 'studentType')
    || columnExists($pdo, 'user', 'enrollmentEvalStatus');

if ($hasStudentSource) {
    $yearExpr = columnExists($pdo, 'user', 'yearLevel')
        ? "COALESCE(NULLIF(yearLevel, ''), '1st Year')"
        : "'1st Year'";
    $typeExpr = columnExists($pdo, 'user', 'studentType')
        ? "COALESCE(NULLIF(studentType, ''), 'Regular')"
        : "'Regular'";
    $evalExpr = columnExists($pdo, 'user', 'enrollmentEvalStatus')
        ? "COALESCE(NULLIF(enrollmentEvalStatus, ''), 'Pending')"
        : "'Pending'";
    $evalByExpr = columnExists($pdo, 'user', 'enrollmentEvalBy')
        ? 'NULLIF(enrollmentEvalBy, \'\')'
        : 'NULL';
    $evalAtExpr = columnExists($pdo, 'user', 'enrollmentEvalAt')
        ? 'enrollmentEvalAt'
        : 'NULL';
    $evalNotesExpr = columnExists($pdo, 'user', 'enrollmentEvalNotes')
        ? 'enrollmentEvalNotes'
        : 'NULL';

    $pdo->exec(
        "INSERT INTO student (
            userId, yearLevel, studentType, enrollmentEvalStatus,
            enrollmentEvalBy, enrollmentEvalAt, enrollmentEvalNotes, createdAt
         )
         SELECT uid, {$yearExpr}, {$typeExpr}, {$evalExpr},
                {$evalByExpr}, {$evalAtExpr}, {$evalNotesExpr}, createdAt
         FROM `user`
         WHERE role = 'Student'
         ON DUPLICATE KEY UPDATE
            yearLevel = VALUES(yearLevel),
            studentType = VALUES(studentType),
            enrollmentEvalStatus = VALUES(enrollmentEvalStatus)"
    );
    echo "Copied student profiles\n";
} elseif ((int) $pdo->query('SELECT COUNT(*) FROM student')->fetchColumn() === 0) {
    $pdo->exec(
        "INSERT INTO student (userId, yearLevel, studentType, enrollmentEvalStatus, createdAt)
         SELECT uid, '1st Year', 'Regular', 'Pending', createdAt
         FROM `user`
         WHERE role = 'Student'"
    );
    echo "Created student profiles from role\n";
}

$pdo->exec(
    'CREATE OR REPLACE VIEW userProfile AS
     SELECT
       u.uid,
       du.departmentId,
       u.firstName,
       u.lastName,
       u.email,
       u.schoolId,
       u.role,
       u.phoneNumber,
       u.status,
       f.employmentType,
       s.yearLevel,
       s.studentType,
       s.enrollmentEvalStatus,
       s.enrollmentEvalBy,
       s.enrollmentEvalAt,
       s.enrollmentEvalNotes,
       u.passwordHash,
       u.apiToken,
       u.tokenExpiresAt,
       u.createdAt
     FROM `user` u
     LEFT JOIN departmentUser du ON du.userId = u.uid
     LEFT JOIN faculty f ON f.userId = u.uid
     LEFT JOIN student s ON s.userId = u.uid'
);
echo "Created userProfile view\n";

$drops = [];
if (foreignKeyExists($pdo, 'user', 'fk_user_department')) {
    $drops[] = 'DROP FOREIGN KEY fk_user_department';
}
if (indexExists($pdo, 'user', 'idx_user_department')) {
    $drops[] = 'DROP INDEX idx_user_department';
}

$userColumns = [
    'departmentId',
    'employmentType',
    'yearLevel',
    'studentType',
    'enrollmentEvalStatus',
    'enrollmentEvalBy',
    'enrollmentEvalAt',
    'enrollmentEvalNotes',
];
foreach ($userColumns as $column) {
    if (columnExists($pdo, 'user', $column)) {
        $drops[] = "DROP COLUMN {$column}";
    }
}

if ($drops !== []) {
    $pdo->exec('ALTER TABLE `user` ' . implode(', ', $drops));
    echo "Dropped denormalized user columns\n";
} else {
    echo "user table already normalized\n";
}

if (!indexExists($pdo, 'user', 'idx_user_status')) {
    $pdo->exec('ALTER TABLE `user` ADD KEY idx_user_status (status)');
}

echo "OK\n";
