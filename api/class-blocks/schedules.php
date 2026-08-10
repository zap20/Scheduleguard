<?php

declare(strict_types=1);

/**
 * List schedules belonging to a class block (table view for Dean / Program Head).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ClassBlock.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';

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

jsonSuccess([
    'block' => $block,
    'schedules' => fetchSchedulesForClassBlock($classBlockId),
]);
