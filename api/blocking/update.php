<?php

declare(strict_types=1);

/**
 * Update an existing block's status. RBAC: ProgramHead, Dean.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Blocking.php';

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PATCH', 'PUT'], true)) {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead', 'Dean']);
$body = requestBody();

$blockId = trim((string) ($body['uid'] ?? $body['blockId'] ?? ''));
$statusInput = trim((string) ($body['status'] ?? ''));

if ($blockId === '' || $statusInput === '') {
    jsonError('uid (or blockId) and status are required.', 422);
}

$newStatus = normalizeBlockStatus($statusInput);
if ($newStatus === null) {
    jsonError('status must be Active or Cleared.', 422);
}

$existing = fetchBlockById($blockId);
if ($existing === null) {
    jsonError('Block not found.', 404);
}

if ($user['role'] === 'ProgramHead') {
    $ownDept = userDepartmentId($user['uid']);
    if ($ownDept === null || $existing['departmentId'] !== $ownDept) {
        jsonError('Program Head may only update blocks in their own department.', 403);
    }
}

$previousStatus = (string) $existing['status'];
if (strcasecmp($previousStatus, $newStatus) === 0) {
    jsonSuccess([
        'block' => $existing,
        'changed' => false,
        'studentCleared' => isStudentCleared((string) $existing['studentId']),
    ]);
}

$stmt = db()->prepare('UPDATE block SET status = :status WHERE uid = :uid');
$stmt->execute([
    ':status' => $newStatus,
    ':uid' => $blockId,
]);

$block = fetchBlockById($blockId);

logAudit(
    $user['uid'],
    'UPDATE',
    'blocking',
    sprintf(
        'Changed block status for %s from %s to %s.',
        $block['studentName'] ?? $existing['studentId'],
        $previousStatus,
        $newStatus
    ),
    $blockId
);

jsonSuccess([
    'block' => $block,
    'changed' => true,
    'studentCleared' => isStudentCleared((string) $existing['studentId']),
]);
