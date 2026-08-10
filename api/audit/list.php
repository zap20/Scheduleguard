<?php

declare(strict_types=1);

/**
 * Read-only audit trail viewer. RBAC: Dean only.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Audit.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['Dean']);

$module = isset($_GET['module']) ? trim((string) $_GET['module']) : '';
$userId = isset($_GET['userId']) ? trim((string) $_GET['userId']) : '';
$dateFrom = isset($_GET['dateFrom']) ? trim((string) $_GET['dateFrom']) : '';
$dateTo = isset($_GET['dateTo']) ? trim((string) $_GET['dateTo']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$pageSize = (int) ($_GET['pageSize'] ?? 20);
if ($pageSize < 1) {
    $pageSize = 20;
}
if ($pageSize > 100) {
    $pageSize = 100;
}

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    jsonError('dateFrom must be YYYY-MM-DD.', 422);
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    jsonError('dateTo must be YYYY-MM-DD.', 422);
}

$result = fetchAuditLogs(
    $module !== '' ? $module : null,
    $userId !== '' ? $userId : null,
    $dateFrom !== '' ? $dateFrom : null,
    $dateTo !== '' ? $dateTo : null,
    $page,
    $pageSize
);

$total = $result['total'];
$totalPages = $total > 0 ? (int) ceil($total / $pageSize) : 1;

$actors = db()->query(
    'SELECT DISTINCT u.uid, u.firstName, u.lastName, u.email, u.role
     FROM auditLog a
     INNER JOIN `user` u ON u.uid = a.userId
     ORDER BY u.lastName ASC, u.firstName ASC'
)->fetchAll();

$actorOptions = array_map(static function (array $row): array {
    return [
        'uid' => (string) $row['uid'],
        'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
        'email' => (string) $row['email'],
        'role' => (string) $row['role'],
        'label' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName'])
            . ' (' . (string) $row['role'] . ')',
    ];
}, $actors);

jsonSuccess([
    'records' => $result['records'],
    'modules' => fetchAuditModules(),
    'users' => $actorOptions,
    'filters' => [
        'module' => $module !== '' ? $module : null,
        'userId' => $userId !== '' ? $userId : null,
        'dateFrom' => $dateFrom !== '' ? $dateFrom : null,
        'dateTo' => $dateTo !== '' ? $dateTo : null,
    ],
    'pagination' => [
        'page' => $page,
        'pageSize' => $pageSize,
        'total' => $total,
        'totalPages' => $totalPages,
    ],
]);
