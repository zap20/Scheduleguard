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

$studentId = trim((string) ($body['studentId'] ?? ''));
$scheduleId = trim((string) ($body['scheduleId'] ?? ''));

if ($studentId === '' || $scheduleId === '') {
    jsonError('studentId and scheduleId are required.', 422);
}

$studentDept = userDepartmentId($studentId);
if ($studentDept === null || $studentDept !== $departmentId) {
    jsonError('Student is not in your department.', 403);
}

if (!isStudentCleared($studentId)) {
    $block = getActiveBlock($studentId);
    jsonError(
        'Student is not cleared and cannot be enrolled.',
        422,
        [
            'code' => 'NOT_CLEARED',
            'blockReason' => $block['reason'] ?? 'Active block on record.',
            'block' => $block,
        ]
    );
}

try {
    $enrollment = createEnrollment($studentId, $scheduleId, $user['uid']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if (str_starts_with($message, 'NOT_CLEARED:')) {
        $reason = substr($message, strlen('NOT_CLEARED:'));
        jsonError('Student is not cleared and cannot be enrolled.', 422, [
            'code' => 'NOT_CLEARED',
            'blockReason' => $reason,
        ]);
    }
    jsonError($message, 422);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

if ($enrollment['departmentId'] !== $departmentId) {
    jsonError('Enrollment department mismatch.', 403);
}

logAudit(
    $user['uid'],
    'ENROLL',
    'enrollment',
    sprintf(
        'Enrolled %s in "%s" (status assigned).',
        $enrollment['studentName'],
        $enrollment['subjectName']
    ),
    $enrollment['uid']
);

jsonSuccess(['enrollment' => $enrollment], 201);
