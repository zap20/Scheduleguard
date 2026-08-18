<?php

declare(strict_types=1);

/**
 * Program Head approves a student for class-block assignment this term.
 * Body: { studentId, notes? }
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Enrollment.php';
require_once dirname(__DIR__, 2) . '/includes/StudentEvaluation.php';
require_once dirname(__DIR__, 2) . '/includes/Term.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead']);
$departmentId = requireProgramHeadDepartment($user['uid']);
$body = requestBody();

$studentId = trim((string) ($body['studentId'] ?? ''));
$notes = trim((string) ($body['notes'] ?? ''));

if ($studentId === '') {
    jsonError('studentId is required.', 422);
}

$existing = fetchStudentEvaluationRow($studentId);
if ($existing === null) {
    jsonError('Student not found.', 404);
}
if ((string) ($existing['departmentId'] ?? '') !== $departmentId) {
    jsonError('Student is outside your department.', 403);
}

try {
    $student = evaluateStudentForEnrollment(
        $studentId,
        $user['uid'],
        ENROLLMENT_EVAL_APPROVED,
        $notes
    );
} catch (DomainException $e) {
    jsonError($e->getMessage(), 422);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
} catch (RuntimeException $e) {
    jsonError($e->getMessage(), 500);
}

$term = currentTermWindow();
logAudit(
    $user['uid'],
    'UPDATE',
    'student_evaluation',
    sprintf(
        'Approved %s (%s, %s) for %s enrollment.',
        $student['fullName'],
        $student['yearLevel'] !== '' ? $student['yearLevel'] : 'year unset',
        $student['studentType'] !== '' ? $student['studentType'] : 'Regular',
        $term['label']
    ),
    $studentId
);

jsonSuccess([
    'student' => $student,
    'term' => $term,
]);
