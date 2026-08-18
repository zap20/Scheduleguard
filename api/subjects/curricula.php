<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);

$departmentId = isset($_GET['departmentId']) ? trim((string) $_GET['departmentId']) : '';
$year = isset($_GET['curriculumYear']) ? (int) $_GET['curriculumYear'] : 0;
$compareFrom = isset($_GET['compareFrom']) ? (int) $_GET['compareFrom'] : 0;

$scopedDept = resolveOwnedSubjectDepartmentScope($user, $departmentId);
if (($user['role'] ?? '') === 'ProgramHead' && $scopedDept === null) {
    jsonError('Program Head has no assigned department.', 403);
}
$departmentId = $scopedDept ?? '';

$summaries = fetchCurriculumSummaries($departmentId !== '' ? $departmentId : null);
$selectedYear = $year > 0 ? $year : (int) ($summaries[0]['curriculumYear'] ?? 0);

$subjects = [];
$compare = null;
if ($selectedYear > 0) {
    $subjects = fetchSubjects(
        $departmentId !== '' ? $departmentId : null,
        null,
        null,
        SUBJECT_STATUS_ACTIVE,
        '',
        $selectedYear
    );

    $fromYear = $compareFrom;
    if ($fromYear <= 0) {
        foreach ($summaries as $row) {
            if ((int) $row['curriculumYear'] < $selectedYear) {
                $fromYear = (int) $row['curriculumYear'];
                break;
            }
        }
    }
    if ($fromYear > 0 && $departmentId !== '') {
        $compare = compareCurriculumYears($departmentId, $fromYear, $selectedYear);
    }
}

jsonSuccess([
    'curricula' => $summaries,
    'curriculumYear' => $selectedYear > 0 ? $selectedYear : null,
    'subjects' => $subjects,
    'compare' => $compare,
    'filters' => [
        'departmentId' => $departmentId !== '' ? $departmentId : null,
    ],
]);
