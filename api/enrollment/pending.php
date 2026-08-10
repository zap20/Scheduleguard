<?php

declare(strict_types=1);

/**
 * Cleared students in the Program Head's department who are not yet enrolled
 * in a confirmed schedule for the current term.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Enrollment.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead']);
$departmentId = requireProgramHeadDepartment($user['uid']);
$term = currentTermWindow();

jsonSuccess([
    'term' => $term,
    'departmentId' => $departmentId,
    'students' => fetchPendingEnrollmentStudents($departmentId),
    'schedules' => fetchConfirmedSchedulesForDepartment($departmentId),
]);
