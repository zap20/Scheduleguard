<?php

declare(strict_types=1);

/**
 * Dean import of student class blocks from a semester workbook
 * (BSIT / Course and Year + Block grids). IRREG bands are skipped.
 * Rooms are checked (existing term + this file) before a meeting is saved;
 * a busy Excel room is swapped for another free room of the same type.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ClassBlock.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';
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

try {
    $groups = parseSemStudentBlockXlsx($tmpPath, $sheet);
    $plan = planStudentBlockImport($groups, $departmentId);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
} catch (Throwable $e) {
    jsonError('Unable to parse import file: ' . $e->getMessage(), 422);
}

$importedBlocks = [];
$importedMeetings = [];
$failed = $plan['failed'];
$skippedIrreg = $plan['skippedIrreg'];
$reassigned = $plan['reassigned'];

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

logAudit(
    $user['uid'],
    'IMPORT',
    'class_block',
    sprintf(
        'Imported student blocks from %s: %d block(s), %d meeting(s) succeeded, %d room(s) reassigned, %d failed, %d IRREG meeting(s) skipped.',
        $originalName,
        count($importedBlocks),
        count($importedMeetings),
        $reassigned,
        count($failed),
        $skippedIrreg
    )
);

jsonSuccess([
    'format' => 'student-block-grid',
    'importedBlockCount' => count($importedBlocks),
    'importedCount' => count($importedMeetings),
    'reassignedCount' => $reassigned,
    'failedCount' => count($failed),
    'skippedIrregCount' => $skippedIrreg,
    'blocks' => array_values($importedBlocks),
    'imported' => array_slice($importedMeetings, 0, 40),
    'failed' => array_slice($failed, 0, 100),
]);
