<?php

declare(strict_types=1);

/**
 * Create subject table and migrate schedule.subjectCode/subjectName → subjectId.
 * Safe to re-run.
 *
 *   php database/migrate_curriculum.php
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

function columnExists(PDO $pdo, string $table, string $column): bool
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

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table'
    );
    $stmt->execute([':table' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

if (!tableExists($pdo, 'subject')) {
    $pdo->exec(
        "CREATE TABLE subject (
          uid VARCHAR(36) NOT NULL,
          departmentId VARCHAR(36) NOT NULL,
          code VARCHAR(50) NOT NULL,
          title VARCHAR(200) NOT NULL,
          yearLevel ENUM('1st Year', '2nd Year', '3rd Year', '4th Year') NOT NULL,
          semester ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL,
          units DECIMAL(4,1) NOT NULL DEFAULT 3.0,
          status VARCHAR(30) NOT NULL DEFAULT 'Active',
          createdAt DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (uid),
          UNIQUE KEY uq_subject_dept_code (departmentId, code),
          KEY idx_subject_department (departmentId),
          KEY idx_subject_year_sem (yearLevel, semester),
          KEY idx_subject_status (status),
          CONSTRAINT fk_subject_department
            FOREIGN KEY (departmentId) REFERENCES department (uid)
            ON UPDATE CASCADE
            ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    echo "Created subject table.\n";
} else {
    echo "subject table already exists.\n";
}

$hasSubjectId = columnExists($pdo, 'schedule', 'subjectId');
$hasSubjectCode = columnExists($pdo, 'schedule', 'subjectCode');
$hasSubjectName = columnExists($pdo, 'schedule', 'subjectName');

if (!$hasSubjectId) {
    $pdo->exec(
        "ALTER TABLE schedule
         ADD COLUMN subjectId VARCHAR(36) NULL AFTER createdBy"
    );
    echo "Added schedule.subjectId (nullable, pending backfill).\n";
    $hasSubjectId = true;
} else {
    echo "schedule.subjectId already exists.\n";
}

if ($hasSubjectCode || $hasSubjectName) {
    $selectCols = 'uid, departmentId';
    if ($hasSubjectCode) {
        $selectCols .= ', subjectCode';
    }
    if ($hasSubjectName) {
        $selectCols .= ', subjectName';
    }
    if (columnExists($pdo, 'schedule', 'semester')) {
        $selectCols .= ', semester';
    }

    $rows = $pdo->query("SELECT {$selectCols} FROM schedule")->fetchAll(PDO::FETCH_ASSOC);
    $find = $pdo->prepare(
        'SELECT uid FROM subject WHERE departmentId = :departmentId AND UPPER(code) = :code LIMIT 1'
    );
    $insert = $pdo->prepare(
        'INSERT INTO subject
            (uid, departmentId, code, title, yearLevel, semester, units, status, createdAt)
         VALUES
            (:uid, :departmentId, :code, :title, :yearLevel, :semester, 3.0, \'Active\', NOW())'
    );
    $update = $pdo->prepare('UPDATE schedule SET subjectId = :subjectId WHERE uid = :uid');

    $created = 0;
    $linked = 0;

    foreach ($rows as $row) {
        $code = '';
        if ($hasSubjectCode) {
            $code = strtoupper(trim((string) ($row['subjectCode'] ?? '')));
        }
        $title = $hasSubjectName ? trim((string) ($row['subjectName'] ?? '')) : '';
        if ($code === '') {
            if ($title !== '' && preg_match('/\b([A-Z]{2,5}\s?\d{2,4})\b/', strtoupper($title), $m)) {
                $code = preg_replace('/\s+/', ' ', $m[1]) ?? $m[1];
            } else {
                $code = 'SUB-' . substr((string) $row['uid'], 0, 8);
            }
        }
        if ($title === '') {
            $title = $code;
        }

        $semesterRaw = (string) ($row['semester'] ?? '1');
        $curriculumSemester = '1st Semester';
        $s = strtolower(trim($semesterRaw));
        if ($s === '2' || $s === '2nd' || $s === '2nd semester') {
            $curriculumSemester = '2nd Semester';
        } elseif ($s === 'summer') {
            $curriculumSemester = 'Summer';
        }

        $find->execute([
            ':departmentId' => $row['departmentId'],
            ':code' => $code,
        ]);
        $subjectUid = $find->fetchColumn();
        if (!$subjectUid) {
            $subjectUid = sprintf('subj-%s', bin2hex(random_bytes(8)));
            $insert->execute([
                ':uid' => $subjectUid,
                ':departmentId' => $row['departmentId'],
                ':code' => $code,
                ':title' => $title,
                ':yearLevel' => '1st Year',
                ':semester' => $curriculumSemester,
            ]);
            $created++;
        }

        $update->execute([
            ':subjectId' => $subjectUid,
            ':uid' => $row['uid'],
        ]);
        $linked++;
    }

    echo "Backfilled subjectId on {$linked} schedule row(s); created {$created} subject(s).\n";
}

// Ensure NOT NULL + FK on subjectId
$nulls = (int) $pdo->query('SELECT COUNT(*) FROM schedule WHERE subjectId IS NULL OR subjectId = \'\'')->fetchColumn();
if ($nulls > 0) {
    fwrite(STDERR, "WARNING: {$nulls} schedule rows still missing subjectId; leaving column nullable.\n");
} else {
    try {
        $pdo->exec('ALTER TABLE schedule MODIFY subjectId VARCHAR(36) NOT NULL');
        echo "schedule.subjectId set to NOT NULL.\n";
    } catch (Throwable $e) {
        echo "Could not set subjectId NOT NULL: " . $e->getMessage() . "\n";
    }
}

$fkExists = (int) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'schedule'
       AND CONSTRAINT_NAME = 'fk_schedule_subject'
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
)->fetchColumn() > 0;

if (!$fkExists && $nulls === 0) {
    try {
        $pdo->exec(
            'ALTER TABLE schedule
             ADD CONSTRAINT fk_schedule_subject
               FOREIGN KEY (subjectId) REFERENCES subject (uid)
               ON UPDATE CASCADE
               ON DELETE RESTRICT'
        );
        echo "Added fk_schedule_subject.\n";
    } catch (Throwable $e) {
        echo "Could not add FK: " . $e->getMessage() . "\n";
    }
} else {
    echo "fk_schedule_subject already present or skipped.\n";
}

if ($hasSubjectCode) {
    try {
        $pdo->exec('ALTER TABLE schedule DROP COLUMN subjectCode');
        echo "Dropped schedule.subjectCode.\n";
    } catch (Throwable $e) {
        echo "Could not drop subjectCode: " . $e->getMessage() . "\n";
    }
}
if ($hasSubjectName) {
    try {
        $pdo->exec('ALTER TABLE schedule DROP COLUMN subjectName');
        echo "Dropped schedule.subjectName.\n";
    } catch (Throwable $e) {
        echo "Could not drop subjectName: " . $e->getMessage() . "\n";
    }
}

echo "Curriculum migration complete.\n";
