<?php

declare(strict_types=1);

/**
 * Role-specific dashboard payload for the post-login landing page.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Dashboard.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles([]);

$period = isset($_GET['period']) ? trim((string) $_GET['period']) : 'monthly';

$payload = match ($user['role']) {
    'Faculty' => buildFacultyDashboard($user['uid']),
    'Student' => buildStudentDashboard($user['uid']),
    'Dean' => buildDeanDashboard($period),
    'HR' => buildHrDashboard(),
    'ProgramHead' => buildProgramHeadDashboard($user['uid']),
    default => [
        'role' => $user['role'],
        'message' => 'No web dashboard widgets are configured for this role yet.',
    ],
};

jsonSuccess([
    'user' => [
        'uid' => $user['uid'],
        'firstName' => $user['firstName'],
        'lastName' => $user['lastName'],
        'email' => $user['email'],
        'role' => $user['role'],
    ],
    'dashboard' => $payload,
]);
