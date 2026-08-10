<?php

declare(strict_types=1);

/**
 * Attempt draft/conflict → confirmed with server-side conflict detection.
 * On conflict: status becomes "conflict" and conflicting rows are returned (HTTP 200).
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
    $result = attemptConfirmSchedule($scheduleId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 404);
}

$schedule = $result['schedule'];

if ($result['confirmed']) {
    logAudit(
        $user['uid'],
        'CONFIRM',
        'schedule',
        sprintf('Confirmed schedule "%s" (conflict-free).', $schedule['subjectCode'] ?? $scheduleId),
        $scheduleId
    );

    jsonSuccess([
        'confirmed' => true,
        'schedule' => $schedule,
        'conflicts' => [],
    ]);
}

logAudit(
    $user['uid'],
    'CONFLICT',
    'schedule',
    sprintf(
        'Confirm blocked for "%s": %d conflict(s) detected; status set to conflict.',
        $schedule['subjectCode'] ?? $scheduleId,
        count($result['conflicts'])
    ),
    $scheduleId
);

jsonSuccess([
    'confirmed' => false,
    'schedule' => $schedule,
    'conflicts' => $result['conflicts'],
    'message' => 'Conflicts detected. Resolve by editing a row or confirm anyway (override).',
]);
