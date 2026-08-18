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

$scopedDept = resolveOwnedSubjectDepartmentScope($user, $departmentId);
if (($user['role'] ?? '') === 'ProgramHead' && $scopedDept === null) {
    jsonError('Program Head has no assigned department.', 403);
}
$departmentId = $scopedDept ?? '';

if ($year <= 0) {
    $summaries = fetchCurriculumSummaries($departmentId !== '' ? $departmentId : null);
    $year = (int) ($summaries[0]['curriculumYear'] ?? 0);
}

if ($year <= 0) {
    jsonError('No curriculum year to download.', 422);
}

$subjects = fetchSubjects(
    $departmentId !== '' ? $departmentId : null,
    null,
    null,
    SUBJECT_STATUS_ACTIVE,
    '',
    $year
);

$csv = buildCurriculumExportCsv($subjects);
$filename = sprintf('curriculum-%d.csv', $year);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo "\xEF\xBB\xBF";
echo $csv;
exit;
