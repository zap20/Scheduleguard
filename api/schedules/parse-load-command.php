<?php

declare(strict_types=1);

/**
 * Parse a faculty load command (does not assign yet).
 * Body: { "command": "8 loads" | "8 loads CICT-ATT", "facultyId": "..." }
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

$preview = null;
$error = null;
if ($parsed['ready']) {
    try {
        $preview = planFacultyLoadAssignment($parsed, $departmentId, false, $facultyId);
        if (isset($preview['parsed']) && is_array($preview['parsed'])) {
            $parsed = array_merge($parsed, $preview['parsed']);
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    }
}

logAudit(
    $user['uid'],
    'PARSE',
    'faculty_load',
    sprintf(
        'Parsed load command "%s" for faculty %s → load=%s subject=%s ready=%s',
        $raw,
        $facultyId,
        $parsed['targetLoad'],
        $parsed['subjectCode'] !== '' ? $parsed['subjectCode'] : '(random)',
        $parsed['ready'] ? 'yes' : 'no'
    ),
    null
);

jsonSuccess([
    'parsed' => $parsed,
    'preview' => $preview,
    'previewError' => $error,
]);
