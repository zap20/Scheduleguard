<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/Room.php';

/**
 * Detect 1st-Sem style weekly grid workbooks (TEACHER / course sheets).
 */
function isSemGridWorkbook(string $path): bool
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return false;
    }
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $zip->close();
    if ($wbXml === false) {
        return false;
    }

    return stripos($wbXml, 'name="TEACHER"') !== false
        || stripos($wbXml, 'name="Teacher"') !== false
        || (stripos($wbXml, 'name="BSIT"') !== false && stripos($wbXml, 'name="ROOM"') !== false);
}

/**
 * Parse semester grid XLSX into draft-ready schedule payloads.
 * Uses the TEACHER sheet (one panel per instructor).
 *
 * Returned keys (string values):
 * facultyName, roomLabel, subjectCode, subjectName, day, startTime, endTime,
 * academicYear, semester, _rowNumber, _sheet, _source
 *
 * @return list<array<string,string>>
 */
function parseSemGridScheduleXlsx(string $path): array
{
    $book = loadXlsxWorkbook($path);
    if (!isset($book['sheets']['TEACHER']) && !isset($book['sheets']['Teacher'])) {
        throw new InvalidArgumentException('Semester workbook is missing a TEACHER sheet.');
    }

    $sheetPath = $book['sheets']['TEACHER'] ?? $book['sheets']['Teacher'];
    $grid = loadXlsxSheetGrid($book['zipPath'], $sheetPath, $book['sharedStrings']);
    $term = currentTermWindow();

    $panels = findSemTeacherPanels($grid);
    if ($panels === []) {
        throw new InvalidArgumentException('No teacher schedule panels found on TEACHER sheet.');
    }

    $rows = [];
    $seq = 0;
    foreach ($panels as $panel) {
        $blocks = extractSemPanelBlocks($grid, $panel);
        foreach ($blocks as $block) {
            $seq++;
            $rows[] = [
                'facultyName' => $panel['teacher'],
                'roomLabel' => $block['room'],
                'subjectCode' => extractSemSubjectCode($block['subject']),
                'subjectName' => $block['subject'],
                'day' => $block['day'],
                'startTime' => minutesToHm($block['startMinutes']),
                'endTime' => minutesToHm($block['endMinutes']),
                'academicYear' => (string) $term['academicYear'],
                'semester' => $term['semester'],
                '_rowNumber' => (string) $seq,
                '_sheet' => 'TEACHER',
                '_source' => $panel['teacher'] . ' / ' . $block['day'],
            ];
        }
    }

    if ($rows === []) {
        throw new InvalidArgumentException('No class blocks found on TEACHER sheet.');
    }

    return $rows;
}

/**
 * @return array{zipPath:string,sharedStrings:list<string>,sheets:array<string,string>}
 */
function loadXlsxWorkbook(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new InvalidArgumentException('Unable to open XLSX file.');
    }

    $sharedStrings = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $ss = simplexml_load_string($ssXml);
        if ($ss !== false) {
            foreach ($ss->si as $si) {
                if (isset($si->t)) {
                    $sharedStrings[] = (string) $si->t;
                } else {
                    $text = '';
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                    $sharedStrings[] = $text;
                }
            }
        }
    }

    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml === false || $relsXml === false) {
        $zip->close();
        throw new InvalidArgumentException('Invalid XLSX workbook.');
    }

    $wb = simplexml_load_string($wbXml);
    $rels = simplexml_load_string($relsXml);
    if ($wb === false || $rels === false) {
        $zip->close();
        throw new InvalidArgumentException('Unable to parse XLSX workbook.');
    }

    $relMap = [];
    foreach ($rels->Relationship as $rel) {
        $relMap[(string) $rel['Id']] = (string) $rel['Target'];
    }

    $sheets = [];
    foreach ($wb->sheets->sheet as $sheet) {
        $name = (string) $sheet['name'];
        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string) ($attrs['id'] ?? '');
        $target = $relMap[$rid] ?? '';
        if ($target === '') {
            continue;
        }
        $sheets[$name] = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
    }

    // Keep zip path for reopening sheet XML; close handle now.
    $zip->close();

    return [
        'zipPath' => $path,
        'sharedStrings' => $sharedStrings,
        'sheets' => $sheets,
    ];
}

