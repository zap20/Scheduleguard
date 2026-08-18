<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/UserManagement.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$actor = requireRoles(['Dean', 'HR']);

$userId = isset($_GET['uid']) ? trim((string) $_GET['uid']) : '';
if ($userId === '') {
    jsonError('uid is required.', 422);
}

$user = fetchManagedUserById($userId);
if ($user === null) {
    jsonError('User not found.', 404);
}

try {
    assertActorMayManageTarget($actor, $user);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 403);
}

jsonSuccess(['user' => $user]);
