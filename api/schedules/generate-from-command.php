<?php

declare(strict_types=1);

/**
 * After Dean confirms a parsed command, generate ranked multi-block plans.
 * Does not write schedule rows — use save-option.php to persist a chosen plan.
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
$planCount = max(1, min(5, (int) ($body['planCount'] ?? $body['optionCount'] ?? 3)));

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
        jsonError(
            'Command is incomplete. Missing: ' . implode(', ', $parsed['missing']),
            422
        );
    }

    $input = buildScheduleOptimizerInputFromDb(
        $parsed['departmentId'],
        (string) $parsed['yearLevel'],
        (string) $parsed['semester'],
        1,
        (int) $parsed['curriculumYear']
    );
    if ($input['subjects'] === []) {
        jsonError(
            sprintf(
                'No active subjects found for %s / %s / curriculum %d.',
                $parsed['yearLevel'],
                $parsed['semester'],
                $parsed['curriculumYear']
            ),
            422
        );
    }
    if ($input['rooms'] === []) {
        jsonError('No rooms available for scheduling.', 422);
    }

    $plans = generateScheduleCommandPlans($input, $parsed, $planCount);
    if ($plans === []) {
        jsonError(
            'Could not build plans: no free rooms for the requested subjects/blocks/days. '
            . 'Add rooms, free existing bookings, use more weekdays, or fewer blocks.',
            422
        );
    }

    $previewNames = $plans[0]['previewBlockNames'] ?? [];
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'GENERATE',
    'schedule_command',
    sprintf(
        'Generated %d plan(s) from "%s" → %s / %s / curriculum %d / %d block(s) / %s day(s) (preview %s).',
        count($plans),
        $parsed['rawCommand'],
        $parsed['yearLevel'],
        $parsed['studentType'],
        $parsed['curriculumYear'],
        $parsed['blockCount'],
        $parsed['dayCount'] ?? 'full',
        implode(', ', $previewNames)
    ),
    null
);

jsonSuccess([
    'parsed' => $parsed,
    'nextBlockName' => $previewNames[0] ?? null,
    'nextBlockNames' => $previewNames,
    'plans' => $plans,
    // Backward-compatible alias: first block of each plan as flat options.
    'result' => [
        'options' => array_map(static function (array $plan): array {
            $first = $plan['blocks'][0] ?? [];
            return array_merge($first, [
                'rank' => $plan['rank'],
                'score' => $plan['score'],
                'planBlockCount' => $plan['blockCount'],
            ]);
        }, $plans),
    ],
]);
