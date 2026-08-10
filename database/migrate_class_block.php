<?php

declare(strict_types=1);

/**
 * Class section blocks: Dean creates first; Program Head assigns students.
 * Distinct from student-hold table `block`.
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();

function columnExistsClassBlockMig(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table
           AND COLUMN_NAME = :column'
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExistsClassBlockMig(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
    );
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!tableExistsClassBlockMig($pdo, 'classBlock')) {
    $pdo->exec(
        "CREATE TABLE classBlock (
          uid VARCHAR(36) NOT NULL,
          departmentId VARCHAR(36) NOT NULL,
          yearLevel ENUM('1st Year', '2nd Year', '3rd Year', '4th Year') NOT NULL,
          blockNumber INT UNSIGNED NOT NULL,
          name VARCHAR(100) NOT NULL,
          academicYear SMALLINT UNSIGNED NOT NULL,
          semester VARCHAR(10) NOT NULL,
          studentType VARCHAR(20) NULL,
          createdBy VARCHAR(36) NOT NULL,
          status VARCHAR(30) NOT NULL DEFAULT 'Open',
          createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (uid),
          UNIQUE KEY uq_class_block_slot (departmentId, yearLevel, academicYear, semester, blockNumber),
          KEY idx_class_block_department (departmentId),
          KEY idx_class_block_term (academicYear, semester),
          KEY idx_class_block_created_by (createdBy),
          CONSTRAINT fk_class_block_department
            FOREIGN KEY (departmentId) REFERENCES department (uid)
            ON UPDATE CASCADE ON DELETE RESTRICT,
          CONSTRAINT fk_class_block_created_by
            FOREIGN KEY (createdBy) REFERENCES `user` (uid)
            ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "Created classBlock.\n";
} else {
    echo "classBlock already exists.\n";
}

if (!tableExistsClassBlockMig($pdo, 'classBlockMember')) {
    $pdo->exec(
        "CREATE TABLE classBlockMember (
          uid VARCHAR(36) NOT NULL,
          classBlockId VARCHAR(36) NOT NULL,
          studentId VARCHAR(36) NOT NULL,
          assignedBy VARCHAR(36) NOT NULL,
          status VARCHAR(30) NOT NULL DEFAULT 'assigned',
          createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (uid),
          UNIQUE KEY uq_class_block_member (classBlockId, studentId),
          KEY idx_class_block_member_student (studentId),
          KEY idx_class_block_member_assigned_by (assignedBy),
          CONSTRAINT fk_class_block_member_block
            FOREIGN KEY (classBlockId) REFERENCES classBlock (uid)
            ON UPDATE CASCADE ON DELETE CASCADE,
          CONSTRAINT fk_class_block_member_student
            FOREIGN KEY (studentId) REFERENCES `user` (uid)
            ON UPDATE CASCADE ON DELETE RESTRICT,
          CONSTRAINT fk_class_block_member_assigned_by
            FOREIGN KEY (assignedBy) REFERENCES `user` (uid)
            ON UPDATE CASCADE ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "Created classBlockMember.\n";
} else {
    echo "classBlockMember already exists.\n";
}

if (!columnExistsClassBlockMig($pdo, 'schedule', 'classBlockId')) {
    $pdo->exec(
        'ALTER TABLE schedule
         ADD COLUMN classBlockId VARCHAR(36) NULL AFTER blockName,
         ADD KEY idx_schedule_class_block (classBlockId)'
    );
    echo "Added schedule.classBlockId.\n";
} else {
    echo "schedule.classBlockId already exists.\n";
}

try {
    $pdo->exec(
        'ALTER TABLE schedule
         ADD CONSTRAINT fk_schedule_class_block
         FOREIGN KEY (classBlockId) REFERENCES classBlock (uid)
         ON UPDATE CASCADE ON DELETE SET NULL'
    );
    echo "Added fk_schedule_class_block.\n";
} catch (Throwable $e) {
    echo "fk_schedule_class_block skipped (may already exist).\n";
}

// Backfill classBlock rows from distinct schedule block labels.
$distinct = $pdo->query(
    "SELECT DISTINCT departmentId, yearLevel, blockNumber, blockName, academicYear, semester, studentType, createdBy
     FROM schedule
     WHERE blockName IS NOT NULL AND blockName <> ''
       AND blockNumber IS NOT NULL
       AND yearLevel IS NOT NULL
       AND (classBlockId IS NULL OR classBlockId = '')"
)->fetchAll(PDO::FETCH_ASSOC);

$created = 0;
foreach ($distinct as $row) {
    $check = $pdo->prepare(
        'SELECT uid FROM classBlock
         WHERE departmentId = :departmentId
           AND yearLevel = :yearLevel
           AND academicYear = :academicYear
           AND semester = :semester
           AND blockNumber = :blockNumber
         LIMIT 1'
    );
    $check->execute([
        ':departmentId' => $row['departmentId'],
        ':yearLevel' => $row['yearLevel'],
        ':academicYear' => $row['academicYear'],
        ':semester' => $row['semester'],
        ':blockNumber' => $row['blockNumber'],
    ]);
    $existingUid = $check->fetchColumn();
    if ($existingUid) {
        $uid = (string) $existingUid;
    } else {
        $uid = sprintf(
            'cblock-%s-%s-%s',
            preg_replace('/\W+/', '', (string) $row['yearLevel']),
            (string) $row['blockNumber'],
            substr(md5(json_encode($row)), 0, 8)
        );
        $ins = $pdo->prepare(
            'INSERT INTO classBlock
                (uid, departmentId, yearLevel, blockNumber, name, academicYear, semester, studentType, createdBy, status, createdAt)
             VALUES
                (:uid, :departmentId, :yearLevel, :blockNumber, :name, :academicYear, :semester, :studentType, :createdBy, \'Open\', NOW())'
        );
        $ins->execute([
            ':uid' => $uid,
            ':departmentId' => $row['departmentId'],
            ':yearLevel' => $row['yearLevel'],
            ':blockNumber' => $row['blockNumber'],
            ':name' => $row['blockName'],
            ':academicYear' => $row['academicYear'],
            ':semester' => $row['semester'],
            ':studentType' => $row['studentType'],
            ':createdBy' => $row['createdBy'],
        ]);
        $created++;
    }

    $upd = $pdo->prepare(
        'UPDATE schedule SET classBlockId = :classBlockId
         WHERE departmentId = :departmentId
           AND yearLevel = :yearLevel
           AND academicYear = :academicYear
           AND semester = :semester
           AND blockNumber = :blockNumber
           AND (classBlockId IS NULL OR classBlockId = \'\')'
    );
    $upd->execute([
        ':classBlockId' => $uid,
        ':departmentId' => $row['departmentId'],
        ':yearLevel' => $row['yearLevel'],
        ':academicYear' => $row['academicYear'],
        ':semester' => $row['semester'],
        ':blockNumber' => $row['blockNumber'],
    ]);
}

echo "Backfilled {$created} classBlock row(s) from schedules.\n";
echo "Class block migration complete.\n";
