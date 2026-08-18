<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    jsonError('file is required (CSV).', 422);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jsonError('File upload failed.', 422);
}

$originalName = (string) ($file['name'] ?? 'upload.csv');
$tmpPath = (string) ($file['tmp_name'] ?? '');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext !== 'csv') {
    jsonError('Upload a CSV file using the curriculum template.', 422);
}

$departmentId = isset($_POST['departmentId']) ? trim((string) $_POST['departmentId']) : '';
$scopedDept = resolveOwnedSubjectDepartmentScope($user, $departmentId);
if (($user['role'] ?? '') === 'ProgramHead' && $scopedDept === null) {
    jsonError('Program Head has no assigned department.', 403);
}
$departmentId = $scopedDept ?? '';
if ($departmentId === '') {
    jsonError('departmentId is required.', 422);
}

$yearRaw = isset($_POST['curriculumYear']) ? trim((string) $_POST['curriculumYear']) : '';
$yearOverride = $yearRaw !== '' ? (int) $yearRaw : null;
if ($yearOverride !== null && ($yearOverride < 2000 || $yearOverride > 2100)) {
    jsonError('curriculumYear must be between 2000 and 2100.', 422);
}

try {
    $rows = parseCurriculumCsv($tmpPath);
    $result = importCurriculumSubjectsFromRows($rows, $departmentId, $yearOverride);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
} catch (Throwable $e) {
    jsonError('Unable to import curriculum: ' . $e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'IMPORT',
    'subject',
    sprintf(
        'Imported curriculum from %s: %d created, %d updated, %d skipped, %d failed.',
        $originalName,
        $result['created'],
        $result['updated'],
        $result['skipped'],
        count($result['failed'])
    )
);

jsonSuccess([
    'createdCount' => $result['created'],
    'updatedCount' => $result['updated'],
    'skippedCount' => $result['skipped'],
    'failedCount' => count($result['failed']),
    'failed' => array_slice($result['failed'], 0, 50),
    'curriculumYear' => $yearOverride,
]);
