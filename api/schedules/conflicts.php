<?php

declare(strict_types=1);

/**
 * Preview conflicts for a schedule without changing status.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['Dean']);

$scheduleId = isset($_GET['uid']) ? trim((string) $_GET['uid']) : '';
if ($scheduleId === '') {
    $scheduleId = isset($_GET['scheduleId']) ? trim((string) $_GET['scheduleId']) : '';
}
if ($scheduleId === '') {
    jsonError('uid (or scheduleId) is required.', 422);
}

try {
    $conflicts = findScheduleConflicts($scheduleId);
    $schedule = fetchScheduleById($scheduleId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 404);
}

jsonSuccess([
    'schedule' => $schedule,
    'conflicts' => $conflicts,
    'hasConflicts' => $conflicts !== [],
]);
