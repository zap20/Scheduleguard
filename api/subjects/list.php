<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);

$departmentId = isset($_GET['departmentId']) ? trim((string) $_GET['departmentId']) : '';
$yearLevel = isset($_GET['yearLevel']) ? trim((string) $_GET['yearLevel']) : '';
$semester = isset($_GET['semester']) ? trim((string) $_GET['semester']) : '';
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : SUBJECT_STATUS_ACTIVE;
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$subjectType = isset($_GET['subjectType']) ? trim((string) $_GET['subjectType']) : '';
$servingDepartmentId = isset($_GET['servingDepartmentId'])
    ? trim((string) $_GET['servingDepartmentId'])
    : '';
$curriculumYearRaw = isset($_GET['curriculumYear']) ? trim((string) $_GET['curriculumYear']) : '';
$curriculumYear = $curriculumYearRaw !== '' ? (int) $curriculumYearRaw : null;

if ($status === 'all') {
    $status = null;
}
if ($subjectType === 'all') {
    $subjectType = '';
}

$scopedDept = resolveOwnedSubjectDepartmentScope($user, $departmentId);
if (($user['role'] ?? '') === 'ProgramHead' && $scopedDept === null) {
    jsonError('Program Head has no assigned department.', 403);
}
$departmentId = $scopedDept ?? '';

try {
    $subjects = fetchSubjects(
        $departmentId !== '' ? $departmentId : null,
        $yearLevel !== '' ? $yearLevel : null,
        $semester !== '' ? $semester : null,
        $status,
        $search,
        $curriculumYear,
        $subjectType !== '' ? $subjectType : null,
        false,
        $servingDepartmentId !== '' ? $servingDepartmentId : null
    );
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

jsonSuccess([
    'subjects' => $subjects,
    'filters' => [
        'departmentId' => $departmentId !== '' ? $departmentId : null,
        'yearLevel' => $yearLevel !== '' ? $yearLevel : null,
        'semester' => $semester !== '' ? $semester : null,
        'subjectType' => $subjectType !== '' ? $subjectType : null,
        'servingDepartmentId' => $servingDepartmentId !== '' ? $servingDepartmentId : null,
        'status' => $status,
        'curriculumYear' => $curriculumYear,
        'q' => $search !== '' ? $search : null,
    ],
    'meta' => [
        'yearLevels' => SUBJECT_YEAR_LEVELS,
        'semesters' => SUBJECT_SEMESTERS,
        'subjectTypes' => SUBJECT_TYPES,
    ],
    'canWrite' => true,
]);
