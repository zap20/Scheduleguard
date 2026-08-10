<?php

declare(strict_types=1);

/**
 * Persist a generated plan (one or more consecutive "{YearLevel} Block N" sets).
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
$plan = $body['plan'] ?? null;
$option = $body['option'] ?? null;

if (!is_array($plan) && is_array($option)) {
    // Legacy: single option → wrap as one-block plan.
    $plan = ['blocks' => [$option]];
}

if (!is_array($plan)) {
    jsonError('plan is required (one ranked plan from generate-from-command).', 422);
}

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
    if (!$parsed['ready']) {
        jsonError('Command is incomplete. Missing: ' . implode(', ', $parsed['missing']), 422);
    }

    $saved = saveGeneratedSchedulePlan($parsed, $plan, $user['uid']);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
} catch (RuntimeException $e) {
    jsonError($e->getMessage(), 500);
}

logAudit(
    $user['uid'],
    'SAVE',
    'schedule_command',
    sprintf(
        'Saved block(s) [%s] from command "%s" (yearLevel=%s studentType=%s curriculumYear=%s blockCount=%d dayCount=%s) — %d confirmed, %d conflict.',
        implode(', ', $saved['blockNames']),
        $parsed['rawCommand'],
        $parsed['yearLevel'],
        $parsed['studentType'],
        $parsed['curriculumYear'],
        $parsed['blockCount'],
        $parsed['dayCount'] ?? 'full',
        $saved['confirmedCount'],
        $saved['conflictCount']
    ),
    $saved['blocks'][0]['schedules'][0]['uid'] ?? null
);

jsonSuccess([
    'parsed' => $parsed,
    'blockNames' => $saved['blockNames'],
    'blockName' => $saved['blockNames'][0] ?? null,
    'blocks' => $saved['blocks'],
    'confirmedCount' => $saved['confirmedCount'],
    'conflictCount' => $saved['conflictCount'],
]);
