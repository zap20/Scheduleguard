<?php

declare(strict_types=1);

/**
 * Update Serves by department id and/or typed department name.
 * Typed names are find-or-created and then attached.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';
require_once dirname(__DIR__, 2) . '/includes/Department.php';

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
        jsonError('Program Head may only edit subjects in their department.', 403);
    }
}

if (strcasecmp((string) $existing['status'], SUBJECT_STATUS_ARCHIVED) === 0) {
    jsonError('Cannot change Serves on an archived subject.', 422);
}

$servingDepartmentId = trim((string) ($body['servingDepartmentId'] ?? ''));
$servingDepartmentName = trim((string) ($body['servingDepartmentName'] ?? ''));
$departmentCreated = false;

try {
    if ($servingDepartmentName !== '') {
        $dept = findOrCreateDepartmentByName($servingDepartmentName);
        $servingDepartmentId = $dept['uid'];
        $departmentCreated = $dept['created'];
        if ($departmentCreated) {
            logAudit(
                $user['uid'],
                'CREATE',
                'department',
                sprintf('Created department %s (from Serves).', $dept['name']),
                $dept['uid']
            );
        }
    } elseif ($servingDepartmentId !== '') {
        $stmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
        $stmt->execute([':uid' => $servingDepartmentId]);
        if (!$stmt->fetchColumn()) {
            jsonError('servingDepartmentId not found.', 422);
        }
    }
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

if ($servingDepartmentId === '') {
    $upd = db()->prepare(
        "UPDATE subject
         SET servingDepartmentId = NULL,
             subjectType = 'MAJOR'
         WHERE uid = :uid"
    );
    $upd->execute([':uid' => $subjectId]);
    $label = 'none (Major)';
} else {
    $upd = db()->prepare(
        "UPDATE subject
         SET servingDepartmentId = :servingDepartmentId,
             subjectType = 'MINOR'
         WHERE uid = :uid"
    );
    $upd->execute([
        ':servingDepartmentId' => $servingDepartmentId,
        ':uid' => $subjectId,
    ]);
}

$subject = fetchSubjectById($subjectId);
if ($subject === null) {
    jsonError('Failed to reload subject.', 500);
}

$label = $subject['servingDepartmentName'] !== ''
    ? $subject['servingDepartmentName']
    : 'none (Major)';

logAudit(
    $user['uid'],
    'UPDATE',
    'subject',
    sprintf('Updated Serves for %s → %s.', $subject['code'], $label),
    $subject['uid']
);

jsonSuccess([
    'subject' => $subject,
    'departmentCreated' => $departmentCreated,
]);
