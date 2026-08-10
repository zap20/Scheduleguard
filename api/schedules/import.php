<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Schedule.php';
require_once dirname(__DIR__, 2) . '/includes/SemScheduleImport.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    jsonError('file is required (CSV or XLSX).', 422);
}

$file = $_FILES['file'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    jsonError('File upload failed.', 422);
}

$originalName = (string) ($file['name'] ?? 'upload.csv');
$tmpPath = (string) ($file['tmp_name'] ?? '');

try {
    $rows = parseScheduleImportFile($tmpPath, $originalName);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
} catch (Throwable $e) {
    jsonError('Unable to parse import file: ' . $e->getMessage(), 422);
}

/**
 * @param array<string,string> $row
 * @return array<string,string>
 */
function normalizeImportRowKeys(array $row): array
{
    // Semester grid rows are already normalized by parseSemGridScheduleXlsx().
    if (isset($row['facultyName'])) {
        return $row;
    }

    $map = [
        'facultyid' => 'facultyId',
        'roomid' => 'roomId',
        'departmentid' => 'departmentId',
        'subjectcode' => 'subjectCode',
        'subjectname' => 'subjectName',
        'day' => 'day',
        'starttime' => 'startTime',
        'endtime' => 'endTime',
        'academicyear' => 'academicYear',
        'semester' => 'semester',
    ];

    $normalized = [];
    foreach ($row as $key => $value) {
        $keyStr = (string) $key;
        if (strncmp($keyStr, '_', 1) === 0) {
            $normalized[$keyStr] = $value;
            continue;
        }
        $lower = strtolower($keyStr);
        if (isset($map[$lower])) {
            $normalized[$map[$lower]] = $value;
        }
    }

    return $normalized;
}

$imported = [];
$failed = [];
$isGrid = false;

foreach ($rows as $row) {
    $rowNumber = (int) ($row['_rowNumber'] ?? 0);
    $payload = normalizeImportRowKeys($row);
    $isGrid = $isGrid || isset($payload['facultyName']);

    try {
        if (isset($payload['facultyName'])) {
            $payload = resolveSemImportRow($payload);
        }
        $data = validateScheduleInput($payload);
        $schedule = createDraftSchedule($data, $user['uid']);
        $imported[] = [
            'row' => $rowNumber,
            'schedule' => $schedule,
            'source' => $row['_source'] ?? null,
        ];

        logAudit(
            $user['uid'],
            'CREATE',
            'schedule',
            sprintf(
                'Imported draft schedule "%s" (%s %s–%s) from %s row %d.',
                $schedule['subjectCode'],
                $schedule['day'],
                $schedule['startTime'],
                $schedule['endTime'],
                $originalName,
                $rowNumber
            ),
            $schedule['uid']
        );
    } catch (Throwable $e) {
        $failed[] = [
            'row' => $rowNumber,
            'error' => $e->getMessage(),
            'data' => $payload,
            'source' => $row['_source'] ?? null,
        ];
    }
}

logAudit(
    $user['uid'],
    'IMPORT',
    'schedule',
    sprintf(
        'Imported schedules from %s%s: %d succeeded, %d failed.',
        $originalName,
        $isGrid ? ' (semester grid)' : '',
        count($imported),
        count($failed)
    )
);

jsonSuccess([
    'format' => $isGrid ? 'semester-grid' : 'flat',
    'importedCount' => count($imported),
    'failedCount' => count($failed),
    'imported' => $imported,
    'failed' => array_slice($failed, 0, 100),
]);
