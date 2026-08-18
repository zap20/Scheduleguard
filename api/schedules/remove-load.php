<?php

declare(strict_types=1);

/**
 * Remove a faculty load offering (unassign all meetings in the subject + block group).
 * Body: { "scheduleId": "..." }
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/FacultyLoad.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();
$scheduleId = trim((string) ($body['scheduleId'] ?? $body['uid'] ?? ''));

if ($scheduleId === '') {
    jsonError('scheduleId is required.', 422);
}

try {
    $result = removeFacultyLoadOfferingByScheduleId($scheduleId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'UNASSIGN',
    'faculty_load',
    sprintf(
        'Removed load of %s (%s) from %s — %d meeting(s) returned to TBF.',
        (string) $result['subjectCode'],
        (string) $result['blockName'],
        (string) $result['facultyName'],
        (int) $result['updatedMeetings']
    ),
    $scheduleId
);

jsonSuccess(['result' => $result]);
