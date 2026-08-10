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

if ($user['role'] === 'ProgramHead') {
    $ownDept = userDepartmentId($user['uid']);
    if ($ownDept === null || $ownDept !== $existing['departmentId']) {
        jsonError('Program Head may only archive subjects in their department.', 403);
    }
}

try {
    $subject = archiveSubject($subjectId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'ARCHIVE',
    'subject',
    sprintf('Archived subject %s — %s.', $subject['code'], $subject['title']),
    $subject['uid']
);

jsonSuccess(['subject' => $subject]);
