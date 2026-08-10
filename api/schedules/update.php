<?php

declare(strict_types=1);

/**
 * Edit a schedule row (typically to resolve conflicts). Resets status to draft.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['POST', 'PUT', 'PATCH'], true)) {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();
$scheduleId = trim((string) ($body['uid'] ?? $body['scheduleId'] ?? ''));

if ($scheduleId === '') {
    jsonError('uid (or scheduleId) is required.', 422);
}

$existing = fetchScheduleById($scheduleId);
if ($existing === null) {
    jsonError('Schedule not found.', 404);
}

try {
    $data = validateScheduleInput($body);
    $schedule = updateScheduleFields($scheduleId, $data);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'UPDATE',
    'schedule',
    sprintf(
        'Updated schedule "%s" (status reset to draft).',
        $schedule['subjectCode']
    ),
    $scheduleId
);

jsonSuccess(['schedule' => $schedule]);
