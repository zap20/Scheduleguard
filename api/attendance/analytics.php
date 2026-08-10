<?php

declare(strict_types=1);

/**
 * Dean attendance analytics — top absent faculty for a semester.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Attendance.php';
require_once dirname(__DIR__, 2) . '/includes/Term.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'HR']);

$current = currentTermWindow();
$departmentId = isset($_GET['departmentId']) ? trim((string) $_GET['departmentId']) : '';
$academicYearRaw = isset($_GET['academicYear']) ? trim((string) $_GET['academicYear']) : '';
$semester = isset($_GET['semester']) ? trim((string) $_GET['semester']) : '';

$academicYear = $academicYearRaw !== '' ? (int) $academicYearRaw : $current['academicYear'];
if ($academicYear < 2000 || $academicYear > 2100) {
    jsonError('academicYear must be between 2000 and 2100.', 422);
}
if ($semester === '') {
    $semester = $current['semester'];
}
if (!in_array($semester, SCHEDULE_SEMESTERS, true)) {
    jsonError('semester must be 1, 2, or Summer.', 422);
}

$topAbsent = fetchTopAbsentFaculty(
    $academicYear,
    $semester,
    $departmentId !== '' ? $departmentId : null,
    10
);

logAudit(
    $user['uid'],
    'viewed',
    'attendance',
    sprintf(
        '%s viewed top-10 absent analytics for AY%d Sem %s%s.',
        $user['role'],
        $academicYear,
        $semester,
        $departmentId !== '' ? ' (department ' . $departmentId . ')' : ''
    )
);

jsonSuccess([
    'term' => [
        'label' => formatTermLabel($academicYear, $semester),
        'academicYear' => $academicYear,
        'semester' => $semester,
        'current' => $current,
    ],
    'filters' => [
        'departmentId' => $departmentId !== '' ? $departmentId : null,
        'academicYear' => $academicYear,
        'semester' => $semester,
    ],
    'topAbsent' => $topAbsent,
    'count' => count($topAbsent),
]);
