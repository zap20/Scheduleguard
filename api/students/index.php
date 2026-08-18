<?php

declare(strict_types=1);

/**
 * Active students for blocking (and later enrollment) forms.
 * ProgramHead is scoped to their department when departmentId is set on their user.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Blocking.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead', 'Dean', 'HR']);

$departmentId = isset($_GET['departmentId']) ? trim((string) $_GET['departmentId']) : '';

if (in_array($user['role'], ['ProgramHead', 'Dean'], true)) {
    $ownDept = userDepartmentId($user['uid']);
    if ($user['role'] === 'ProgramHead' && $ownDept === null) {
        jsonError('Program Head has no assigned department.', 403);
    }
    if ($ownDept !== null) {
        $departmentId = $ownDept;
    }
}

$sql = 'SELECT uid, firstName, lastName, email, schoolId, departmentId, status
        FROM userProfile
        WHERE role = \'Student\' AND status = \'Active\'';
$params = [];

if ($departmentId !== '') {
    $sql .= ' AND departmentId = :departmentId';
    $params[':departmentId'] = $departmentId;
}

$sql .= ' ORDER BY lastName ASC, firstName ASC';

$stmt = db()->prepare($sql);
$stmt->execute($params);

$students = array_map(static function (array $row): array {
    return [
        'uid' => (string) $row['uid'],
        'firstName' => (string) $row['firstName'],
        'lastName' => (string) $row['lastName'],
        'email' => (string) $row['email'],
        'schoolId' => (string) ($row['schoolId'] ?? ''),
        'departmentId' => $row['departmentId'] !== null ? (string) $row['departmentId'] : null,
        'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
    ];
}, $stmt->fetchAll());

jsonSuccess(['students' => $students]);
