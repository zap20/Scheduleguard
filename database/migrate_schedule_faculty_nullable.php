<?php

declare(strict_types=1);

/**
 * Allow schedules without an assigned instructor (displayed as TBF).
 */

require_once dirname(__DIR__) . '/config/database.php';

$pdo = db();

echo "Making schedule.facultyId nullable …\n";

try {
    $pdo->exec('ALTER TABLE schedule DROP FOREIGN KEY fk_schedule_faculty');
} catch (Throwable $e) {
    // Already dropped or named differently — continue.
}

$pdo->exec(
    'ALTER TABLE schedule
     MODIFY facultyId VARCHAR(36) NULL'
);

try {
    $pdo->exec(
        'ALTER TABLE schedule
         ADD CONSTRAINT fk_schedule_faculty
         FOREIGN KEY (facultyId) REFERENCES `user` (uid)
         ON UPDATE CASCADE
         ON DELETE RESTRICT'
    );
} catch (Throwable $e) {
    // Constraint may already exist after a partial run.
}

echo "Done. Unassigned instructors will display as TBF.\n";