/**
 * @param list<string> $sharedStrings
 * @return array<int,array<int,string>>
 */
function loadXlsxSheetGrid(string $xlsxPath, string $sheetPath, array $sharedStrings): array
{
    $zip = new ZipArchive();
    if ($zip->open($xlsxPath) !== true) {
        throw new InvalidArgumentException('Unable to reopen XLSX file.');
    }
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheetXml === false) {
        throw new InvalidArgumentException('Unable to read worksheet: ' . $sheetPath);
    }

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new InvalidArgumentException('Unable to parse worksheet XML.');
    }

    $grid = [];
    foreach ($sheet->sheetData->row as $row) {
        $rIndex = (int) $row['r'];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $col = semXlsxColumnIndex($m[1]);
            $type = (string) ($cell['t'] ?? '');
            if ($type === 's') {
                $value = $sharedStrings[(int) $cell->v] ?? '';
            } else {
                $value = (string) ($cell->v ?? '');
            }
            $grid[$rIndex][$col] = $value;
        }
    }

    return $grid;
}

/**
 * @param array<int,array<int,string>> $grid
 * @return list<array{teacher:string,dayRow:int,timeStartCol:int,timeEndCol:int,days:array<string,array{subj:int,meta:int}>}>
 */
function findSemTeacherPanels(array $grid): array
{
    $dayNames = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
    $panels = [];

    foreach ($grid as $rowNum => $cells) {
        $dayCols = [];
        foreach ($cells as $col => $raw) {
            $key = strtolower(trim((string) $raw));
            $key = rtrim($key, '.');
            if (isset($dayNames[$key])) {
                $dayCols[$col] = $dayNames[$key];
            }
        }
        if (count($dayCols) < 5) {
            continue;
        }
        ksort($dayCols);

        // Split into weekly panels (Mon..Sun groups).
        $cols = array_keys($dayCols);
        $groups = [];
        $current = [];
        $prev = null;
        foreach ($cols as $col) {
            if ($prev !== null && $col - $prev > 4) {
                if ($current !== []) {
                    $groups[] = $current;
                }
                $current = [];
            }
            $current[] = $col;
            $prev = $col;
        }
        if ($current !== []) {
            $groups[] = $current;
        }

        foreach ($groups as $groupCols) {
            if (count($groupCols) < 5) {
                continue;
            }
            $monCol = $groupCols[0];
            if (strtolower(trim((string) ($cells[$monCol] ?? ''))) !== 'mon') {
                // rotate until Monday if needed
                $foundMon = null;
                foreach ($groupCols as $c) {
                    if (strtolower(trim((string) ($cells[$c] ?? ''))) === 'mon') {
                        $foundMon = $c;
                        break;
                    }
                }
                if ($foundMon === null) {
                    continue;
                }
                $monCol = $foundMon;
            }

            $days = [];
            foreach ($groupCols as $subjCol) {
                $label = strtolower(trim((string) ($cells[$subjCol] ?? '')));
                $label = rtrim($label, '.');
                if (!isset($dayNames[$label])) {
                    continue;
                }
                $days[$dayNames[$label]] = [
                    'subj' => $subjCol,
                    'meta' => $subjCol + 1,
                ];
            }
            if (count($days) < 5) {
                continue;
            }

            $timeEndCol = $monCol - 1;
            $timeStartCol = $monCol - 3;
            if ($timeStartCol < 1) {
                $timeStartCol = 1;
            }

            $teacher = detectSemPanelTeacher($grid, $rowNum, $monCol, $timeStartCol);
            $panel = [
                'teacher' => $teacher !== '' ? $teacher : 'UNKNOWN',
                'dayRow' => $rowNum,
                'timeStartCol' => $timeStartCol,
                'timeEndCol' => $timeEndCol,
                'days' => $days,
            ];
            if ($panel['teacher'] === 'UNKNOWN') {
                $inferred = inferSemTeacherFromPanelMeta($grid, $panel);
                if ($inferred !== '') {
                    $panel['teacher'] = $inferred;
                }
            }

            $panels[] = $panel;
        }
    }

    return $panels;
}

