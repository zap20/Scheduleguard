<?php

declare(strict_types=1);

/**
 * Department enrollments for the Program Head (filter by status).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Enrollment.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead']);
$departmentId = requireProgramHeadDepartment($user['uid']);
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';

jsonSuccess([
    'term' => currentTermWindow(),
    'enrollments' => fetchDepartmentEnrollments(
        $departmentId,
        $status !== '' ? $status : null
    ),
]);
