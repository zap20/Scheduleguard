<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);
$body = requestBody();
$subjectId = trim((string) ($body['uid'] ?? $body['subjectId'] ?? ''));
if ($subjectId === '') {
    jsonError('subjectId is required.', 422);
}

$existing = fetchSubjectById($subjectId);
if ($existing === null) {
    jsonError('Subject not found.', 404);
}

try {
    assertSubjectOwnedByUserDepartment($user, $existing);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 403);
}

$scopedDept = resolveOwnedSubjectDepartmentScope($user, (string) ($body['departmentId'] ?? ''));
if ($scopedDept !== null && $scopedDept !== '') {
    $body['departmentId'] = $scopedDept;
}

try {
    $subject = updateSubject($subjectId, $body);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'UPDATE',
    'subject',
    sprintf('Updated subject %s — %s.', $subject['code'], $subject['title']),
    $subject['uid']
);

jsonSuccess(['subject' => $subject]);