/**
 * @param array<int,array<int,string>> $grid
 */
function detectSemPanelTeacher(array $grid, int $dayRow, int $monCol, int $timeStartCol): string
{
    for ($r = $dayRow - 1; $r >= max(1, $dayRow - 4); $r--) {
        $row = $grid[$r] ?? [];
        // Prefer label near the time/name column.
        foreach ([$timeStartCol, $monCol - 3, $monCol, $monCol + 2, $monCol + 5] as $c) {
            if ($c < 1) {
                continue;
            }
            $val = trim((string) ($row[$c] ?? ''));
            if (isSemTeacherName($val)) {
                return strtoupper($val);
            }
        }
        foreach ($row as $c => $val) {
            if ($c < $timeStartCol - 1 || $c > $monCol + 8) {
                continue;
            }
            $val = trim((string) $val);
            if (isSemTeacherName($val)) {
                return strtoupper($val);
            }
        }
    }

    return '';
}

function isSemTeacherName(string $value): bool
{
    $v = trim($value);
    if ($v === '' || strlen($v) > 24) {
        return false;
    }
    $upper = strtoupper($v);
    $blocked = [
        'MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN', 'LOAD', 'DEPARTMENT',
        'BLOCK', 'INSTRUCTOR', 'COURSE AND YEAR', 'PHYSICAL ROOM', 'COMPUTER LAB',
        'CICT', '-',
    ];
    if (in_array($upper, $blocked, true)) {
        return false;
    }
    if (isSemRoomLabel($v)) {
        return false;
    }
    // Section codes like 121A / GE114A
    if (preg_match('/^\d{2,4}[A-Z]$/i', $v) === 1) {
        return false;
    }
    if (preg_match('/^[A-Z]{2,}\d+[A-Z]?$/i', $v) === 1 && preg_match('/\d/', $v) === 1) {
        // e.g. GE114A, PEA-like codes with digits
        if (preg_match('/\d/', $v) === 1 && strlen($v) <= 8) {
            return false;
        }
    }
    if (preg_match('/^[A-Za-z][A-Za-z.\-\']{1,20}$/', $v) !== 1) {
        return false;
    }
    return true;
}

function isSemRoomLabel(string $value): bool
{
    $v = strtoupper(trim($value));
    if ($v === '') {
        return false;
    }
    if (preg_match('/^(CL|MST|JST|GYM|LAB|ROOM)\b/', $v) === 1) {
        return true;
    }
    if (preg_match('/\b(LAB|ROOM|GYM)\b/', $v) === 1) {
        return true;
    }
    return false;
}

/**
 * Fallback: pick the most common non-room meta token in the panel as faculty/code.
 *
 * @param array<int,array<int,string>> $grid
 * @param array{dayRow:int,days:array<string,array{subj:int,meta:int}>} $panel
 */
function inferSemTeacherFromPanelMeta(array $grid, array $panel): string
{
    $counts = [];
    $maxRow = $panel['dayRow'] + 80;
    foreach ($panel['days'] as $cols) {
        for ($r = $panel['dayRow'] + 1; $r <= $maxRow; $r++) {
            $subject = trim((string) ($grid[$r][$cols['subj']] ?? ''));
            $meta = trim((string) ($grid[$r][$cols['meta']] ?? ''));
            if ($subject === '' && $meta === '') {
                continue;
            }
            if ($meta === '' || isSemRoomLabel($meta) || is_numeric($meta)) {
                continue;
            }
            if (strcasecmp($meta, '12 blocks') === 0) {
                continue;
            }
            $key = strtoupper($meta);
            // Prefer surname-like tokens over section codes when both exist.
            $weight = isSemTeacherName($meta) ? 3 : 1;
            if (preg_match('/^\d{2,4}[A-Z]$/i', $meta) === 1) {
                $weight = 1;
            }
            $counts[$key] = ($counts[$key] ?? 0) + $weight;
        }
    }
    if ($counts === []) {
        return '';
    }
    arsort($counts);
    return (string) array_key_first($counts);
}

