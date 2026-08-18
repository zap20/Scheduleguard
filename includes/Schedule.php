<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/Subject.php';
require_once __DIR__ . '/Room.php';

const SCHEDULE_STATUS_DRAFT = 'draft';
const SCHEDULE_STATUS_CONFLICT = 'conflict';
const SCHEDULE_STATUS_CONFIRMED = 'confirmed';
const SCHEDULE_INSTRUCTOR_TBF = 'TBF';

/**
 * Teaching load for one subject offering = total weekly hours ÷ 3
 * (e.g. 5 hours → 5/3 ≈ 1.6667).
 */
const FACULTY_LOAD_HOURS_DIVISOR = 3.0;

const SCHEDULE_DAYS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

/**
 * Load credit for a subject from lecture + lab hours.
 */
function subjectTeachingLoadFromHours(float $lectureHours, float $labHours): float
{
    $total = max(0.0, $lectureHours) + max(0.0, $labHours);
    if ($total <= 0) {
        return 0.0;
    }

    return round($total / FACULTY_LOAD_HOURS_DIVISOR, 4);
}

/**
 * Faculty teaching load from schedule rows.
 * Uses total scheduled contact hours ÷ 3 (matches weekly meetings on the grid).
 *
 * @param list<array<string,mixed>> $rows Schedule rows for one faculty
 * @return array{subjectCount:int,offeringCount:int,load:float,contactHours:float,subjects:list<string>}
 */
function computeFacultyTeachingLoad(array $rows): array
{
    $subjects = [];
    $offerings = [];
    $contactMinutes = 0;

    foreach ($rows as $row) {
        $sid = trim((string) ($row['subjectId'] ?? ''));
        $code = trim((string) ($row['subjectCode'] ?? ''));
        $key = $sid !== '' ? $sid : ($code !== '' ? $code : '');
        if ($key === '') {
            continue;
        }
        $blockKey = trim((string) ($row['classBlockId'] ?? ''));
        if ($blockKey === '') {
            $blockKey = trim((string) ($row['blockName'] ?? '')) ?: 'default';
        }
        $offerKey = $key . '::' . $blockKey;
        $subjects[$key] = $code !== '' ? $code : $key;
        $offerings[$offerKey] = true;

        $start = substr((string) ($row['startTime'] ?? ''), 0, 5);
        $end = substr((string) ($row['endTime'] ?? ''), 0, 5);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $start, $sm)
            && preg_match('/^(\d{1,2}):(\d{2})$/', $end, $em)
        ) {
            $sMin = ((int) $sm[1]) * 60 + (int) $sm[2];
            $eMin = ((int) $em[1]) * 60 + (int) $em[2];
            if ($eMin > $sMin) {
                $contactMinutes += $eMin - $sMin;
            }
        }
    }

    $contactHours = $contactMinutes / 60.0;
    $load = $contactHours > 0 ? round($contactHours / FACULTY_LOAD_HOURS_DIVISOR, 2) : 0.0;

    return [
        'subjectCount' => count($subjects),
        'offeringCount' => count($offerings),
        'load' => $load,
        'contactHours' => round($contactHours, 2),
        'subjects' => array_values($subjects),
    ];
}
/**
 * Empty / "TBF" → null (unassigned instructor).
 */
function normalizeAssignmentFacultyId(mixed $facultyId): ?string
{
    $raw = trim((string) ($facultyId ?? ''));
    if ($raw === '' || strcasecmp($raw, SCHEDULE_INSTRUCTOR_TBF) === 0) {
        return null;
    }
    return $raw;
}

/**
 * Display label for the instructor assigned to a schedule.
 * Empty / unassigned → TBF (to be filled).
 */
