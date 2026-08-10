<?php

declare(strict_types=1);

/**
 * Filterable, paginated block list.
 * - ProgramHead: own department only
 * - Dean / HR: all departments (HR read-only on the client; this endpoint is GET)
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Blocking.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead', 'Dean', 'HR']);

$departmentId = isset($_GET['departmentId']) ? trim((string) $_GET['departmentId']) : '';
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = (int) ($_GET['pageSize'] ?? 10);
if ($pageSize < 1) {
    $pageSize = 10;
}
if ($pageSize > 100) {
    $pageSize = 100;
}

$scopedDepartmentId = $departmentId !== '' ? $departmentId : null;

if ($user['role'] === 'ProgramHead') {
    $ownDept = userDepartmentId($user['uid']);
    if ($ownDept === null) {
        jsonError('Program Head has no assigned department.', 403);
    }
    // Program Head may only see their department (ignore other filter values).
    $scopedDepartmentId = $ownDept;
}

try {
    $result = fetchBlocks($scopedDepartmentId, $status !== '' ? $status : null, $page, $pageSize);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

$total = $result['total'];
$totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

jsonSuccess([
    'records' => $result['records'],
    'filters' => [
        'departmentId' => $scopedDepartmentId,
        'status' => $status !== '' ? normalizeBlockStatus($status) : null,
    ],
    'pagination' => [
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => $total,
        'totalPages' => $totalPages,
    ],
    'canWrite' => in_array($user['role'], ['ProgramHead', 'Dean'], true),
]);