/**
 * @param array<int,array<int,string>> $grid
 * @param array{teacher:string,dayRow:int,timeStartCol:int,timeEndCol:int,days:array<string,array{subj:int,meta:int}>} $panel
 * @return list<array{day:string,subject:string,room:string,startMinutes:int,endMinutes:int}>
 */
function extractSemPanelBlocks(array $grid, array $panel): array
{
    $maxRow = $panel['dayRow'];
    foreach ($grid as $r => $_) {
        if ($r > $maxRow) {
            $maxRow = $r;
        }
    }

    $blocks = [];
    foreach ($panel['days'] as $dayName => $cols) {
        $active = null;
        $clockOffset = 0;
        $prevStart = null;

        for ($r = $panel['dayRow'] + 1; $r <= $maxRow; $r++) {
            $row = $grid[$r] ?? [];
            $startRaw = trim((string) ($row[$panel['timeStartCol']] ?? ''));
            $endRaw = trim((string) ($row[$panel['timeEndCol']] ?? ''));
            if ($startRaw === '' || $endRaw === '') {
                // No time markers — end of this panel's vertical range.
                if ($active !== null) {
                    $blocks[] = $active;
                    $active = null;
                }
                break;
            }

            $startMin = excelTimeToMinutes($startRaw) + $clockOffset;
            $endMin = excelTimeToMinutes($endRaw) + $clockOffset;
            if ($prevStart !== null && $startMin + 5 < $prevStart) {
                $clockOffset += 12 * 60;
                $startMin += 12 * 60;
                $endMin += 12 * 60;
            }
            if ($endMin <= $startMin) {
                $endMin += 12 * 60;
            }
            $prevStart = $startMin;

            $subject = trim((string) ($row[$cols['subj']] ?? ''));
            $meta = trim((string) ($row[$cols['meta']] ?? ''));

            // Ignore Excel date serial leftovers / noise in meta.
            if ($meta !== '' && is_numeric($meta) && (float) $meta > 20000) {
                $meta = '';
            }
            if (strcasecmp($meta, '12 blocks') === 0) {
                $meta = '';
            }

            if ($subject !== '') {
                if ($active !== null) {
                    $blocks[] = $active;
                }
                $active = [
                    'day' => $dayName,
                    'subject' => preg_replace('/\s+/', ' ', $subject) ?? $subject,
                    'room' => isSemRoomLabel($meta) ? normalizeSemRoomLabel($meta) : '',
                    'startMinutes' => $startMin,
                    'endMinutes' => $endMin,
                ];
                continue;
            }

            if ($active === null) {
                continue;
            }

            if (isSemRoomLabel($meta)) {
                $active['room'] = normalizeSemRoomLabel($meta);
                $active['endMinutes'] = $endMin;
                continue;
            }

            // Empty continuation — class ended on previous row.
            $blocks[] = $active;
            $active = null;
        }

        if ($active !== null) {
            $blocks[] = $active;
        }
    }

    return $blocks;
}

function excelTimeToMinutes(string $raw): int
{
    $raw = trim($raw);
    if ($raw === '') {
        return 0;
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $m) === 1) {
        return ((int) $m[1]) * 60 + (int) $m[2];
    }
    if (is_numeric($raw)) {
        $n = (float) $raw;
        // Excel stores time as fraction of day.
        if ($n >= 0 && $n < 1.5) {
            $total = (int) round(fmod($n, 1) * 24 * 60);
            return $total;
        }
    }
    return 0;
}

function minutesToHm(int $minutes): string
{
    $minutes = max(0, $minutes);
    // Keep within 24h clock for TIME columns.
    $minutes = $minutes % (24 * 60);
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    return sprintf('%02d:%02d', $h, $m);
}

