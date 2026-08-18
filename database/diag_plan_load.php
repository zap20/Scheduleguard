<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/FacultyLoad.php';
require_once dirname(__DIR__) . '/includes/Schedule.php';

$term = currentTermWindow();
$dept = 'dept-cict';
$faculty = db()->query(
    "SELECT uid, firstName, lastName FROM userProfile
     WHERE role='Faculty' AND departmentId='dept-cict' AND status='Active'
     ORDER BY lastName"
)->fetchAll();

foreach ($faculty as $f) {
    $uid = (string) $f['uid'];
    $st = db()->prepare(
        'SELECT COUNT(*) FROM schedule s
         WHERE s.facultyId = :fid AND s.academicYear = :y AND s.semester = :sem
           AND LOWER(s.status) IN (\'draft\', \'confirmed\', \'conflict\')'
    );
    $st->execute([':fid' => $uid, ':y' => $term['academicYear'], ':sem' => $term['semester']]);
    $meetings = (int) $st->fetchColumn();
    if ($meetings === 0) {
        echo $f['firstName'] . ' ' . $f['lastName'] . ' (' . $uid . ") meetings=0\n";
        $parsed = parseFacultyLoadCommand('8 loads');
        try {
            $preview = planFacultyLoadAssignment($parsed, $dept, false, $uid);
            echo '  offerings=' . count($preview['allOfferings'] ?? []) .
                ' suggested=' . count($preview['suggestedOfferingKeys'] ?? []) . "\n";
        } catch (Throwable $e) {
            echo '  ERROR: ' . $e->getMessage() . "\n";
        }
    }
}
