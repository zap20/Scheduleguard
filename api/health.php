<?php

declare(strict_types=1);

/**
 * Example protected route demonstrating RBAC attachment.
 * Any authenticated Active role may call this endpoint.
 *
 * Later routes will pass specific roles, e.g.:
 *   $user = requireRoles(['Dean', 'HR', 'ProgramHead']);
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles([
    'Checker',
    'Faculty',
    'Dean',
    'HR',
    'ProgramHead',
    'Student',
]);

jsonSuccess([
    'status' => 'ok',
    'app' => (string) env('APP_NAME', 'ScheduleGuard'),
    'role' => $user['role'],
    'user' => [
        'uid' => $user['uid'],
        'email' => $user['email'],
    ],
]);
