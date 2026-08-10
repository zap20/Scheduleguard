<?php

declare(strict_types=1);

/**
 * Preview AI schedule options (does NOT write schedule rows).
 * Dean only.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';
require_once dirname(__DIR__, 2) . '/includes/ScheduleOptimizer.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();

$departmentId = trim((string) ($body['departmentId'] ?? ''));
$yearLevel = trim((string) ($body['yearLevel'] ?? ''));
$semester = trim((string) ($body['semester'] ?? ''));
$curriculumYear = isset($body['curriculumYear']) ? (int) $body['curriculumYear'] : null;
$optionCount = max(1, min(8, (int) ($body['optionCount'] ?? 3)));

$ownDept = userDepartmentId($user['uid']);
if ($ownDept === null) {
    jsonError('Dean account has no assigned department.', 403);
}
$departmentId = $ownDept;

if ($yearLevel === '' || $semester === '') {
    jsonError('yearLevel and semester are required.', 422);
}

try {
    $input = buildScheduleOptimizerInputFromDb(
        $departmentId,
        $yearLevel,
        $semester,
        $optionCount,
        $curriculumYear
    );
    if ($input['subjects'] === []) {
        jsonError('No active curriculum subjects found for that year level and semester.', 422);
    }
    if ($input['faculty'] === []) {
        jsonError('No active faculty available for scheduling.', 422);
    }
    if ($input['rooms'] === []) {
        jsonError('No rooms available for scheduling.', 422);
    }
    $result = generateScheduleOptions($input);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'GENERATE',
    'schedule_options',
    sprintf(
        'Generated %d draft schedule option(s) for %s / %s / dept %s.',
        count($result['options']),
        $yearLevel,
        $semester,
        $departmentId
    ),
    null
);

jsonSuccess(['result' => $result]);
