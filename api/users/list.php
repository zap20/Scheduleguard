<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/UserManagement.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$actor = requireRoles(['Dean', 'HR']);

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

$departmentId = null;
$roleAllowlist = null;
$alwaysInclude = null;
$creatableRoles = rolesCreatableByActor($actor);

if (!actorManagesAllUsers($actor)) {
    $ownDept = userDepartmentId($actor['uid']);
    if ($ownDept === null) {
        jsonError('Dean has no assigned department.', 403);
    }
    $departmentId = $ownDept;
    $roleAllowlist = DEAN_MANAGEABLE_ROLES;
    $alwaysInclude = $actor['uid'];
}

$result = fetchManagedUsers(
    $search,
    $role !== '' ? $role : null,
    $status !== '' ? $status : null,
    $page,
    $pageSize,
    $departmentId,
    $roleAllowlist,
    $alwaysInclude
);

$total = $result['total'];
$totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

jsonSuccess([
    'users' => $result['records'],
    'roles' => $creatableRoles,
    'filterRoles' => actorManagesAllUsers($actor) ? USER_ROLES : array_values(array_unique(array_merge(
        DEAN_MANAGEABLE_ROLES,
        [$actor['role']]
    ))),
    'managesAll' => actorManagesAllUsers($actor),
    'pagination' => [
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => $total,
        'totalPages' => $totalPages,
    ],
]);
