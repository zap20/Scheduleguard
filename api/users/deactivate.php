<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/UserManagement.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$actor = requireRoles(['Dean']);
$body = requestBody();
$userId = trim((string) ($body['uid'] ?? $body['userId'] ?? ''));

if ($userId === '') {
    jsonError('uid (or userId) is required.', 422);
}

if ($userId === $actor['uid']) {
    jsonError('You cannot deactivate your own account.', 422);
}

$existing = fetchManagedUserById($userId);
if ($existing === null) {
    jsonError('User not found.', 404);
}

try {
    $user = deactivateManagedUser($userId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $actor['uid'],
    'DEACTIVATE',
    'user_management',
    sprintf(
        'Deactivated user %s (%s). Previous status: %s. Role at deactivation: %s.',
        $user['fullName'],
        $user['email'],
        $existing['status'],
        $user['role']
    ),
    $user['uid']
);

jsonSuccess(['user' => $user]);
