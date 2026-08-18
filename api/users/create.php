<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/UserManagement.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$actor = requireRoles(['Dean', 'HR']);
$body = requestBody();

if (!actorManagesAllUsers($actor)) {
    $ownDept = userDepartmentId($actor['uid']);
    if ($ownDept === null) {
        jsonError('Dean has no assigned department.', 403);
    }
    $body['departmentId'] = $ownDept;
}

try {
    assertActorMayAssignRole($actor, trim((string) ($body['role'] ?? '')));
    $user = createManagedUser($body);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $actor['uid'],
    'CREATE',
    'user_management',
    sprintf(
        'Created user %s (%s) with role %s, status %s.',
        $user['fullName'],
        $user['email'],
        $user['role'],
        $user['status']
    ),
    $user['uid']
);

jsonSuccess(['user' => $user], 201);
