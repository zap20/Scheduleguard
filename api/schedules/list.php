<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['Dean']);

$status = isset($_GET['status']) ? trim((string) $_GET['status']) : '';
$departmentId = isset($_GET['departmentId']) ? trim((string) $_GET['departmentId']) : '';
$academicYearRaw = isset($_GET['academicYear']) ? trim((string) $_GET['academicYear']) : '';
$semester = isset($_GET['semester']) ? trim((string) $_GET['semester']) : '';
$yearLevel = isset($_GET['yearLevel']) ? trim((string) $_GET['yearLevel']) : '';
$blockNumberRaw = isset($_GET['blockNumber']) ? trim((string) $_GET['blockNumber']) : '';
$blockName = isset($_GET['blockName']) ? trim((string) $_GET['blockName']) : '';

$academicYear = $academicYearRaw !== '' ? (int) $academicYearRaw : null;
if ($academicYearRaw !== '' && ($academicYear < 2000 || $academicYear > 2100)) {
    jsonError('academicYear must be between 2000 and 2100.', 422);
}

if ($yearLevel !== '' && !in_array($yearLevel, SUBJECT_YEAR_LEVELS, true)) {
    jsonError('yearLevel must be one of: ' . implode(', ', SUBJECT_YEAR_LEVELS), 422);
}

$blockNumber = null;
if ($blockNumberRaw !== '') {
    if (!ctype_digit($blockNumberRaw) || (int) $blockNumberRaw < 1) {
        jsonError('blockNumber must be a positive integer.', 422);
    }
    $blockNumber = (int) $blockNumberRaw;
}

$schedules = fetchSchedules(
    $status !== '' ? $status : null,
    $departmentId !== '' ? $departmentId : null,
    $academicYear,
    $semester !== '' ? $semester : null,
    $yearLevel !== '' ? $yearLevel : null,
    $blockNumber,
    $blockName !== '' ? $blockName : null
);

$filterOptions = fetchScheduleFilterOptions(
    $departmentId !== '' ? $departmentId : null,
    $academicYear,
    $semester !== '' ? $semester : null
);

jsonSuccess([
    'schedules' => $schedules,
    'count' => count($schedules),
    'term' => currentTermWindow(),
    'filterOptions' => $filterOptions,
    'filters' => [
        'status' => $status !== '' ? $status : null,
        'departmentId' => $departmentId !== '' ? $departmentId : null,
        'academicYear' => $academicYear,
        'semester' => $semester !== '' ? $semester : null,
        'yearLevel' => $yearLevel !== '' ? $yearLevel : null,
        'blockNumber' => $blockNumber,
        'blockName' => $blockName !== '' ? $blockName : null,
    ],
]);
