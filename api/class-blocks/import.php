<?php

declare(strict_types=1);

/**
 * Dean import of student class blocks from a semester workbook
 * (BSIT / Course and Year + Block grids). IRREG bands are skipped.
 * Preview first (preview=1) lists Excel room conflicts (not GYM / Field / SEAIT).
 * Those shared venues auto-clone as GYM2, Field2, SEAIT2 when busy.
 * Commit with resolveConflicts=1 to auto-assign a vacant same-type room, or 0 to skip them.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ClassBlock.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';
require_once dirname(__DIR__, 2) . '/includes/SemScheduleImport.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    jsonError('file is required (XLSX).', 422);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jsonError('File upload failed.', 422);
}

$originalName = (string) ($file['name'] ?? 'upload.xlsx');
$tmpPath = (string) ($file['tmp_name'] ?? '');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext !== 'xlsx') {
    jsonError('Upload the semester XLSX template (Course and Year / Block sheets).', 422);
}

$departmentId = (string) (userDepartmentId($user['uid']) ?? '');
if ($departmentId === '') {
    jsonError('Dean has no assigned department.', 422);
}

$sheet = isset($_POST['sheet']) ? trim((string) $_POST['sheet']) : 'BSIT';
if ($sheet === '') {
    $sheet = 'BSIT';
}

$preview = isset($_POST['preview']) && (string) $_POST['preview'] === '1';
$allowReassign = isset($_POST['resolveConflicts']) && (string) $_POST['resolveConflicts'] === '1';

try {
    $groups = parseSemStudentBlockXlsx($tmpPath, $sheet);
    $plan = planStudentBlockImport($groups, $departmentId, $preview ? false : $allowReassign);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
} catch (Throwable $e) {
    jsonError('Unable to parse import file: ' . $e->getMessage(), 422);
}

$conflicts = $plan['conflicts'] ?? [];

if ($preview) {
    jsonSuccess([
        'preview' => true,
        'okCount' => count($plan['accepted']),
        'conflictCount' => count($conflicts),
        'clonedVenueCount' => (int) ($plan['clonedVenueCount'] ?? 0),
        'failedCount' => count($plan['failed']),
        'skippedIrregCount' => $plan['skippedIrreg'],
        'conflicts' => array_slice($conflicts, 0, 120),
        'failed' => array_slice($plan['failed'], 0, 40),
    ]);
}

$importedBlocks = [];
$importedMeetings = [];
$failed = $plan['failed'];
$skippedIrreg = $plan['skippedIrreg'];
$reassigned = $plan['reassigned'];
$clonedVenues = (int) ($plan['clonedVenueCount'] ?? 0);
$conflictCount = count($conflicts);

foreach ($plan['accepted'] as $item) {
    $group = $item['group'];
    $meeting = $item['meeting'];
    $subjectName = (string) ($meeting['subjectName'] ?? '');

    try {
        $block = ensureImportedClassBlock(
            $departmentId,
            (string) $group['yearLevel'],
            (int) $group['blockNumber'],
            (string) $group['blockName'],
            (int) ($meeting['academicYear'] ?? 0),
            (string) ($meeting['semester'] ?? ''),
            $user['uid'],
            'regular'
        );
    } catch (Throwable $e) {
        $failed[] = [
            'row' => (int) $item['seq'],
            'error' => $e->getMessage(),
            'source' => $meeting['_source'] ?? ($group['blockName'] ?? null),
        ];
        continue;
    }

    $importedBlocks[$block['uid']] = $block;

    try {
        $payload = [
            'facultyId' => '',
            'roomId' => (string) $item['roomId'],
            'departmentId' => $departmentId,
            'subjectCode' => (string) ($meeting['subjectCode'] ?? ''),
            'subjectName' => $subjectName,
            'day' => (string) ($meeting['day'] ?? ''),
            'startTime' => (string) ($meeting['startTime'] ?? ''),
            'endTime' => (string) ($meeting['endTime'] ?? ''),
            'academicYear' => (string) ($meeting['academicYear'] ?? ''),
            'semester' => (string) ($meeting['semester'] ?? ''),
            'yearLevel' => (string) $group['yearLevel'],
        ];
        $data = validateScheduleInput($payload);
        $data['yearLevel'] = $block['yearLevel'];
        $data['blockNumber'] = $block['blockNumber'];
        $data['blockName'] = $block['name'];
        $data['classBlockId'] = $block['uid'];
        $data['studentType'] = 'regular';
        $schedule = createDraftSchedule($data, $user['uid']);
        $importedMeetings[] = [
            'row' => (int) $item['seq'],
            'schedule' => $schedule,
            'source' => $meeting['_source'] ?? $block['name'],
            'reassigned' => (bool) $item['reassigned'],
            'requestedRoom' => (string) $item['requestedRoom'],
            'roomLabel' => (string) $item['roomLabel'],
        ];
    } catch (Throwable $e) {
        $failed[] = [
            'row' => (int) $item['seq'],
            'error' => $e->getMessage(),
            'data' => [
                'blockName' => $block['name'],
                'subject' => $subjectName,
                'day' => $meeting['day'] ?? '',
                'startTime' => $meeting['startTime'] ?? '',
                'endTime' => $meeting['endTime'] ?? '',
                'roomLabel' => $item['roomLabel'] ?? '',
            ],
            'source' => $meeting['_source'] ?? $block['name'],
        ];
    }
}

$term = currentTermWindow();
$hoursByCode = buildSubjectCatalogHoursFromImportGroups($groups);
$subjectsUpdated = 0;
try {
    $subjectsUpdated = applySubjectCatalogHoursFromImport(
        $departmentId,
        $hoursByCode,
        (int) $term['academicYear']
    );
} catch (Throwable $e) {
    // Meetings imported; subject hour sync is best-effort.
}

logAudit(
    $user['uid'],
    'IMPORT',
    'class_block',
    sprintf(
        'Imported student blocks from %s: %d block(s), %d meeting(s) succeeded, %d room(s) reassigned, %d shared venue clone(s), %d room conflict(s), %d failed, %d IRREG meeting(s) skipped.',
        $originalName,
        count($importedBlocks),
        count($importedMeetings),
        $reassigned,
        $clonedVenues,
        $conflictCount,
        count($failed),
        $skippedIrreg
    )
);

jsonSuccess([
    'format' => 'student-block-grid',
    'importedBlockCount' => count($importedBlocks),
    'importedCount' => count($importedMeetings),
    'reassignedCount' => $reassigned,
    'clonedVenueCount' => $clonedVenues,
    'conflictCount' => $conflictCount,
    'conflicts' => array_slice($conflicts, 0, 40),
    'failedCount' => count($failed),
    'skippedIrregCount' => $skippedIrreg,
    'subjectsUpdated' => $subjectsUpdated,
    'subjectHoursSample' => array_slice($hoursByCode, 0, 8, true),
    'blocks' => array_values($importedBlocks),
    'imported' => array_slice($importedMeetings, 0, 40),
    'failed' => array_slice($failed, 0, 100),
]);
