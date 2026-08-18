<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/UserManagement.php';

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH'], true)) {
    jsonError('Method not allowed.', 405);
}

$actor = requireRoles(['Dean', 'HR']);
$body = requestBody();
$userId = trim((string) ($body['uid'] ?? $body['userId'] ?? ''));

if ($userId === '') {
    jsonError('uid (or userId) is required.', 422);
}

$existing = fetchManagedUserById($userId);
if ($existing === null) {
    jsonError('User not found.', 404);
}

try {
    assertActorMayManageTarget($actor, $existing);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 403);
}

if (!actorManagesAllUsers($actor)) {
    $ownDept = userDepartmentId($actor['uid']);
    if ($ownDept !== null) {
        $body['departmentId'] = $ownDept;
    }
    $newRole = array_key_exists('role', $body)
        ? trim((string) $body['role'])
        : (string) $existing['role'];
    if ($newRole !== (string) $existing['role']) {
        try {
            assertActorMayAssignRole($actor, $newRole);
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage(), 403);
        }
    }
}

try {
    $result = updateManagedUser($userId, $body);
} catch (InvalidArgumentException $e) {
    $message = $e->getMessage();
    jsonError($message, $message === 'User not found.' ? 404 : 422);
}

$user = $result['user'];

$parts = [
    sprintf('Updated user %s (%s).', $user['fullName'], $user['email']),
];

if ($result['roleChanged']) {
    $parts[] = sprintf(
        'Role changed FROM %s TO %s.',
        $result['previousRole'],
        $user['role']
    );

    logAudit(
        $actor['uid'],
        'ROLE_CHANGE',
        'user_management',
        sprintf(
            'Role change for %s (%s): FROM %s TO %s.',
            $user['fullName'],
            $user['email'],
            $result['previousRole'],
            $user['role']
        ),
        $user['uid']
    );
}

if ($result['statusChanged']) {
    $parts[] = sprintf(
        'Status changed FROM %s TO %s.',
        $result['previousStatus'],
        $user['status']
    );

    if ($user['status'] === USER_STATUS_INACTIVE) {
        logAudit(
            $actor['uid'],
            'DEACTIVATE',
            'user_management',
            sprintf(
                'Deactivated user %s (%s) via edit (status FROM %s TO %s).',
                $user['fullName'],
                $user['email'],
                $result['previousStatus'],
                $user['status']
            ),
            $user['uid']
        );
    } else {
        logAudit(
            $actor['uid'],
            'REACTIVATE',
            'user_management',
            sprintf(
                'Reactivated user %s (%s) (status FROM %s TO %s).',
                $user['fullName'],
                $user['email'],
                $result['previousStatus'],
                $user['status']
            ),
            $user['uid']
        );
    }
}

logAudit(
    $actor['uid'],
    'UPDATE',
    'user_management',
    implode(' ', $parts),
    $user['uid']
);

jsonSuccess([
    'user' => $user,
    'roleChanged' => $result['roleChanged'],
    'previousRole' => $result['previousRole'],
    'statusChanged' => $result['statusChanged'],
    'previousStatus' => $result['previousStatus'],
]);
