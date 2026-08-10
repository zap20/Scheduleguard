<?php

declare(strict_types=1);

/**
 * Dean manual override: confirm a schedule even when conflicts exist.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();
$scheduleId = trim((string) ($body['uid'] ?? $body['scheduleId'] ?? ''));

if ($scheduleId === '') {
    jsonError('uid (or scheduleId) is required.', 422);
}

try {
    $result = overrideConfirmSchedule($scheduleId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 404);
}

$schedule = $result['schedule'];
$conflictCount = count($result['conflicts']);

logAudit(
    $user['uid'],
    'OVERRIDE',
    'schedule',
    sprintf(
        'Override-confirmed schedule "%s" despite %d conflict(s).',
        $schedule['subjectCode'] ?? $scheduleId,
        $conflictCount
    ),
    $scheduleId
);

jsonSuccess([
    'confirmed' => true,
    'overridden' => true,
    'schedule' => $schedule,
    'conflicts' => $result['conflicts'],
]);
