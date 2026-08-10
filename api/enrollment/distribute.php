<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Enrollment.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead']);
$departmentId = requireProgramHeadDepartment($user['uid']);
$body = requestBody();

$enrollmentId = trim((string) ($body['uid'] ?? $body['enrollmentId'] ?? ''));
if ($enrollmentId === '') {
    jsonError('uid (or enrollmentId) is required.', 422);
}

$existing = fetchEnrollmentById($enrollmentId);
if ($existing === null) {
    jsonError('Enrollment not found.', 404);
}
if ($existing['departmentId'] !== $departmentId) {
    jsonError('Enrollment is outside your department.', 403);
}

try {
    $enrollment = distributeEnrollment($enrollmentId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'DISTRIBUTE',
    'enrollment',
    sprintf(
        'Distributed schedule "%s" to %s (now visible on student schedule view).',
        $enrollment['subjectName'],
        $enrollment['studentName']
    ),
    $enrollment['uid']
);

jsonSuccess(['enrollment' => $enrollment]);