function normalizeSemRoomLabel(string $label): string
{
    $label = strtoupper(trim($label));
    $label = preg_replace('/\s+/', ' ', $label) ?? $label;
    // CL1 → CL 01, CL01 → CL 01
    if (preg_match('/^CL\s*(\d+)$/', $label, $m) === 1) {
        return 'CL ' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    return $label;
}

function extractSemSubjectCode(string $subjectName): string
{
    $name = strtoupper(trim($subjectName));
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;

    if (preg_match('/^([A-Z]+(?:\s+[A-Z]+)?)\s+(\d+)/', $name, $m) === 1) {
        return substr(str_replace(' ', '', $m[1] . $m[2]), 0, 50);
    }
    if (preg_match('/^([A-Z]{2,}[0-9A-Z]*)/', $name, $m) === 1) {
        return substr($m[1], 0, 50);
    }

    $compact = preg_replace('/[^A-Z0-9]/', '', $name) ?? 'SUBJ';
    return substr($compact !== '' ? $compact : 'SUBJ', 0, 50);
}

function semXlsxColumnIndex(string $letters): int
{
    $letters = strtoupper($letters);
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n;
}

/**
 * Resolve or create Faculty / Room rows for a grid import payload.
 *
 * @param array<string,string> $row
 * @return array<string,string>
 */
function resolveSemImportRow(array $row): array
{
    $facultyName = strtoupper(trim((string) ($row['facultyName'] ?? '')));
    $roomLabel = trim((string) ($row['roomLabel'] ?? ''));
    if ($roomLabel === '') {
        $roomLabel = 'TBA';
    }

    $facultyId = findOrCreateFacultyByName($facultyName);
    $roomId = findOrCreateRoomByLabel($roomLabel);
    $departmentId = findUserDepartmentId($facultyId) ?? 'dept-comp';

    return [
        'facultyId' => $facultyId,
        'roomId' => $roomId,
        'departmentId' => $departmentId,
        'subjectCode' => (string) ($row['subjectCode'] ?? ''),
        'subjectName' => (string) ($row['subjectName'] ?? ''),
        'day' => (string) ($row['day'] ?? ''),
        'startTime' => (string) ($row['startTime'] ?? ''),
        'endTime' => (string) ($row['endTime'] ?? ''),
        'academicYear' => (string) ($row['academicYear'] ?? ''),
        'semester' => (string) ($row['semester'] ?? ''),
    ];
}

function findUserDepartmentId(string $userId): ?string
{
    $stmt = db()->prepare('SELECT departmentId FROM `user` WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $val = $stmt->fetchColumn();
    return $val !== false && $val !== null && $val !== '' ? (string) $val : null;
}

function findOrCreateFacultyByName(string $name): string
{
    $name = strtoupper(trim($name));
    if ($name === '' || $name === 'UNKNOWN') {
        throw new InvalidArgumentException('Faculty name is missing in TEACHER panel.');
    }

    $stmt = db()->prepare(
        "SELECT uid FROM `user`
         WHERE role = 'Faculty'
           AND (
                UPPER(lastName) = :name
             OR UPPER(firstName) = :name
             OR UPPER(CONCAT(firstName, ' ', lastName)) = :name
             OR UPPER(schoolId) = :name
           )
         ORDER BY CASE WHEN status = 'Active' THEN 0 ELSE 1 END
         LIMIT 1"
    );
    $stmt->execute([':name' => $name]);
    $uid = $stmt->fetchColumn();
    if ($uid) {
        return (string) $uid;
    }

    // Create a stub Faculty account so semester imports can proceed.
    $schoolId = nextAvailableSchoolIdForRole('Faculty');
    $emailLocal = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name) ?? 'faculty');
    if ($emailLocal === '') {
        $emailLocal = 'faculty';
    }
    $email = $emailLocal . '@scheduleguard.test';
    $suffix = 1;
    while (emailExists($email)) {
        $suffix++;
        $email = $emailLocal . $suffix . '@scheduleguard.test';
    }

    $uid = 'fac-' . strtolower(substr(preg_replace('/[^a-z0-9]+/i', '', $name) ?? 'x', 0, 16));
    if ($uid === 'fac-' || userUidExists($uid)) {
        $uid = generateUid();
    }

    $dept = departmentExists('dept-comp') ? 'dept-comp' : null;
    $ins = db()->prepare(
        'INSERT INTO `user`
            (uid, departmentId, firstName, lastName, email, schoolId, role, phoneNumber, status, passwordHash, createdAt)
         VALUES
            (:uid, :departmentId, :firstName, :lastName, :email, :schoolId, \'Faculty\', NULL, \'Active\', :passwordHash, NOW())'
    );
    $ins->execute([
        ':uid' => $uid,
        ':departmentId' => $dept,
        ':firstName' => 'Teacher',
        ':lastName' => $name,
        ':email' => $email,
        ':schoolId' => $schoolId,
        ':passwordHash' => password_hash('Password123!', PASSWORD_BCRYPT),
    ]);

    return $uid;
}

function findOrCreateRoomByLabel(string $label): string
{
    $label = normalizeSemRoomLabel($label);
    $compact = strtoupper(preg_replace('/\s+/', '', $label) ?? $label);

    $stmt = db()->query('SELECT uid, name, building FROM room');
    foreach ($stmt->fetchAll() as $row) {
        $name = strtoupper(trim((string) $row['name']));
        $building = strtoupper(trim((string) $row['building']));
        $nameCompact = preg_replace('/\s+/', '', $name) ?? $name;
        $combo = preg_replace('/\s+/', '', $building . $name) ?? '';
        if ($name === $label || $nameCompact === $compact || $combo === $compact) {
            return (string) $row['uid'];
        }
    }

    $uid = 'room-' . strtolower(preg_replace('/[^a-z0-9]+/i', '-', $label) ?? 'tba');
    $uid = trim($uid, '-');
    if ($uid === 'room' || roomUidExists($uid)) {
        $uid = generateUid();
    }

    $building = 'Main';
    $name = $label;
    if (preg_match('/^(CL|MST|JST)\s*(.+)$/', $label, $m) === 1) {
        $building = $m[1];
        $name = trim($m[2]);
        if ($name === '') {
            $name = $label;
        }
    } elseif ($label === 'GYM') {
        $building = 'Campus';
        $name = 'GYM';
    } elseif ($label === 'TBA') {
        $building = 'TBA';
        $name = 'TBA';
    }

    $ins = db()->prepare(
        'INSERT INTO room (uid, name, building, capacity, roomType, createdAt)
         VALUES (:uid, :name, :building, 40, :roomType, NOW())'
    );
    $ins->execute([
        ':uid' => $uid,
        ':name' => $name,
        ':building' => $building,
        ':roomType' => inferRoomTypeFromLabel($label),
    ]);

    return $uid;
}

function emailExists(string $email): bool
{
    $stmt = db()->prepare('SELECT uid FROM `user` WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => strtolower($email)]);
    return (bool) $stmt->fetchColumn();
}

function userUidExists(string $uid): bool
{
    $stmt = db()->prepare('SELECT uid FROM `user` WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    return (bool) $stmt->fetchColumn();
}

function roomUidExists(string $uid): bool
{
    $stmt = db()->prepare('SELECT uid FROM room WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    return (bool) $stmt->fetchColumn();
}

function departmentExists(string $uid): bool
{
    $stmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $uid]);
    return (bool) $stmt->fetchColumn();
}

function nextAvailableSchoolIdForRole(string $role): string
{
    $year = (int) date('Y');
    for ($n = 1; $n <= 999; $n++) {
        $schoolId = sprintf('%04d-%03d', $year, $n);
        $stmt = db()->prepare('SELECT uid FROM `user` WHERE schoolId = :schoolId AND role = :role LIMIT 1');
        $stmt->execute([':schoolId' => $schoolId, ':role' => $role]);
        if (!$stmt->fetchColumn()) {
            return $schoolId;
        }
    }
    throw new RuntimeException('Unable to allocate schoolId for new faculty.');
}
