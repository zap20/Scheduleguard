<?php

declare(strict_types=1);

/**
 * Confirm & assign faculty load from a parsed command.
 * Body: { "command": "8 loads" | "8 load IT 322", "facultyId": "...", "offeringKeys": ["..."] }
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/FacultyLoad.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();
$raw = trim((string) ($body['command'] ?? $body['rawCommand'] ?? ''));
$facultyId = trim((string) ($body['facultyId'] ?? ''));
$subjectCodeOverride = trim((string) ($body['subjectCode'] ?? ''));

if ($raw === '') {
    jsonError('command is required.', 422);
}
if ($facultyId === '') {
    jsonError('Select a faculty first.', 422);
}

$departmentId = userDepartmentId($user['uid']);
if ($departmentId === null) {
    jsonError('Dean account has no assigned department.', 403);
}

$parsed = parseFacultyLoadCommand($raw);
$selectedOfferingKeys = null;
if (isset($body['offeringKeys']) && is_array($body['offeringKeys'])) {
    $selectedOfferingKeys = array_values(array_filter(array_map('strval', $body['offeringKeys'])));
} elseif (isset($body['selectedOfferingKeys']) && is_array($body['selectedOfferingKeys'])) {
    $selectedOfferingKeys = array_values(array_filter(array_map('strval', $body['selectedOfferingKeys'])));
}

// Pin a subject from preview only when the typed command had no code AND
// the client did not send a multi-offering selection (e.g. "8 loads" across subjects).
$commandNamedSubject = trim((string) ($parsed['subjectCode'] ?? '')) !== '';
if (
    !$commandNamedSubject
    && $subjectCodeOverride !== ''
    && ($selectedOfferingKeys === null || $selectedOfferingKeys === [])
) {
    $parsed['subjectCode'] = strtoupper($subjectCodeOverride);
    $parsed['subjectSpecified'] = true;
}

try {
    $result = planFacultyLoadAssignment($parsed, $departmentId, true, $facultyId, $selectedOfferingKeys);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'ASSIGN',
    'faculty_load',
    sprintf(
        'Assigned %.2f load of %s to %s across %d offering(s) (%d meetings).',
        (float) $result['assignedLoad'],
        (string) $result['subject']['code'],
        (string) $result['faculty']['fullName'],
        count($result['assignments']),
        (int) $result['updatedMeetings']
    ),
    null
);

jsonSuccess([
    'parsed' => $parsed,
    'result' => $result,
]);
