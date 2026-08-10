<?php

declare(strict_types=1);

/**
 * Create a new block. RBAC: ProgramHead, Dean.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Blocking.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead', 'Dean']);
$body = requestBody();

$studentId = trim((string) ($body['studentId'] ?? ''));
$departmentId = trim((string) ($body['departmentId'] ?? ''));
$reason = trim((string) ($body['reason'] ?? ''));

if ($studentId === '' || $departmentId === '' || $reason === '') {
    jsonError('studentId, departmentId, and reason are required.', 422);
}

if ($user['role'] === 'ProgramHead') {
    $ownDept = userDepartmentId($user['uid']);
    if ($ownDept === null) {
        jsonError('Program Head has no assigned department.', 403);
    }
    if ($departmentId !== $ownDept) {
        jsonError('Program Head may only issue blocks for their own department.', 403);
    }
}

assertStudentExists($studentId);
assertDepartmentExists($departmentId);

$blockId = generateUid();

$stmt = db()->prepare(
    'INSERT INTO block (uid, studentId, departmentId, issuedBy, reason, status, createdAt)
     VALUES (:uid, :studentId, :departmentId, :issuedBy, :reason, :status, NOW())'
);
$stmt->execute([
    ':uid' => $blockId,
    ':studentId' => $studentId,
    ':departmentId' => $departmentId,
    ':issuedBy' => $user['uid'],
    ':reason' => $reason,
    ':status' => BLOCK_STATUS_ACTIVE,
]);

$block = fetchBlockById($blockId);

logAudit(
    $user['uid'],
    'CREATE',
    'blocking',
    sprintf(
        'Issued Active block for %s in %s: %s',
        $block['studentName'] ?? $studentId,
        $block['departmentName'] ?? $departmentId,
        $reason
    ),
    $blockId
);

jsonSuccess([
    'block' => $block,
    'studentCleared' => isStudentCleared($studentId),
], 201);
