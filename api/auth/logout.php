<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

// Logout is allowed for any authenticated role.
$user = requireRoles([]);

clearApiToken($user['uid']);

logAudit(
    $user['uid'],
    'LOGOUT',
    'auth',
    'User logged out.'
);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
}

session_destroy();

jsonSuccess(['message' => 'Logged out.']);
