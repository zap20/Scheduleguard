<?php

declare(strict_types=1);

/**
 * Faculty teaching schedule — own confirmed schedule rows only.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Faculty']);

jsonSuccess([
    'term' => currentTermWindow(),
    'schedules' => fetchFacultyOwnSchedules($user['uid']),
]);
