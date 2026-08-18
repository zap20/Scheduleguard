<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$auth = requireRoles([]);

$stmt = db()->prepare(
    'SELECT uid, departmentId, firstName, lastName, email, role, phoneNumber, status, createdAt
     FROM userProfile
     WHERE uid = :uid
     LIMIT 1'
);
$stmt->execute([':uid' => $auth['uid']]);
$user = $stmt->fetch();

if (!$user) {
    jsonError('User not found.', 404);
}

jsonSuccess([
    'user' => publicUser($user),
    'role' => $user['role'],
]);
