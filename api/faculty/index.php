<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/UserManagement.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['Dean', 'ProgramHead', 'HR']);

$stmt = db()->query(
    "SELECT uid, firstName, lastName, email, schoolId, departmentId, employmentType
     FROM userProfile
     WHERE role = 'Faculty' AND status = 'Active'
     ORDER BY lastName ASC, firstName ASC"
);

$faculty = array_map(static function (array $row): array {
    $employmentType = normalizeFacultyEmploymentType(
        isset($row['employmentType']) ? (string) $row['employmentType'] : 'Regular',
        false
    ) ?? 'Regular';
    return [
        'uid' => (string) $row['uid'],
        'firstName' => (string) $row['firstName'],
        'lastName' => (string) $row['lastName'],
        'email' => (string) $row['email'],
        'schoolId' => (string) ($row['schoolId'] ?? ''),
        'departmentId' => $row['departmentId'] !== null ? (string) $row['departmentId'] : null,
        'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
        'employmentType' => $employmentType,
        'minLoad' => facultyMinLoadForEmploymentType($employmentType),
    ];
}, $stmt->fetchAll());

jsonSuccess([
    'faculty' => $faculty,
    'minLoads' => [
        'Regular' => FACULTY_MIN_LOAD_REGULAR,
        'PartTime' => FACULTY_MIN_LOAD_PART_TIME,
    ],
]);
