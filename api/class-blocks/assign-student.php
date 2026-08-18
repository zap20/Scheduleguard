<?php

declare(strict_types=1);

/**
 * Program Head assigns a cleared student to a Dean-created class block.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ClassBlock.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead']);
$departmentId = requireProgramHeadDepartment($user['uid']);
$body = requestBody();

$classBlockId = trim((string) ($body['classBlockId'] ?? ''));
$studentId = trim((string) ($body['studentId'] ?? ''));

if ($classBlockId === '' || $studentId === '') {
    jsonError('classBlockId and studentId are required.', 422);
}

$block = fetchClassBlockById($classBlockId);
if ($block === null) {
    jsonError('Class block not found.', 404);
}
if ($block['departmentId'] !== $departmentId) {
    jsonError('Class block is outside your department.', 403);
}

try {
    $result = assignStudentToClassBlock($classBlockId, $studentId, $user['uid']);
} catch (DomainException $e) {
    $message = $e->getMessage();
    if (str_starts_with($message, 'NOT_CLEARED:')) {
        $reason = substr($message, strlen('NOT_CLEARED:'));
        jsonError('Student is not cleared and cannot be added to a class block.', 422, [
            'code' => 'NOT_CLEARED',
            'blockReason' => $reason,
        ]);
    }
    if (str_starts_with($message, 'NOT_EVALUATED:')) {
        $reason = substr($message, strlen('NOT_EVALUATED:'));
        jsonError('Student must be evaluated and Approved before assignment.', 422, [
            'code' => 'NOT_EVALUATED',
            'blockReason' => $reason,
        ]);
    }
    jsonError($message, 422);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
} catch (RuntimeException $e) {
    jsonError($e->getMessage(), 500);
}

logAudit(
    $user['uid'],
    'ASSIGN',
    'class_block',
    sprintf(
        'Added %s to class block "%s" (%d schedule enrollment(s)).',
        $result['member']['studentName'],
        $block['name'],
        $result['enrollmentCount']
    ),
    $result['member']['uid']
);

jsonSuccess($result, 201);
