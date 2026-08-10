<?php

declare(strict_types=1);

/**
 * Parse a Dean NL schedule-generation command (no generation yet).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ScheduleCommand.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();
$raw = trim((string) ($body['command'] ?? $body['rawCommand'] ?? ''));
$semesterOverride = isset($body['semester']) ? trim((string) $body['semester']) : null;

$departmentId = userDepartmentId($user['uid']);
if ($departmentId === null) {
    jsonError('Dean account has no assigned department.', 403);
}

try {
    $parsed = parseScheduleCommand($raw, $departmentId);
    $parsed = applyScheduleCommandSemesterDefault(
        $parsed,
        $semesterOverride !== '' ? $semesterOverride : null
    );
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'PARSE',
    'schedule_command',
    sprintf(
        'Parsed command "%s" → yearLevel=%s studentType=%s curriculumYear=%s semester=%s ready=%s',
        $parsed['rawCommand'],
        $parsed['yearLevel'] ?? '',
        $parsed['studentType'],
        $parsed['curriculumYear'] ?? '',
        $parsed['semester'] ?? '',
        $parsed['ready'] ? 'yes' : 'no'
    ),
    null
);

jsonSuccess(['parsed' => $parsed]);
