<?php

declare(strict_types=1);

/**
 * Faculty attendance view — own records only (schedule.facultyId = authenticated user).
 * Read-only. RBAC: Faculty.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Attendance.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Faculty']);
$records = fetchFacultyAttendance($user['uid']);

// Own data only — not an "another user" view, so no cross-user audit entry.

jsonSuccess([
    'view' => 'faculty',
    'framing' => 'Your scheduled classes cross-referenced with attendance scans.',
    'records' => $records,
    'count' => count($records),
]);
