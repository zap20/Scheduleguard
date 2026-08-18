<?php

declare(strict_types=1);

/**
 * List students already in a class block + cleared students available to add.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ClassBlock.php';
require_once dirname(__DIR__, 2) . '/includes/StudentEvaluation.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);
$classBlockId = isset($_GET['classBlockId']) ? trim((string) $_GET['classBlockId']) : '';
if ($classBlockId === '') {
    jsonError('classBlockId is required.', 422);
}

$block = fetchClassBlockById($classBlockId);
if ($block === null) {
    jsonError('Class block not found.', 404);
}

if ($user['role'] === 'ProgramHead') {
    $own = userDepartmentId($user['uid']);
    if ($own === null || $own !== $block['departmentId']) {
        jsonError('Class block is outside your department.', 403);
    }
}

$available = [];
$evaluationQueue = [];
if ($user['role'] === 'ProgramHead') {
    $available = fetchStudentsAvailableForClassBlock($classBlockId, $block['departmentId']);
    $evaluationQueue = fetchStudentEvaluationQueue(
        $block['departmentId'],
        (string) ($block['yearLevel'] ?? ''),
        (string) ($block['studentType'] ?? 'Regular'),
        null
    );
    // Only show students not already in this block.
    $memberIds = [];
    foreach (fetchClassBlockMembers($classBlockId) as $m) {
        $memberIds[(string) $m['studentId']] = true;
    }
    $evaluationQueue = array_values(array_filter(
        $evaluationQueue,
        static function (array $s) use ($memberIds): bool {
            return !isset($memberIds[(string) $s['uid']]);
        }
    ));
}

jsonSuccess([
    'block' => $block,
    'members' => fetchClassBlockMembers($classBlockId),
    'availableStudents' => $available,
    'evaluationQueue' => $evaluationQueue,
]);
