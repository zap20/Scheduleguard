<?php

declare(strict_types=1);

/**
 * List class section blocks (Dean + Program Head).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ClassBlock.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);

$departmentId = isset($_GET['departmentId']) ? trim((string) $_GET['departmentId']) : '';
$yearLevel = isset($_GET['yearLevel']) ? trim((string) $_GET['yearLevel']) : '';

if ($user['role'] === 'ProgramHead') {
    $own = userDepartmentId($user['uid']);
    if ($own === null) {
        jsonError('Program Head has no assigned department.', 403);
    }
    $departmentId = $own;
} elseif ($departmentId === '') {
    $own = userDepartmentId($user['uid']);
    if ($own !== null) {
        $departmentId = $own;
    }
}

$term = currentTermWindow();

jsonSuccess([
    'term' => $term,
    'yearLevels' => SUBJECT_YEAR_LEVELS,
    'blocks' => fetchClassBlocks(
        $departmentId !== '' ? $departmentId : null,
        $yearLevel !== '' ? $yearLevel : null,
        $term['academicYear'],
        $term['semester']
    ),
]);