function scheduleInstructorLabel(?string $facultyId, ?string $firstName, ?string $lastName): string
{
    if ($facultyId === null || trim($facultyId) === '') {
        return SCHEDULE_INSTRUCTOR_TBF;
    }
    $name = trim(trim((string) $firstName) . ' ' . trim((string) $lastName));
    return $name !== '' ? $name : SCHEDULE_INSTRUCTOR_TBF;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapScheduleRow(array $row): array
{
    $facultyId = isset($row['facultyId']) && $row['facultyId'] !== null
        ? (string) $row['facultyId']
        : '';
    $facultyName = scheduleInstructorLabel(
        $facultyId !== '' ? $facultyId : null,
        isset($row['facultyFirstName']) ? (string) $row['facultyFirstName'] : null,
        isset($row['facultyLastName']) ? (string) $row['facultyLastName'] : null
    );

    return [
        'uid' => (string) $row['uid'],
        'facultyId' => $facultyId,
        'facultyName' => $facultyName,
        'instructor' => $facultyName,
        'facultyEmail' => (string) ($row['facultyEmail'] ?? ''),
        'roomId' => (string) $row['roomId'],
        'roomName' => (string) $row['roomName'],
        'roomBuilding' => (string) $row['roomBuilding'],
        'roomLabel' => formatRoomDisplayLabel(
            isset($row['roomBuilding']) ? (string) $row['roomBuilding'] : null,
            isset($row['roomName']) ? (string) $row['roomName'] : null
        ),
        'departmentId' => (string) $row['departmentId'],
        'departmentName' => (string) $row['departmentName'],
        'createdBy' => (string) $row['createdBy'],
        'subjectId' => (string) ($row['subjectId'] ?? ''),
        'subjectCode' => (string) ($row['subjectCode'] ?? ''),
        'subjectName' => (string) ($row['subjectName'] ?? $row['subjectTitle'] ?? ''),
        'subjectYearLevel' => (string) ($row['subjectYearLevel'] ?? ''),
        'subjectSemester' => (string) ($row['subjectSemester'] ?? ''),
        'subjectUnits' => isset($row['subjectUnits']) ? (float) $row['subjectUnits'] : null,
        'lectureHours' => isset($row['subjectLectureHours']) ? (float) $row['subjectLectureHours'] : 0.0,
        'labHours' => isset($row['subjectLabHours']) ? (float) $row['subjectLabHours'] : 0.0,
        'subjectLoad' => subjectTeachingLoadFromHours(
            isset($row['subjectLectureHours']) ? (float) $row['subjectLectureHours'] : 0.0,
            isset($row['subjectLabHours']) ? (float) $row['subjectLabHours'] : 0.0
        ),
        'day' => (string) $row['day'],
        'startTime' => substr((string) $row['startTime'], 0, 5),
        'endTime' => substr((string) $row['endTime'], 0, 5),
        'academicYear' => (int) ($row['academicYear'] ?? 0),
        'semester' => (string) ($row['semester'] ?? ''),
        'yearLevel' => (string) ($row['yearLevel'] ?? $row['subjectYearLevel'] ?? ''),
        'blockNumber' => isset($row['blockNumber']) && $row['blockNumber'] !== null ? (int) $row['blockNumber'] : null,
        'blockName' => (string) ($row['blockName'] ?? ''),
        'classBlockId' => (string) ($row['classBlockId'] ?? ''),
        'studentType' => (string) ($row['studentType'] ?? ''),
        'termLabel' => formatTermLabel(
            (int) ($row['academicYear'] ?? 0),
            (string) ($row['semester'] ?? '')
        ),
        'status' => (string) $row['status'],
        'createdAt' => (string) $row['createdAt'],
    ];
}

function scheduleSelectSql(): string
{
    return 'SELECT
                s.uid,
                s.facultyId,
                s.roomId,
                s.departmentId,
                s.createdBy,
                s.subjectId,
                sub.code AS subjectCode,
                sub.title AS subjectName,
                sub.title AS subjectTitle,
                sub.yearLevel AS subjectYearLevel,
                sub.semester AS subjectSemester,
                sub.units AS subjectUnits,
                sub.lectureHours AS subjectLectureHours,
                sub.labHours AS subjectLabHours,
                s.day,
                s.startTime,
                s.endTime,
                s.academicYear,
                s.semester,
                s.yearLevel,
                s.blockNumber,
                s.blockName,
                s.classBlockId,
                s.studentType,
                s.status,
                s.createdAt,
                f.firstName AS facultyFirstName,
                f.lastName AS facultyLastName,
                f.email AS facultyEmail,
                r.name AS roomName,
                r.building AS roomBuilding,
                d.name AS departmentName
            FROM schedule s
            INNER JOIN subject sub ON sub.uid = s.subjectId
            LEFT JOIN `user` f ON f.uid = s.facultyId
            INNER JOIN room r ON r.uid = s.roomId
            INNER JOIN department d ON d.uid = s.departmentId';
}

/**
 * @return array<string,mixed>|null
 */
function fetchScheduleById(string $scheduleId): ?array
{
    $stmt = db()->prepare(scheduleSelectSql() . ' WHERE s.uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $scheduleId]);
    $row = $stmt->fetch();
    return $row ? mapScheduleRow($row) : null;
}

/**
 * @return list<array<string,mixed>>
 */
function fetchSchedules(
    ?string $status = null,
    ?string $departmentId = null,
    ?int $academicYear = null,
    ?string $semester = null,
    ?string $yearLevel = null,
    ?int $blockNumber = null,
    ?string $blockName = null
): array {
    $sql = scheduleSelectSql() . ' WHERE 1 = 1';
    $params = [];

    if ($status !== null && $status !== '') {
        $sql .= ' AND LOWER(s.status) = :status';
        $params[':status'] = strtolower($status);
    }
    if ($departmentId !== null && $departmentId !== '') {
        $sql .= ' AND s.departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
    }
    if ($academicYear !== null) {
        $sql .= ' AND s.academicYear = :academicYear';
        $params[':academicYear'] = $academicYear;
    }
    if ($semester !== null && $semester !== '') {
        $sql .= ' AND s.semester = :semester';
        $params[':semester'] = $semester;
    }
    if ($yearLevel !== null && $yearLevel !== '') {
        $sql .= ' AND s.yearLevel = :yearLevel';
        $params[':yearLevel'] = $yearLevel;
    }
    if ($blockNumber !== null) {
        $sql .= ' AND s.blockNumber = :blockNumber';
        $params[':blockNumber'] = $blockNumber;
    }
    if ($blockName !== null && $blockName !== '') {
        $sql .= ' AND s.blockName = :blockName';
        $params[':blockName'] = $blockName;
    }

    $sql .= ' ORDER BY
                FIELD(s.yearLevel, \'1st Year\', \'2nd Year\', \'3rd Year\', \'4th Year\'),
                s.blockNumber IS NULL,
                s.blockNumber ASC,
                FIELD(LOWER(s.status), \'conflict\', \'draft\', \'confirmed\'),
                FIELD(s.day, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'),
                s.startTime ASC,
                sub.code ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('mapScheduleRow', $stmt->fetchAll());
}

/**
 * Schedules linked to a class block (Dean / Program Head view).
 *
 * @return list<array<string,mixed>>
 */
function fetchSchedulesForClassBlock(string $classBlockId): array
{
    $sql = scheduleSelectSql() . '
        WHERE s.classBlockId = :classBlockId
        ORDER BY FIELD(s.day, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'),
                 s.startTime ASC,
                 sub.code ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute([':classBlockId' => $classBlockId]);
    return array_map('mapScheduleRow', $stmt->fetchAll());
}

/**
 * Distinct year-level / block options for filter dropdowns.
 *
 * @return array{yearLevels:list<string>,blocks:list<array{yearLevel:string,blockNumber:int,blockName:string}>}
 */
function fetchScheduleFilterOptions(
    ?string $departmentId = null,
    ?int $academicYear = null,
    ?string $semester = null
): array {
    $sql = 'SELECT DISTINCT yearLevel, blockNumber, blockName
            FROM schedule
            WHERE yearLevel IS NOT NULL
              AND blockNumber IS NOT NULL
              AND blockName IS NOT NULL
              AND blockName <> \'\'';
    $params = [];
    if ($departmentId !== null && $departmentId !== '') {
        $sql .= ' AND departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
    }
    if ($academicYear !== null) {
        $sql .= ' AND academicYear = :academicYear';
        $params[':academicYear'] = $academicYear;
    }
    if ($semester !== null && $semester !== '') {
        $sql .= ' AND semester = :semester';
        $params[':semester'] = $semester;
    }
    $sql .= ' ORDER BY
                FIELD(yearLevel, \'1st Year\', \'2nd Year\', \'3rd Year\', \'4th Year\'),
                blockNumber ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $blocks = [];
    $yearLevels = [];
    foreach ($stmt->fetchAll() as $row) {
        $yl = (string) $row['yearLevel'];
        if (!in_array($yl, $yearLevels, true)) {
            $yearLevels[] = $yl;
        }
        $blocks[] = [
            'yearLevel' => $yl,
            'blockNumber' => (int) $row['blockNumber'],
            'blockName' => (string) $row['blockName'],
        ];
    }

    return [
        'yearLevels' => $yearLevels,
        'blocks' => $blocks,
    ];
}

/**
 * Faculty self-service teaching schedule (own facultyId only).
 * Shows confirmed and conflict rows for the current term so overlapping
 * AI / multi-block assignments remain visible to the instructor.
 *
 * @return list<array<string,mixed>>
 */
function fetchFacultyOwnSchedules(string $facultyId): array
{
    $term = currentTermWindow();
    $sql = scheduleSelectSql() . '
        WHERE s.facultyId = :facultyId
          AND LOWER(s.status) IN (\'confirmed\', \'conflict\')
          AND s.academicYear = :academicYear
          AND s.semester = :semester
        ORDER BY FIELD(s.day, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'),
                 s.startTime ASC,
                 sub.code ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':facultyId' => $facultyId,
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ]);

    return array_map('mapScheduleRow', $stmt->fetchAll());
}

/**
 * Dean oversight: teaching schedules for the current term (draft, confirmed, conflict).
 * Optionally filter by faculty and/or room. When faculty unfiltered, includes TBF rows.
 *
 * @return list<array<string,mixed>>
 */
function fetchDeanFacultySchedules(
    ?string $facultyId = null,
    ?string $roomId = null,
    ?string $viewerDepartmentId = null
): array {
    $term = currentTermWindow();
    $sql = scheduleSelectSql() . '
        WHERE LOWER(s.status) IN (\'draft\', \'confirmed\', \'conflict\')
          AND s.academicYear = :academicYear
          AND s.semester = :semester';
    $params = [
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ];

    if ($viewerDepartmentId !== null && $viewerDepartmentId !== '') {
        $sql .= ' AND (
            sub.departmentId = :viewerDepartmentId
            OR sub.servingDepartmentId = :viewerDepartmentIdServe
        )';
        $params[':viewerDepartmentId'] = $viewerDepartmentId;
        $params[':viewerDepartmentIdServe'] = $viewerDepartmentId;
    }

    if ($facultyId !== null && $facultyId !== '') {
        if (strcasecmp($facultyId, SCHEDULE_INSTRUCTOR_TBF) === 0) {
            $sql .= ' AND (s.facultyId IS NULL OR s.facultyId = \'\')';
        } else {
            $sql .= ' AND s.facultyId = :facultyId';
            $params[':facultyId'] = $facultyId;
        }
    }

    if ($roomId !== null && $roomId !== '') {
        $sql .= ' AND s.roomId = :roomId';
        $params[':roomId'] = $roomId;
    }

    $sql .= '
        ORDER BY f.lastName ASC, f.firstName ASC,
                 FIELD(s.day, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'),
                 s.startTime ASC,
                 sub.code ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('mapScheduleRow', $stmt->fetchAll());
}

/**
 * Existing term meetings used as hard reservations for the optimizer
 * (room + assigned faculty must not double-book).
 *
 * @return list<array{facultyId:string,roomId:string,subjectId:string,day:string,startTime:string,endTime:string}>
 */
function fetchTermScheduleReservations(?string $departmentId = null): array
{
    $term = currentTermWindow();
    $sql = 'SELECT s.facultyId, s.roomId, s.subjectId, s.day, s.startTime, s.endTime
            FROM schedule s
            WHERE s.academicYear = :academicYear
              AND s.semester = :semester
              AND LOWER(s.status) IN (\'confirmed\', \'conflict\')';
    $params = [
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ];
    if ($departmentId !== null && $departmentId !== '') {
        $sql .= ' AND s.departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
    }

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $start = normalizeScheduleTime((string) $row['startTime']);
        $end = normalizeScheduleTime((string) $row['endTime']);
        if ($start === null || $end === null) {
            continue;
        }
        $out[] = [
            'facultyId' => $row['facultyId'] !== null ? (string) $row['facultyId'] : '',
            'roomId' => (string) $row['roomId'],
            'subjectId' => (string) $row['subjectId'],
            'day' => (string) $row['day'],
            'startTime' => $start,
            'endTime' => $end,
        ];
    }

    return $out;
}

function normalizeScheduleDay(string $day): ?string
{
    $trimmed = trim($day);
    foreach (SCHEDULE_DAYS as $canonical) {
        if (strcasecmp($canonical, $trimmed) === 0) {
            return $canonical;
        }
    }
    return null;
}

function normalizeScheduleTime(string $time): ?string
{
    $trimmed = trim($time);
    if (preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $trimmed) !== 1) {
        return null;
    }

    $parts = explode(':', $trimmed);
    $hour = (int) $parts[0];
    $minute = (int) $parts[1];
    $second = isset($parts[2]) ? (int) $parts[2] : 0;

    if ($hour > 23 || $minute > 59 || $second > 59) {
        return null;
    }

    return sprintf('%02d:%02d:%02d', $hour, $minute, $second);
}

/**
 * Validate payload fields shared by create/import/update.
 *
 * Accepts either subjectId, or subjectCode (+ optional subjectName) which will
 * resolve/create a curriculum subject for the department + term semester.
 *
 * @param array<string,mixed> $input
 * @return array{facultyId:?string,roomId:string,departmentId:string,subjectId:string,day:string,startTime:string,endTime:string,academicYear:int,semester:string}
 */
function validateScheduleInput(array $input): array
{
    $facultyRaw = trim((string) ($input['facultyId'] ?? ''));
    // Empty / TBF → unassigned instructor
    if ($facultyRaw === '' || strcasecmp($facultyRaw, SCHEDULE_INSTRUCTOR_TBF) === 0) {
        $facultyId = null;
    } else {
        $facultyId = $facultyRaw;
    }
    $roomId = trim((string) ($input['roomId'] ?? ''));
    $departmentId = trim((string) ($input['departmentId'] ?? ''));
    $subjectId = trim((string) ($input['subjectId'] ?? ''));
    $subjectCode = strtoupper(trim((string) ($input['subjectCode'] ?? '')));
    $subjectName = trim((string) ($input['subjectName'] ?? ''));
    $dayRaw = trim((string) ($input['day'] ?? ''));
    $startRaw = trim((string) ($input['startTime'] ?? ''));
    $endRaw = trim((string) ($input['endTime'] ?? ''));

    $yearRaw = $input['academicYear'] ?? null;
    $yearInt = $yearRaw === null || $yearRaw === '' ? null : (int) $yearRaw;
    $semesterRaw = isset($input['semester']) ? trim((string) $input['semester']) : null;
    $term = normalizeTermFields($yearInt, $semesterRaw);

    $missing = [];
    foreach (
        [
            'roomId' => $roomId,
            'departmentId' => $departmentId,
            'day' => $dayRaw,
            'startTime' => $startRaw,
            'endTime' => $endRaw,
        ] as $field => $value
    ) {
        if ($value === '') {
            $missing[] = $field;
        }
    }
    if ($missing !== []) {
        throw new InvalidArgumentException('Missing required fields: ' . implode(', ', $missing));
    }

    if ($subjectId === '' && $subjectCode === '') {
        throw new InvalidArgumentException('Missing required fields: subjectId or subjectCode.');
    }

    $day = normalizeScheduleDay($dayRaw);
    if ($day === null) {
        throw new InvalidArgumentException('day must be a weekday name (Monday–Sunday).');
    }

    $startTime = normalizeScheduleTime($startRaw);
    $endTime = normalizeScheduleTime($endRaw);
    if ($startTime === null || $endTime === null) {
        throw new InvalidArgumentException('startTime and endTime must be HH:MM or HH:MM:SS.');
    }
    if ($startTime >= $endTime) {
        throw new InvalidArgumentException('endTime must be after startTime.');
    }

    if ($facultyId !== null) {
        assertFacultyExists($facultyId);
    }
    assertRoomExists($roomId);
    assertDepartmentExistsForSchedule($departmentId);

    if ($subjectId !== '') {
        $subject = assertSubjectExistsForSchedule($subjectId, $departmentId);
    } else {
        $curriculumSemester = curriculumSemesterFromScheduleSemester($term['semester']);
        $yearLevel = trim((string) ($input['yearLevel'] ?? SUBJECT_YEAR_1));
        if (!in_array($yearLevel, SUBJECT_YEAR_LEVELS, true)) {
            $yearLevel = SUBJECT_YEAR_1;
        }
        $units = isset($input['units']) ? (float) $input['units'] : 3.0;
        $subject = findOrCreateSubjectByCode(
            $departmentId,
            $subjectCode,
            '',
            $yearLevel,
            $curriculumSemester,
            $units
        );
    }

    return [
        'facultyId' => $facultyId,
        'roomId' => $roomId,
        'departmentId' => $departmentId,
        'subjectId' => (string) $subject['uid'],
        'day' => $day,
        'startTime' => $startTime,
        'endTime' => $endTime,
        'academicYear' => $term['academicYear'],
        'semester' => $term['semester'],
    ];
}

/**
 * @return array<string,mixed>
 */
function assertSubjectExistsForSchedule(string $subjectId, string $departmentId): array
{
    $subject = fetchSubjectById($subjectId);
    if ($subject === null) {
        throw new InvalidArgumentException('Subject not found.');
    }
    $owned = (string) $subject['departmentId'] === $departmentId;
    $type = strtoupper((string) ($subject['subjectType'] ?? 'MAJOR'));
    $servingId = $subject['servingDepartmentId'] ?? null;
    $servingId = ($servingId !== null && $servingId !== '') ? (string) $servingId : null;
    $servedHere = $servingId !== null && $servingId === $departmentId;
    $servesElsewhere = $servingId !== null && $servingId !== $departmentId;

    // Owned majors (not serving another dept), or any subject that Serves this dept.
    $allowed = ($owned && $type === 'MAJOR' && !$servesElsewhere) || $servedHere;
    if (!$allowed) {
        throw new InvalidArgumentException(
            'Subject is not available for the selected department (own major or Serves this department).'
        );
    }
    if (strcasecmp((string) $subject['status'], SUBJECT_STATUS_ARCHIVED) === 0) {
        throw new InvalidArgumentException('Cannot schedule an archived subject.');
    }

    return $subject;
}

function assertFacultyExists(string $facultyId): void
{
    $stmt = db()->prepare(
        'SELECT uid, role, status FROM `user` WHERE uid = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $facultyId]);
    $row = $stmt->fetch();
    if (!$row) {
        throw new InvalidArgumentException('facultyId not found.');
    }
    if (($row['role'] ?? '') !== 'Faculty') {
        throw new InvalidArgumentException('facultyId must reference a Faculty user.');
    }
    if (($row['status'] ?? '') !== 'Active') {
        throw new InvalidArgumentException('Faculty user is not active.');
    }
}

function assertRoomExists(string $roomId): void
{
    $stmt = db()->prepare('SELECT uid FROM room WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $roomId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('roomId not found.');
    }
}

/**
 * Hard rule: a schedule cannot be created/updated if the room is already
 * occupied in the same term at an overlapping day/time.
 */
function assertRoomAvailableAt(
    string $roomId,
    string $day,
    string $startTime,
    string $endTime,
    int $academicYear,
    string $semester,
    ?string $excludeScheduleId = null
): void {
    $roomId = trim($roomId);
    if ($roomId === '') {
        throw new InvalidArgumentException('roomId is required.');
    }

    $start = normalizeScheduleTime($startTime);
    $end = normalizeScheduleTime($endTime);
    if ($start === null || $end === null) {
        throw new InvalidArgumentException('Invalid startTime/endTime for room availability check.');
    }

    $sql = 'SELECT s.uid,
                   sub.code AS subjectCode,
                   s.day,
                   s.startTime,
                   s.endTime,
                   s.status,
                   s.blockName,
                   r.name AS roomName,
                   r.building AS roomBuilding
            FROM schedule s
            INNER JOIN subject sub ON sub.uid = s.subjectId
            INNER JOIN room r ON r.uid = s.roomId
            WHERE s.roomId = :roomId
              AND s.academicYear = :academicYear
              AND s.semester = :semester
              AND LOWER(s.day) = LOWER(:day)
              AND s.startTime < :endTime
              AND s.endTime > :startTime';
    $params = [
        ':roomId' => $roomId,
        ':academicYear' => $academicYear,
        ':semester' => $semester,
        ':day' => $day,
        ':startTime' => $start . ':00',
        ':endTime' => $end . ':00',
    ];
    if ($excludeScheduleId !== null && $excludeScheduleId !== '') {
        $sql .= ' AND s.uid <> :excludeUid';
        $params[':excludeUid'] = $excludeScheduleId;
    }
    $sql .= ' ORDER BY s.startTime ASC LIMIT 3';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $hits = $stmt->fetchAll();
    if ($hits === []) {
        return;
    }

    $examples = [];
    foreach ($hits as $hit) {
        $block = trim((string) ($hit['blockName'] ?? ''));
        $examples[] = sprintf(
            '%s %s–%s (%s%s)',
            (string) $hit['subjectCode'],
            substr((string) $hit['startTime'], 0, 5),
            substr((string) $hit['endTime'], 0, 5),
            (string) $hit['status'],
            $block !== '' ? ', ' . $block : ''
        );
    }

    $roomLabel = trim((string) ($hits[0]['roomBuilding'] ?? '') . ' / ' . (string) ($hits[0]['roomName'] ?? ''));
    throw new InvalidArgumentException(
        sprintf(
            'Room %s is not available on %s %s–%s. Occupied by: %s. Choose another room or time.',
            $roomLabel !== '/' ? $roomLabel : $roomId,
            $day,
            $start,
            $end,
            implode('; ', $examples)
        )
    );
}

function scheduleTimesOverlap(string $startA, string $endA, string $startB, string $endB): bool
{
    $a0 = normalizeScheduleTime($startA);
    $a1 = normalizeScheduleTime($endA);
    $b0 = normalizeScheduleTime($startB);
    $b1 = normalizeScheduleTime($endB);
    if ($a0 === null || $a1 === null || $b0 === null || $b1 === null) {
        return false;
    }
    return $a0 < $b1 && $a1 > $b0;
}

function isRoomAvailableAt(
    string $roomId,
    string $day,
    string $startTime,
    string $endTime,
    int $academicYear,
    string $semester,
    ?string $excludeScheduleId = null
): bool {
    try {
        assertRoomAvailableAt(
            $roomId,
            $day,
            $startTime,
            $endTime,
            $academicYear,
            $semester,
            $excludeScheduleId
        );
        return true;
    } catch (InvalidArgumentException $e) {
        return false;
    }
}

/**
 * Hard rule: faculty cannot be double-booked on overlapping day/time in the term.
 *
 * @param list<string>|null $excludeScheduleIds
 */
function assertFacultyAvailableAt(
    ?string $facultyId,
    string $day,
    string $startTime,
    string $endTime,
    int $academicYear,
    string $semester,
    string|array|null $excludeScheduleIds = null
): void {
    $facultyId = normalizeAssignmentFacultyId($facultyId);
    if ($facultyId === null) {
        return; // TBF / unassigned — no faculty clash
    }

    $start = normalizeScheduleTime($startTime);
    $end = normalizeScheduleTime($endTime);
    if ($start === null || $end === null) {
        throw new InvalidArgumentException('Invalid startTime/endTime for faculty availability check.');
    }

    $exclude = [];
    if (is_string($excludeScheduleIds) && $excludeScheduleIds !== '') {
        $exclude = [$excludeScheduleIds];
    } elseif (is_array($excludeScheduleIds)) {
        foreach ($excludeScheduleIds as $id) {
            $id = trim((string) $id);
            if ($id !== '') {
                $exclude[] = $id;
            }
        }
    }

    $sql = 'SELECT s.uid,
                   sub.code AS subjectCode,
                   s.day,
                   s.startTime,
                   s.endTime,
                   s.status,
                   s.blockName,
                   CONCAT(f.firstName, \' \', f.lastName) AS facultyName
            FROM schedule s
            INNER JOIN subject sub ON sub.uid = s.subjectId
            INNER JOIN `user` f ON f.uid = s.facultyId
            WHERE s.facultyId = :facultyId
              AND s.academicYear = :academicYear
              AND s.semester = :semester
              AND LOWER(s.day) = LOWER(:day)
              AND s.startTime < :endTime
              AND s.endTime > :startTime
              AND LOWER(s.status) IN (\'confirmed\', \'conflict\', \'draft\')';
    $params = [
        ':facultyId' => $facultyId,
        ':academicYear' => $academicYear,
        ':semester' => $semester,
        ':day' => $day,
        ':startTime' => $start . ':00',
        ':endTime' => $end . ':00',
    ];
    if ($exclude !== []) {
        $ph = [];
        foreach ($exclude as $i => $id) {
            $key = ':ex' . $i;
            $ph[] = $key;
            $params[$key] = $id;
        }
        $sql .= ' AND s.uid NOT IN (' . implode(',', $ph) . ')';
    }
    $sql .= ' ORDER BY s.startTime ASC LIMIT 3';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $hits = $stmt->fetchAll();
    if ($hits === []) {
        return;
    }

    $examples = [];
    foreach ($hits as $hit) {
        $block = trim((string) ($hit['blockName'] ?? ''));
        $examples[] = sprintf(
            '%s %s–%s (%s%s)',
            (string) $hit['subjectCode'],
            substr((string) $hit['startTime'], 0, 5),
            substr((string) $hit['endTime'], 0, 5),
            (string) $hit['status'],
            $block !== '' ? ', ' . $block : ''
        );
    }

    $name = trim((string) ($hits[0]['facultyName'] ?? ''));
    throw new InvalidArgumentException(
        sprintf(
            'Faculty %s is not available on %s %s–%s. Already teaching: %s. '
            . 'Choose another faculty, day, or time.',
            $name !== '' ? $name : $facultyId,
            $day,
            $start,
            $end,
            implode('; ', $examples)
        )
    );
}

function assertDepartmentExistsForSchedule(string $departmentId): void
{
    $stmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $departmentId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('departmentId not found.');
    }
}

/**
 * Insert a draft schedule row.
 *
 * @param array{
 *   facultyId:?string,
 *   roomId:string,
 *   departmentId:string,
 *   subjectId:string,
 *   day:string,
 *   startTime:string,
 *   endTime:string,
 *   academicYear:int,
 *   semester:string,
 *   yearLevel?:?string,
 *   blockNumber?:?int,
 *   blockName?:?string,
 *   classBlockId?:?string,
 *   studentType?:?string
 * } $data
 * @return array<string,mixed>
 */
function createDraftSchedule(array $data, string $createdBy): array
{
    assertRoomAvailableAt(
        (string) $data['roomId'],
        (string) $data['day'],
        (string) $data['startTime'],
        (string) $data['endTime'],
        (int) $data['academicYear'],
        (string) $data['semester']
    );
    assertFacultyAvailableAt(
        isset($data['facultyId']) ? (string) $data['facultyId'] : null,
        (string) $data['day'],
        (string) $data['startTime'],
        (string) $data['endTime'],
        (int) $data['academicYear'],
        (string) $data['semester']
    );

    $uid = generateUid();
    $stmt = db()->prepare(
        'INSERT INTO schedule
            (uid, facultyId, roomId, departmentId, createdBy, subjectId, day, startTime, endTime,
             academicYear, semester, yearLevel, blockNumber, blockName, classBlockId, studentType, status, createdAt)
         VALUES
            (:uid, :facultyId, :roomId, :departmentId, :createdBy, :subjectId, :day, :startTime, :endTime,
             :academicYear, :semester, :yearLevel, :blockNumber, :blockName, :classBlockId, :studentType, :status, NOW())'
    );
    $stmt->execute([
        ':uid' => $uid,
        ':facultyId' => normalizeAssignmentFacultyId($data['facultyId'] ?? null),
        ':roomId' => $data['roomId'],
        ':departmentId' => $data['departmentId'],
        ':createdBy' => $createdBy,
        ':subjectId' => $data['subjectId'],
        ':day' => $data['day'],
        ':startTime' => $data['startTime'],
        ':endTime' => $data['endTime'],
        ':academicYear' => $data['academicYear'],
        ':semester' => $data['semester'],
        ':yearLevel' => $data['yearLevel'] ?? null,
        ':blockNumber' => $data['blockNumber'] ?? null,
        ':blockName' => $data['blockName'] ?? null,
        ':classBlockId' => $data['classBlockId'] ?? null,
        ':studentType' => $data['studentType'] ?? null,
        ':status' => SCHEDULE_STATUS_DRAFT,
    ]);

    $schedule = fetchScheduleById($uid);
    if ($schedule === null) {
        throw new RuntimeException('Failed to load created schedule.');
    }

    return $schedule;
}

/**
 * Update schedule fields (used when Dean edits a conflicting/draft row).
 *
 * @param array{facultyId:string,roomId:string,departmentId:string,subjectId:string,day:string,startTime:string,endTime:string,academicYear:int,semester:string} $data
 * @return array<string,mixed>
 */
function updateScheduleFields(string $scheduleId, array $data): array
{
    assertRoomAvailableAt(
        (string) $data['roomId'],
        (string) $data['day'],
        (string) $data['startTime'],
        (string) $data['endTime'],
        (int) $data['academicYear'],
        (string) $data['semester'],
        $scheduleId
    );
    assertFacultyAvailableAt(
        isset($data['facultyId']) ? (string) $data['facultyId'] : null,
        (string) $data['day'],
        (string) $data['startTime'],
        (string) $data['endTime'],
        (int) $data['academicYear'],
        (string) $data['semester'],
        $scheduleId
    );

    $stmt = db()->prepare(
        'UPDATE schedule
         SET facultyId = :facultyId,
             roomId = :roomId,
             departmentId = :departmentId,
             subjectId = :subjectId,
             day = :day,
             startTime = :startTime,
             endTime = :endTime,
             academicYear = :academicYear,
             semester = :semester,
             status = :status
         WHERE uid = :uid'
    );
    $stmt->execute([
        ':facultyId' => normalizeAssignmentFacultyId($data['facultyId'] ?? null),
        ':roomId' => $data['roomId'],
        ':departmentId' => $data['departmentId'],
        ':subjectId' => $data['subjectId'],
        ':day' => $data['day'],
        ':startTime' => $data['startTime'],
        ':endTime' => $data['endTime'],
        ':academicYear' => $data['academicYear'],
        ':semester' => $data['semester'],
        ':status' => SCHEDULE_STATUS_DRAFT,
        ':uid' => $scheduleId,
    ]);

    $schedule = fetchScheduleById($scheduleId);
    if ($schedule === null) {
        throw new RuntimeException('Schedule not found after update.');
    }

    return $schedule;
}

/**
 * Find room/faculty overlaps on the same day.
 *
 * @return list<array<string,mixed>>
 */
function findScheduleConflicts(string $scheduleId): array
{
    $schedule = fetchScheduleById($scheduleId);
    if ($schedule === null) {
        throw new InvalidArgumentException('Schedule not found.');
    }

    $sql = scheduleSelectSql() . '
        WHERE s.uid <> :uid
          AND s.academicYear = :academicYear
          AND s.semester = :semester
          AND LOWER(s.day) = LOWER(:day)
          AND s.startTime < :endTime
          AND s.endTime > :startTime
          AND (
                s.roomId = :roomId';

    $facultyId = trim((string) ($schedule['facultyId'] ?? ''));
    if ($facultyId !== '') {
        $sql .= '
             OR (s.facultyId IS NOT NULL AND s.facultyId = :facultyId)';
    }
    $sql .= '
          )
        ORDER BY s.startTime ASC';

    $stmt = db()->prepare($sql);
    $params = [
        ':uid' => $scheduleId,
        ':academicYear' => $schedule['academicYear'],
        ':semester' => $schedule['semester'],
        ':day' => $schedule['day'],
        ':startTime' => $schedule['startTime'] . ':00',
        ':endTime' => $schedule['endTime'] . ':00',
        ':roomId' => $schedule['roomId'],
    ];
    if ($facultyId !== '') {
        $params[':facultyId'] = $facultyId;
    }
    $stmt->execute($params);

    $conflicts = [];
    foreach ($stmt->fetchAll() as $row) {
        $mapped = mapScheduleRow($row);
        $types = [];
        if ($mapped['roomId'] === $schedule['roomId']) {
            $types[] = 'room';
        }
        if (
            $facultyId !== ''
            && $mapped['facultyId'] !== ''
            && $mapped['facultyId'] === $facultyId
        ) {
            $types[] = 'faculty';
        }
        $mapped['conflictTypes'] = $types;
        $conflicts[] = $mapped;
    }

    return $conflicts;
}

/**
 * Attempt to move draft/conflict → confirmed. On conflict, mark as conflict and return details.
 *
 * @return array{schedule: array<string,mixed>, conflicts: list<array<string,mixed>>, confirmed: bool}
 */
function attemptConfirmSchedule(string $scheduleId): array
{
    $schedule = fetchScheduleById($scheduleId);
    if ($schedule === null) {
        throw new InvalidArgumentException('Schedule not found.');
    }

    $status = strtolower($schedule['status']);
    if ($status === SCHEDULE_STATUS_CONFIRMED) {
        return [
            'schedule' => $schedule,
            'conflicts' => [],
            'confirmed' => true,
        ];
    }

    $conflicts = findScheduleConflicts($scheduleId);
    if ($conflicts !== []) {
        $stmt = db()->prepare('UPDATE schedule SET status = :status WHERE uid = :uid');
        $stmt->execute([
            ':status' => SCHEDULE_STATUS_CONFLICT,
            ':uid' => $scheduleId,
        ]);

        return [
            'schedule' => fetchScheduleById($scheduleId),
            'conflicts' => $conflicts,
            'confirmed' => false,
        ];
    }

    $stmt = db()->prepare('UPDATE schedule SET status = :status WHERE uid = :uid');
    $stmt->execute([
        ':status' => SCHEDULE_STATUS_CONFIRMED,
        ':uid' => $scheduleId,
    ]);

    return [
        'schedule' => fetchScheduleById($scheduleId),
        'conflicts' => [],
        'confirmed' => true,
    ];
}

/**
 * Manual override: confirm even when conflicts exist.
 *
 * @return array{schedule: array<string,mixed>, conflicts: list<array<string,mixed>>}
 */
function overrideConfirmSchedule(string $scheduleId): array
{
    $schedule = fetchScheduleById($scheduleId);
    if ($schedule === null) {
        throw new InvalidArgumentException('Schedule not found.');
    }

    $conflicts = findScheduleConflicts($scheduleId);

    $stmt = db()->prepare('UPDATE schedule SET status = :status WHERE uid = :uid');
    $stmt->execute([
        ':status' => SCHEDULE_STATUS_CONFIRMED,
        ':uid' => $scheduleId,
    ]);

    return [
        'schedule' => fetchScheduleById($scheduleId),
        'conflicts' => $conflicts,
    ];
}

/**
 * Parse uploaded CSV or XLSX into associative rows.
 *
 * Expected headers: facultyId, roomId, departmentId, subjectCode, subjectName, day, startTime, endTime, academicYear, semester
 * academicYear and semester are optional; missing values default to the current term.
 *
 * @return list<array<string,string>>
 */
function parseScheduleImportFile(string $tmpPath, string $originalName): array
{
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

    if ($ext === 'csv') {
        return parseScheduleCsv($tmpPath);
    }
    if ($ext === 'xlsx') {
        require_once __DIR__ . '/SemScheduleImport.php';
        if (isSemGridWorkbook($tmpPath)) {
            return parseSemGridScheduleXlsx($tmpPath);
        }
        return parseScheduleXlsx($tmpPath);
    }

    throw new InvalidArgumentException('Only CSV or XLSX files are supported.');
}

/**
 * @return list<array<string,string>>
 */
function parseScheduleCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Unable to read CSV file.');
    }

    $header = null;
    $rows = [];
    $line = 0;

    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        $line++;
        if ($data === [null] || $data === false) {
            continue;
        }

        // Strip UTF-8 BOM from first cell.
        if (isset($data[0])) {
            $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $data[0]) ?? (string) $data[0];
        }

        if ($header === null) {
            $header = array_map(static fn($h) => strtolower(trim((string) $h)), $data);
            continue;
        }

        if (count(array_filter($data, static fn($v) => trim((string) $v) !== '')) === 0) {
            continue;
        }

        $assoc = [];
        foreach ($header as $i => $key) {
            $assoc[$key] = trim((string) ($data[$i] ?? ''));
        }
        $assoc['_rowNumber'] = (string) $line;
        $rows[] = $assoc;
    }

    fclose($handle);

    if ($header === null) {
        throw new InvalidArgumentException('CSV file is empty.');
    }

    return $rows;
}

/**
 * Minimal first-sheet XLSX reader (no external library).
 *
 * @return list<array<string,string>>
 */
function parseScheduleXlsx(string $path): array
{
    if (!class_exists(ZipArchive::class, false)) {
        throw new InvalidArgumentException(
            'PHP zip extension is not enabled. In C:\\xampp\\php\\php.ini uncomment extension=zip, then restart Apache.'
        );
    }
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

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();

    if ($sheetXml === false) {
        throw new InvalidArgumentException('XLSX is missing sheet1.');
    }

    $sheet = simplexml_load_string($sheetXml);
    if ($sheet === false) {
        throw new InvalidArgumentException('Unable to parse XLSX sheet.');
    }

    $grid = [];
    foreach ($sheet->sheetData->row as $row) {
        $rIndex = (int) $row['r'];
        foreach ($row->c as $cell) {
            $ref = (string) $cell['r'];
            if (!preg_match('/^([A-Z]+)(\d+)$/', $ref, $m)) {
                continue;
            }
            $col = xlsxColumnIndex($m[1]);
            $type = (string) ($cell['t'] ?? '');
            $value = '';
            if ($type === 's') {
                $idx = (int) $cell->v;
                $value = $sharedStrings[$idx] ?? '';
            } else {
                $value = (string) ($cell->v ?? '');
            }
            $grid[$rIndex][$col] = $value;
        }
    }

    if ($grid === []) {
        throw new InvalidArgumentException('XLSX sheet is empty.');
    }

    ksort($grid);
    $headerRowNum = array_key_first($grid);
    $headerCells = $grid[$headerRowNum];
    ksort($headerCells);
    $headers = [];
    foreach ($headerCells as $col => $label) {
        $headers[$col] = strtolower(trim((string) $label));
    }

    $rows = [];
    foreach ($grid as $rowNum => $cells) {
        if ($rowNum === $headerRowNum) {
            continue;
        }
        $assoc = [];
        $nonEmpty = false;
        foreach ($headers as $col => $key) {
            if ($key === '') {
                continue;
            }
            $value = trim((string) ($cells[$col] ?? ''));
            $assoc[$key] = $value;
            if ($value !== '') {
                $nonEmpty = true;
            }
        }
        if (!$nonEmpty) {
            continue;
        }
        $assoc['_rowNumber'] = (string) $rowNum;
        $rows[] = $assoc;
    }

    return $rows;
}

function xlsxColumnIndex(string $letters): int
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
 * Re-assign later same-room overlapping meetings to a free room/time/day (7:00 AM–9:00 PM).
 * Keeps the earlier meeting; moves the later one — prefers another room same day, then another day.
 *
 * @return array{
 *   moved:list<array<string,mixed>>,
 *   failed:list<array<string,mixed>>,
 *   kept:int,
 *   pairsFound:int
 * }
 */
function reassignSameRoomOverlapsForCurrentTerm(): array
{
    require_once __DIR__ . '/Room.php';

    $term = currentTermWindow();
    $stmt = db()->prepare(
        "SELECT s.uid,
                s.facultyId,
                s.roomId,
                s.departmentId,
                s.subjectId,
                s.day,
                s.startTime,
                s.endTime,
                s.academicYear,
                s.semester,
                s.status,
                s.blockName,
                s.createdAt,
                sub.code AS subjectCode,
                r.building,
                r.name AS roomName
         FROM schedule s
         INNER JOIN subject sub ON sub.uid = s.subjectId
         INNER JOIN room r ON r.uid = s.roomId
         WHERE s.academicYear = :academicYear
           AND s.semester = :semester
           AND LOWER(s.status) IN ('confirmed', 'conflict', 'draft')
           AND s.roomId IS NOT NULL
           AND s.roomId <> ''
         ORDER BY r.building, r.name,
                  FIELD(s.day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'),
                  s.startTime ASC,
                  s.createdAt ASC,
                  s.uid ASC"
    );
    $stmt->execute([
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ]);
    $rows = $stmt->fetchAll();

    $overlap = static function (array $a, array $b): bool {
        if (strcasecmp((string) $a['day'], (string) $b['day']) !== 0) {
            return false;
        }
        if ((string) $a['roomId'] !== (string) $b['roomId']) {
            return false;
        }
        return $a['startTime'] < $b['endTime'] && $b['startTime'] < $a['endTime'];
    };

    $toMove = [];
    $pairsFound = 0;
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (!$overlap($rows[$i], $rows[$j])) {
                continue;
            }
            $pairsFound++;
            // Keep earlier row ($i is earlier by sort); move later ($j).
            $toMove[(string) $rows[$j]['uid']] = $rows[$j];
        }
    }

    if ($toMove === []) {
        return ['moved' => [], 'failed' => [], 'kept' => $n, 'pairsFound' => 0];
    }

    $rooms = fetchRooms();
    $moved = [];
    $failed = [];

    foreach ($toMove as $row) {
        $uid = (string) $row['uid'];
        $day = (string) $row['day'];
        $start = substr((string) $row['startTime'], 0, 5);
        $end = substr((string) $row['endTime'], 0, 5);
        $startMin = ((int) substr($start, 0, 2)) * 60 + (int) substr($start, 3, 2);
        $endMin = ((int) substr($end, 0, 2)) * 60 + (int) substr($end, 3, 2);
        $duration = max(30, $endMin - $startMin);

        $candidate = findFreeRoomSlotForReassign(
            $rooms,
            $day,
            $duration,
            (int) $row['academicYear'],
            (string) $row['semester'],
            $uid,
            $start,
            $end
        );

        if ($candidate === null) {
            $failed[] = [
                'uid' => $uid,
                'subjectCode' => (string) $row['subjectCode'],
                'day' => $day,
                'from' => trim((string) $row['building'] . ' / ' . (string) $row['roomName']),
                'reason' => 'No free room/time on any day between 7:00 AM and 9:00 PM.',
            ];
            continue;
        }

        try {
            updateScheduleFields($uid, [
                'facultyId' => $row['facultyId'],
                'roomId' => $candidate['roomId'],
                'departmentId' => (string) $row['departmentId'],
                'subjectId' => (string) $row['subjectId'],
                'day' => $candidate['day'],
                'startTime' => $candidate['startTime'],
                'endTime' => $candidate['endTime'],
                'academicYear' => (int) $row['academicYear'],
                'semester' => (string) $row['semester'],
            ]);
            $confirm = attemptConfirmSchedule($uid);
            $moved[] = [
                'uid' => $uid,
                'subjectCode' => (string) $row['subjectCode'],
                'day' => $day,
                'from' => trim((string) $row['building'] . ' / ' . (string) $row['roomName'])
                    . ' ' . $day . ' ' . $start . '–' . $end,
                'to' => $candidate['roomLabel']
                    . ' ' . $candidate['day'] . ' '
                    . $candidate['startTime'] . '–' . $candidate['endTime'],
                'status' => (string) ($confirm['schedule']['status'] ?? 'draft'),
            ];
        } catch (Throwable $e) {
            $failed[] = [
                'uid' => $uid,
                'subjectCode' => (string) $row['subjectCode'],
                'day' => $day,
                'from' => trim((string) $row['building'] . ' / ' . (string) $row['roomName']),
                'reason' => $e->getMessage(),
            ];
        }
    }

    return [
        'moved' => $moved,
        'failed' => $failed,
        'kept' => $n - count($toMove),
        'pairsFound' => $pairsFound,
    ];
}

/**
 * Find a free room slot for an overlapping meeting.
 * Order: same day+time other room → same day other time → other days (7:00 AM–9:00 PM).
 *
 * @param list<array<string,mixed>> $rooms
 * @return array{roomId:string,roomLabel:string,day:string,startTime:string,endTime:string}|null
 */
function findFreeRoomSlotForReassign(
    array $rooms,
    string $day,
    int $durationMinutes,
    int $academicYear,
    string $semester,
    string $excludeScheduleId,
    string $preferredStart,
    string $preferredEnd
): ?array {
    $dayStart = 7 * 60;
    $dayEnd = 21 * 60;
    $weekDays = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday',
    ];

    $trySlot = static function (
        string $tryDay,
        string $roomId,
        string $roomLabel,
        string $start,
        string $end
    ) use ($academicYear, $semester, $excludeScheduleId): ?array {
        try {
            assertRoomAvailableAt(
                $roomId,
                $tryDay,
                $start,
                $end,
                $academicYear,
                $semester,
                $excludeScheduleId
            );
            return [
                'roomId' => $roomId,
                'roomLabel' => $roomLabel,
                'day' => $tryDay,
                'startTime' => $start,
                'endTime' => $end,
            ];
        } catch (InvalidArgumentException $e) {
            return null;
        }
    };

    $searchDay = static function (string $tryDay, bool $skipPreferredTime) use (
        $rooms,
        $durationMinutes,
        $dayStart,
        $dayEnd,
        $preferredStart,
        $preferredEnd,
        $trySlot
    ): ?array {
        // Preferred clock time first (unless skipped for "other times" pass).
        if (!$skipPreferredTime) {
            foreach ($rooms as $room) {
                $hit = $trySlot(
                    $tryDay,
                    (string) $room['uid'],
                    (string) ($room['label'] ?? $room['uid']),
                    $preferredStart,
                    $preferredEnd
                );
                if ($hit !== null) {
                    return $hit;
                }
            }
        }

        for ($t = $dayStart; $t + $durationMinutes <= $dayEnd; $t += 30) {
            if ($t < 12 * 60 && ($t + $durationMinutes) > 13 * 60) {
                continue;
            }
            $start = sprintf('%02d:%02d', intdiv($t, 60), $t % 60);
            $endMin = $t + $durationMinutes;
            $end = sprintf('%02d:%02d', intdiv($endMin, 60), $endMin % 60);
            if ($start === $preferredStart && $end === $preferredEnd) {
                continue;
            }
            foreach ($rooms as $room) {
                $hit = $trySlot(
                    $tryDay,
                    (string) $room['uid'],
                    (string) ($room['label'] ?? $room['uid']),
                    $start,
                    $end
                );
                if ($hit !== null) {
                    return $hit;
                }
            }
        }
        return null;
    };

    // 1–2) Same day first.
    $sameDay = $searchDay($day, false);
    if ($sameDay !== null) {
        return $sameDay;
    }

    // 3) Other days — prefer Saturday/Sunday after weekdays for Mon conflicts, etc.
    $otherDays = [];
    foreach ($weekDays as $d) {
        if (strcasecmp($d, $day) !== 0) {
            $otherDays[] = $d;
        }
    }
    // Prefer weekend when leaving a weekday (common Mon/Sat pattern).
    usort($otherDays, static function (string $a, string $b) use ($day): int {
        $weekend = static fn (string $d): int => in_array($d, ['Saturday', 'Sunday'], true) ? 0 : 1;
        $wa = $weekend($a);
        $wb = $weekend($b);
        if ($wa !== $wb && !in_array($day, ['Saturday', 'Sunday'], true)) {
            return $wa <=> $wb;
        }
        return 0;
    });

    foreach ($otherDays as $otherDay) {
        $hit = $searchDay($otherDay, false);
        if ($hit !== null) {
            return $hit;
        }
    }

    return null;
}
