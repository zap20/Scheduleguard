<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/Subject.php';

const SCHEDULE_STATUS_DRAFT = 'draft';
const SCHEDULE_STATUS_CONFLICT = 'conflict';
const SCHEDULE_STATUS_CONFIRMED = 'confirmed';
const SCHEDULE_INSTRUCTOR_TBF = 'TBF';

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
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
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
        'roomLabel' => (string) $row['roomBuilding'] . ' / ' . (string) $row['roomName'],
        'departmentId' => (string) $row['departmentId'],
        'departmentName' => (string) $row['departmentName'],
        'createdBy' => (string) $row['createdBy'],
        'subjectId' => (string) ($row['subjectId'] ?? ''),
        'subjectCode' => (string) ($row['subjectCode'] ?? ''),
        'subjectName' => (string) ($row['subjectName'] ?? $row['subjectTitle'] ?? ''),
        'subjectYearLevel' => (string) ($row['subjectYearLevel'] ?? ''),
        'subjectSemester' => (string) ($row['subjectSemester'] ?? ''),
        'subjectUnits' => isset($row['subjectUnits']) ? (float) $row['subjectUnits'] : null,
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
 * Dean oversight: teaching schedules for the current term (confirmed + conflict).
 * Optionally filter to one faculty member. When unfiltered, includes TBF rows.
 *
 * @return list<array<string,mixed>>
 */
function fetchDeanFacultySchedules(?string $facultyId = null): array
{
    $term = currentTermWindow();
    $sql = scheduleSelectSql() . '
        WHERE LOWER(s.status) IN (\'confirmed\', \'conflict\')
          AND s.academicYear = :academicYear
          AND s.semester = :semester';
    $params = [
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ];

    if ($facultyId !== null && $facultyId !== '') {
        if (strcasecmp($facultyId, SCHEDULE_INSTRUCTOR_TBF) === 0) {
            $sql .= ' AND (s.facultyId IS NULL OR s.facultyId = \'\')';
        } else {
            $sql .= ' AND s.facultyId = :facultyId';
            $params[':facultyId'] = $facultyId;
        }
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
            $subjectName !== '' ? $subjectName : $subjectCode,
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
    if ((string) $subject['departmentId'] !== $departmentId) {
        throw new InvalidArgumentException('Subject does not belong to the selected department.');
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
 *   facultyId:string,
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
        ':facultyId' => $data['facultyId'],
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
        ':facultyId' => $data['facultyId'],
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
