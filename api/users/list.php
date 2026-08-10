<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/UserManagement.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['Dean']);

$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$role = isset($_GET['role']) ? trim((string) $_GET['role']) : '';
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = (int) ($_GET['pageSize'] ?? 10);
if ($pageSize < 1) {
    $pageSize = 10;
}
if ($pageSize > 100) {
    $pageSize = 100;
}

$result = fetchManagedUsers(
    $search,
    $role !== '' ? $role : null,
    $status !== '' ? $status : null,
    $page,
    $pageSize
);

$total = $result['total'];
$totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

jsonSuccess([
    'users' => $result['records'],
    'roles' => USER_ROLES,
    'pagination' => [
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => $total,
        'totalPages' => $totalPages,
    ],
]);
