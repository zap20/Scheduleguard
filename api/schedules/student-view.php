<?php

declare(strict_types=1);

/**
 * Dean view of distributed student schedules (same data students see).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Enrollment.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['Dean']);

$studentId = isset($_GET['studentId']) ? trim((string) $_GET['studentId']) : '';

jsonSuccess([
    'term' => currentTermWindow(),
    'schedules' => fetchDeanStudentSchedules($studentId !== '' ? $studentId : null),
]);
