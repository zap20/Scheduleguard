<?php

declare(strict_types=1);

/**
 * Dean view of faculty teaching schedules (draft + confirmed + conflict).
 * Filters: facultyId (or TBF), roomId.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['Dean', 'Checker', 'ProgramHead', 'HR']);

$facultyId = isset($_GET['facultyId']) ? trim((string) $_GET['facultyId']) : '';
$roomId = isset($_GET['roomId']) ? trim((string) $_GET['roomId']) : '';

jsonSuccess([
    'term' => currentTermWindow(),
    'schedules' => fetchDeanFacultySchedules(
        $facultyId !== '' ? $facultyId : null,
        $roomId !== '' ? $roomId : null
    ),
]);
