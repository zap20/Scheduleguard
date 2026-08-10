<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();

try {
    $data = validateScheduleInput($body);
    $schedule = createDraftSchedule($data, $user['uid']);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'CREATE',
    'schedule',
    sprintf(
        'Created draft schedule "%s" (%s %s–%s).',
        $schedule['subjectCode'],
        $schedule['day'],
        $schedule['startTime'],
        $schedule['endTime']
    ),
    $schedule['uid']
);

jsonSuccess(['schedule' => $schedule], 201);
