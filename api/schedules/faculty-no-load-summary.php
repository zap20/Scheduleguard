<?php

declare(strict_types=1);

/**
 * Faculty with no teaching load this term (dean's department).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/FacultyLoad.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead', 'Checker', 'HR']);

$departmentId = null;
if (in_array($user['role'], ['Dean', 'ProgramHead'], true)) {
    $departmentId = userDepartmentId($user['uid']);
}

$summary = computeDepartmentFacultyNoLoadSummary($departmentId);

jsonSuccess([
    'term' => currentTermWindow(),
    'summary' => $summary,
]);
