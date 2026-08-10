<?php

declare(strict_types=1);

/**
 * Dean view of confirmed faculty teaching schedules (same data faculty see).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['Dean']);

$facultyId = isset($_GET['facultyId']) ? trim((string) $_GET['facultyId']) : '';

jsonSuccess([
    'term' => currentTermWindow(),
    'schedules' => fetchDeanFacultySchedules($facultyId !== '' ? $facultyId : null),
]);
