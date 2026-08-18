<?php

declare(strict_types=1);

/**
 * Dean view of faculty teaching schedules (draft + confirmed + conflict).
 * Filters: facultyId (or TBF), roomId.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';
require_once dirname(__DIR__, 2) . '/includes/FacultyLoad.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'Checker', 'ProgramHead', 'HR']);
$facultyId = isset($_GET['facultyId']) ? trim((string) $_GET['facultyId']) : '';
$roomId = isset($_GET['roomId']) ? trim((string) $_GET['roomId']) : '';

$viewerDepartmentId = null;
if (in_array($user['role'], ['Dean', 'ProgramHead'], true)) {
    $viewerDepartmentId = userDepartmentId($user['uid']);
}

$viewerDepartment = null;
if ($viewerDepartmentId !== null && $viewerDepartmentId !== '') {
    $deptStmt = db()->prepare('SELECT uid, name FROM department WHERE uid = :uid LIMIT 1');
    $deptStmt->execute([':uid' => $viewerDepartmentId]);
    $deptRow = $deptStmt->fetch();
    if ($deptRow) {
        $viewerDepartment = [
            'uid' => (string) $deptRow['uid'],
            'name' => (string) $deptRow['name'],
        ];
    }
}

jsonSuccess([
    'term' => currentTermWindow(),
    'viewerDepartment' => $viewerDepartment,
    'schedules' => fetchDeanFacultySchedules(
        $facultyId !== '' ? $facultyId : null,
        $roomId !== '' ? $roomId : null,
        $viewerDepartmentId
    ),
    'tbfSummary' => strcasecmp($facultyId, SCHEDULE_INSTRUCTOR_TBF) === 0
        ? computeDepartmentTbfLoadSummary($viewerDepartmentId)
        : null,
]);
