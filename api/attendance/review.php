<?php

declare(strict_types=1);

/**
 * HR attendance review — all faculty, filterable by department + date range.
 * Framed for performance evaluation. Read-only. RBAC: HR.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Attendance.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['HR']);

$departmentId = isset($_GET['departmentId']) ? trim((string) $_GET['departmentId']) : null;
$dateFrom = isset($_GET['dateFrom']) ? trim((string) $_GET['dateFrom']) : null;
$dateTo = isset($_GET['dateTo']) ? trim((string) $_GET['dateTo']) : null;

if ($dateFrom !== null && $dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    jsonError('dateFrom must be YYYY-MM-DD.', 422);
}
if ($dateTo !== null && $dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    jsonError('dateTo must be YYYY-MM-DD.', 422);
}

$records = fetchAttendanceReview(
    $departmentId !== '' ? $departmentId : null,
    $dateFrom !== '' ? $dateFrom : null,
    $dateTo !== '' ? $dateTo : null
);

auditAttendanceViews(
    $user['uid'],
    'HR',
    'performance evaluation',
    $records,
    $departmentId !== '' ? $departmentId : null,
    $dateFrom !== '' ? $dateFrom : null,
    $dateTo !== '' ? $dateTo : null
);

jsonSuccess([
    'view' => 'hr',
    'framing' => 'Performance evaluation — campus-wide faculty attendance (read-only).',
    'filters' => [
        'departmentId' => $departmentId !== '' ? $departmentId : null,
        'dateFrom' => $dateFrom !== '' ? $dateFrom : null,
        'dateTo' => $dateTo !== '' ? $dateTo : null,
    ],
    'records' => $records,
    'count' => count($records),
]);
