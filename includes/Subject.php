<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Department.php';

const SUBJECT_STATUS_ACTIVE = 'Active';
const SUBJECT_STATUS_ARCHIVED = 'Archived';

const SUBJECT_YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
const SUBJECT_SEMESTERS = ['1st Semester', '2nd Semester', 'Summer'];
const SUBJECT_ROOM_TYPES = ['LAB', 'LECTURE'];
const SUBJECT_TYPES = ['MAJOR', 'MINOR'];

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapSubjectRow(array $row): array
{
    $preferred = strtoupper((string) ($row['preferredRoomType'] ?? 'LECTURE'));
    if (!in_array($preferred, SUBJECT_ROOM_TYPES, true)) {
        $preferred = 'LECTURE';
    }

    $subjectType = strtoupper((string) ($row['subjectType'] ?? 'MAJOR'));
    if (!in_array($subjectType, SUBJECT_TYPES, true)) {
        $subjectType = 'MAJOR';
    }

    $lectureHours = (float) ($row['lectureHours'] ?? 0);
    $labHours = (float) ($row['labHours'] ?? 0);
    $labSessionCount = max(1, (int) ($row['labSessionCount'] ?? 1));

    $servingDepartmentId = $row['servingDepartmentId'] ?? null;
    if ($servingDepartmentId !== null && $servingDepartmentId !== '') {
        $servingDepartmentId = (string) $servingDepartmentId;
    } else {
        $servingDepartmentId = null;
    }

    return [
        'uid' => (string) $row['uid'],
        'departmentId' => (string) $row['departmentId'],
        'departmentName' => (string) ($row['departmentName'] ?? ''),
        'servingDepartmentId' => $servingDepartmentId,
        'servingDepartmentName' => (string) ($row['servingDepartmentName'] ?? ''),
        'code' => (string) $row['code'],
        'title' => (string) $row['title'],
        'yearLevel' => (string) $row['yearLevel'],
        'semester' => (string) $row['semester'],
        'curriculumYear' => (int) ($row['curriculumYear'] ?? 0),
        'subjectType' => $subjectType,
        'units' => (float) $row['units'],
        'lectureHours' => $lectureHours,
        'labHours' => $labHours,
        'labSessionCount' => $labSessionCount,
        'preferredRoomType' => $preferred,
        'status' => (string) $row['status'],
        'createdAt' => (string) $row['createdAt'],
        'sessionSummary' => subjectSessionSummary($lectureHours, $labHours),
    ];
}

/**
 * Human-readable lec/lab breakdown for tables.
 * Lab hours are stored as totals; schedule generation may split a lab into 2 days.
 */
function subjectSessionSummary(float $lectureHours, float $labHours): string
{
    $parts = [];
    if ($lectureHours > 0) {
        $parts[] = 'Lec ' . formatSubjectHours($lectureHours);
    }
    if ($labHours > 0) {
        $parts[] = 'Lab ' . formatSubjectHours($labHours);
    }
    return $parts === [] ? '—' : implode(' · ', $parts);
}

function formatSubjectHours(float $hours): string
{
    $totalMinutes = (int) round($hours * 60);
    $h = intdiv($totalMinutes, 60);
    $m = $totalMinutes % 60;
    if ($h > 0 && $m > 0) {
        return $h . 'h ' . $m . 'm';
    }
    if ($h > 0) {
        return $h . 'h';
    }
    return $m . 'm';
}

function subjectSelectSql(): string
{
    return 'SELECT
                s.uid,
                s.departmentId,
                s.servingDepartmentId,
                s.code,
                s.title,
                s.yearLevel,
                s.semester,
                s.curriculumYear,
                s.subjectType,
                s.units,
                s.lectureHours,
                s.labHours,
                s.labSessionCount,
                s.preferredRoomType,
                s.status,
                s.createdAt,
                d.name AS departmentName,
                sd.name AS servingDepartmentName
            FROM subject s
            INNER JOIN department d ON d.uid = s.departmentId
            LEFT JOIN department sd ON sd.uid = s.servingDepartmentId';
}

function fetchSubjectById(string $subjectId): ?array
{
    $stmt = db()->prepare(subjectSelectSql() . ' WHERE s.uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $subjectId]);
    $row = $stmt->fetch();
    return $row ? mapSubjectRow($row) : null;
}

/**
 * @param bool $forScheduling When true, include this department's majors plus
 *                            subjects that Serve this department only (never
 *                            subjects that Serve a different department).
 * @return list<array<string,mixed>>
 */
function fetchSubjects(
    ?string $departmentId = null,
    ?string $yearLevel = null,
    ?string $semester = null,
    ?string $status = SUBJECT_STATUS_ACTIVE,
    string $search = '',
    ?int $curriculumYear = null,
    ?string $subjectType = null,
    bool $forScheduling = false,
    ?string $servingDepartmentId = null
): array {
    $sql = subjectSelectSql() . ' WHERE 1 = 1';
    $params = [];

    if ($departmentId !== null && $departmentId !== '') {
        if ($forScheduling) {
            // Dean schedules only:
            // - majors owned by this department
            // - minors (any owner) whose Serves = this department
            // Never include a subject that Serves a different department.
            $sql .= ' AND (
                (s.departmentId = :departmentIdMajor AND s.subjectType = \'MAJOR\'
                    AND (s.servingDepartmentId IS NULL OR s.servingDepartmentId = :departmentIdMajorServe))
                OR s.servingDepartmentId = :departmentIdServing
            )';
            $params[':departmentIdMajor'] = $departmentId;
            $params[':departmentIdMajorServe'] = $departmentId;
            $params[':departmentIdServing'] = $departmentId;
        } else {
            $sql .= ' AND s.departmentId = :departmentId';
            $params[':departmentId'] = $departmentId;
        }
    }
    if ($servingDepartmentId !== null && $servingDepartmentId !== '') {
        if ($servingDepartmentId === 'none') {
            $sql .= ' AND s.servingDepartmentId IS NULL';
        } else {
            $sql .= ' AND s.servingDepartmentId = :servingDepartmentId';
            $params[':servingDepartmentId'] = $servingDepartmentId;
        }
    }
    if ($yearLevel !== null && $yearLevel !== '') {
        $sql .= ' AND s.yearLevel = :yearLevel';
        $params[':yearLevel'] = $yearLevel;
    }
    if ($semester !== null && $semester !== '') {
        $sql .= ' AND s.semester = :semester';
        $params[':semester'] = $semester;
    }
    if ($curriculumYear !== null) {
        $sql .= ' AND s.curriculumYear = :curriculumYear';
        $params[':curriculumYear'] = $curriculumYear;
    }
    if ($subjectType !== null && $subjectType !== '') {
        $sql .= ' AND s.subjectType = :subjectType';
        $params[':subjectType'] = strtoupper($subjectType);
    }
    if ($status !== null && $status !== '') {
        $sql .= ' AND s.status = :status';
        $params[':status'] = $status;
    }
    if ($search !== '') {
        $sql .= ' AND (s.code LIKE :qCode OR s.title LIKE :qTitle)';
        $params[':qCode'] = '%' . $search . '%';
        $params[':qTitle'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY
                FIELD(s.yearLevel, \'1st Year\', \'2nd Year\', \'3rd Year\', \'4th Year\'),
                FIELD(s.semester, \'1st Semester\', \'2nd Semester\', \'Summer\'),
                FIELD(s.subjectType, \'MAJOR\', \'MINOR\'),
                s.curriculumYear DESC,
                s.code ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('mapSubjectRow', $stmt->fetchAll());
}

/**
 * Expand a subject into lecture/lab schedule sessions (for the optimizer).
 *
 * Lab hours are stored as a total on the subject. At schedule generation the lab
 * is split into 2 equal day-sessions so placement can follow availability
 * (e.g. 3h lab → 2×1.5h, 6h lab → 2×3h).
 *
 * @param array<string,mixed> $subject
 * @return list<array<string,mixed>>
 */
function expandSubjectIntoSessions(array $subject): array
{
    $lectureHours = (float) ($subject['lectureHours'] ?? 0);
    $labHours = (float) ($subject['labHours'] ?? 0);
    $realId = (string) $subject['uid'];
    $code = (string) ($subject['code'] ?? '');
    $title = (string) ($subject['title'] ?? '');
    $units = isset($subject['units']) ? (float) $subject['units'] : null;

    $sessions = [];

    // Legacy fallback: no lec/lab hours → one session from preferredRoomType (90 min).
    if ($lectureHours <= 0 && $labHours <= 0) {
        $preferred = strtoupper((string) ($subject['preferredRoomType'] ?? 'LECTURE'));
        if ($preferred !== 'LAB' && $preferred !== 'LECTURE') {
            $preferred = 'LECTURE';
        }
        $sessions[] = [
            'uid' => $realId . '#MAIN',
            'subjectId' => $realId,
            'code' => $code,
            'title' => $title,
            'units' => $units,
            'lectureHours' => $lectureHours,
            'labHours' => $labHours,
            'preferredRoomType' => $preferred,
            'durationMinutes' => 90,
            'sessionKind' => $preferred === 'LAB' ? 'LAB' : 'LECTURE',
            'sessionIndex' => 1,
            'sessionCount' => 1,
        ];
        return $sessions;
    }

    if ($lectureHours > 0) {
        $sessions[] = [
            'uid' => $realId . '#LEC',
            'subjectId' => $realId,
            'code' => $code,
            'title' => $title . ' (Lecture)',
            'units' => $units,
            'lectureHours' => $lectureHours,
            'labHours' => $labHours,
            'preferredRoomType' => 'LECTURE',
            'durationMinutes' => max(30, (int) round($lectureHours * 60)),
            'sessionKind' => 'LECTURE',
            'sessionIndex' => 1,
            'sessionCount' => 1,
        ];
    }

    if ($labHours > 0) {
        // Always ÷2 at schedule creation; slots land wherever availability allows.
        $labSessionCount = 2;
        $perHours = $labHours / $labSessionCount;
        $durationMinutes = max(30, (int) round($perHours * 60));
        for ($i = 1; $i <= $labSessionCount; $i++) {
            $sessions[] = [
                'uid' => $realId . '#LAB' . $i,
                'subjectId' => $realId,
                'code' => $code,
                'title' => $title . ' (Lab ' . $i . '/2)',
                'units' => $units,
                'lectureHours' => $lectureHours,
                'labHours' => $labHours,
                'preferredRoomType' => 'LAB',
                'durationMinutes' => $durationMinutes,
                'sessionKind' => 'LAB',
                'sessionIndex' => $i,
                'sessionCount' => 2,
            ];
        }
    }

    return $sessions;
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function validateSubjectInput(array $input, bool $requireAll = true): array
{
    $departmentId = trim((string) ($input['departmentId'] ?? ''));
    $servingDepartmentId = trim((string) ($input['servingDepartmentId'] ?? ''));
    $servingDepartmentName = trim((string) ($input['servingDepartmentName'] ?? ''));
    $code = strtoupper(trim((string) ($input['code'] ?? '')));
    $code = preg_replace('/\s+/', ' ', $code) ?? $code;
    $title = trim((string) ($input['title'] ?? ''));
    $yearLevel = trim((string) ($input['yearLevel'] ?? ''));
    $semester = trim((string) ($input['semester'] ?? ''));
    $unitsRaw = $input['units'] ?? 3;
    $curriculumYearRaw = $input['curriculumYear'] ?? null;
    $curriculumYear = $curriculumYearRaw === null || $curriculumYearRaw === ''
        ? (int) date('Y')
        : (int) $curriculumYearRaw;

    $subjectType = strtoupper(trim((string) ($input['subjectType'] ?? 'MAJOR')));
    if ($subjectType === '') {
        $subjectType = 'MAJOR';
    }

    // Typed Serves name wins — resolve after subjectType so it is not overwritten.
    $departmentCreated = false;
    if ($servingDepartmentName !== '') {
        $dept = findOrCreateDepartmentByName($servingDepartmentName);
        $servingDepartmentId = $dept['uid'];
        $departmentCreated = !empty($dept['created']);
        $subjectType = 'MINOR';
    }

    $lectureHours = isset($input['lectureHours']) ? (float) $input['lectureHours'] : 0.0;
    $labHours = isset($input['labHours']) ? (float) $input['labHours'] : 0.0;
    // labSessionCount kept in DB for compatibility; schedule gen always splits lab ÷2.
    $labSessionCount = 1;

    if ($requireAll) {
        $missing = [];
        foreach (
            [
                'departmentId' => $departmentId,
                'code' => $code,
                'title' => $title,
                'yearLevel' => $yearLevel,
                'semester' => $semester,
            ] as $field => $value
        ) {
            if ($value === '') {
                $missing[] = $field;
            }
        }
        if ($missing !== []) {
            throw new InvalidArgumentException('Missing required fields: ' . implode(', ', $missing));
        }
    }

    if ($code !== '' && strlen($code) > 50) {
        throw new InvalidArgumentException('code must be 50 characters or fewer.');
    }
    if ($title !== '' && strlen($title) > 200) {
        throw new InvalidArgumentException('title must be 200 characters or fewer.');
    }
    if ($yearLevel !== '' && !in_array($yearLevel, SUBJECT_YEAR_LEVELS, true)) {
        throw new InvalidArgumentException('yearLevel must be one of: ' . implode(', ', SUBJECT_YEAR_LEVELS));
    }
    if ($semester !== '' && !in_array($semester, SUBJECT_SEMESTERS, true)) {
        throw new InvalidArgumentException('semester must be one of: ' . implode(', ', SUBJECT_SEMESTERS));
    }
    if ($curriculumYear < 2000 || $curriculumYear > 2100) {
        throw new InvalidArgumentException('curriculumYear must be between 2000 and 2100.');
    }
    if (!in_array($subjectType, SUBJECT_TYPES, true)) {
        throw new InvalidArgumentException('subjectType must be MAJOR or MINOR.');
    }

    $units = (float) $unitsRaw;
    if ($units <= 0 || $units > 30) {
        throw new InvalidArgumentException('units must be between 0.5 and 30.');
    }

    if ($lectureHours < 0 || $lectureHours > 12) {
        throw new InvalidArgumentException('lectureHours must be between 0 and 12.');
    }
    if ($labHours < 0 || $labHours > 12) {
        throw new InvalidArgumentException('labHours must be between 0 and 12.');
    }
    if ($lectureHours <= 0 && $labHours <= 0) {
        // Allow legacy preferredRoomType-only create; default 1.5h lecture.
        $lectureHours = 1.5;
    }

    $preferredRoomType = strtoupper(trim((string) ($input['preferredRoomType'] ?? '')));
    if ($preferredRoomType === '' || !in_array($preferredRoomType, SUBJECT_ROOM_TYPES, true)) {
        $preferredRoomType = $labHours > 0 && $lectureHours <= 0 ? 'LAB' : 'LECTURE';
    }

    if ($departmentId !== '') {
        $stmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
        $stmt->execute([':uid' => $departmentId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('departmentId not found.');
        }
    }

    if ($subjectType === 'MAJOR') {
        $servingDepartmentId = '';
    } else {
        // Minors must name the program they serve (may be the same dept as owner).
        if ($servingDepartmentId === '') {
            throw new InvalidArgumentException(
                'Minor subjects require a Serving department (type a name such as CICT or Criminology).'
            );
        }
        $stmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
        $stmt->execute([':uid' => $servingDepartmentId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('servingDepartmentId not found.');
        }
    }

    return [
        'departmentId' => $departmentId,
        'servingDepartmentId' => $servingDepartmentId !== '' ? $servingDepartmentId : null,
        'code' => $code,
        'title' => $title,
        'yearLevel' => $yearLevel,
        'semester' => $semester,
        'curriculumYear' => $curriculumYear,
        'subjectType' => $subjectType,
        'units' => $units,
        'lectureHours' => round($lectureHours, 2),
        'labHours' => round($labHours, 2),
        'labSessionCount' => $labSessionCount,
        'preferredRoomType' => $preferredRoomType,
        'departmentCreated' => $departmentCreated,
    ];
}

function assertSubjectCodeAvailable(
    string $departmentId,
    string $code,
    int $curriculumYear,
    ?string $excludeUid = null
): void {
    $sql = 'SELECT uid FROM subject
            WHERE departmentId = :departmentId
              AND UPPER(code) = :code
              AND curriculumYear = :curriculumYear';
    $params = [
        ':departmentId' => $departmentId,
        ':code' => strtoupper($code),
        ':curriculumYear' => $curriculumYear,
    ];
    if ($excludeUid) {
        $sql .= ' AND uid <> :uid';
        $params[':uid'] = $excludeUid;
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException(
            'A subject with this code already exists in the department for that curriculum year.'
        );
    }
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function createSubject(array $input): array
{
    $data = validateSubjectInput($input, true);
    assertSubjectCodeAvailable($data['departmentId'], $data['code'], $data['curriculumYear']);

    $uid = generateUid();
    $stmt = db()->prepare(
        'INSERT INTO subject
            (uid, departmentId, servingDepartmentId, code, title, yearLevel, semester,
             curriculumYear, subjectType, units, lectureHours, labHours, labSessionCount,
             preferredRoomType, status, createdAt)
         VALUES
            (:uid, :departmentId, :servingDepartmentId, :code, :title, :yearLevel, :semester,
             :curriculumYear, :subjectType, :units, :lectureHours, :labHours, :labSessionCount,
             :preferredRoomType, :status, NOW())'
    );
    $stmt->execute([
        ':uid' => $uid,
        ':departmentId' => $data['departmentId'],
        ':servingDepartmentId' => $data['servingDepartmentId'],
        ':code' => $data['code'],
        ':title' => $data['title'],
        ':yearLevel' => $data['yearLevel'],
        ':semester' => $data['semester'],
        ':curriculumYear' => $data['curriculumYear'],
        ':subjectType' => $data['subjectType'],
        ':units' => $data['units'],
        ':lectureHours' => $data['lectureHours'],
        ':labHours' => $data['labHours'],
        ':labSessionCount' => $data['labSessionCount'],
        ':preferredRoomType' => $data['preferredRoomType'],
        ':status' => SUBJECT_STATUS_ACTIVE,
    ]);

    $subject = fetchSubjectById($uid);
    if ($subject === null) {
        throw new RuntimeException('Failed to load created subject.');
    }
    return $subject;
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function updateSubject(string $subjectId, array $input): array
{
    $existing = fetchSubjectById($subjectId);
    if ($existing === null) {
        throw new InvalidArgumentException('Subject not found.');
    }

    $merged = [
        'departmentId' => array_key_exists('departmentId', $input)
            ? trim((string) $input['departmentId'])
            : $existing['departmentId'],
        'servingDepartmentId' => array_key_exists('servingDepartmentId', $input)
            ? ($input['servingDepartmentId'] === null || $input['servingDepartmentId'] === ''
                ? ''
                : trim((string) $input['servingDepartmentId']))
            : ($existing['servingDepartmentId'] ?? ''),
        'servingDepartmentName' => array_key_exists('servingDepartmentName', $input)
            ? trim((string) $input['servingDepartmentName'])
            : '',
        'code' => array_key_exists('code', $input)
            ? (string) $input['code']
            : $existing['code'],
        'title' => array_key_exists('title', $input)
            ? (string) $input['title']
            : $existing['title'],
        'yearLevel' => array_key_exists('yearLevel', $input)
            ? (string) $input['yearLevel']
            : $existing['yearLevel'],
        'semester' => array_key_exists('semester', $input)
            ? (string) $input['semester']
            : $existing['semester'],
        'curriculumYear' => array_key_exists('curriculumYear', $input)
            ? $input['curriculumYear']
            : $existing['curriculumYear'],
        'subjectType' => array_key_exists('subjectType', $input)
            ? $input['subjectType']
            : $existing['subjectType'],
        'units' => array_key_exists('units', $input)
            ? $input['units']
            : $existing['units'],
        'lectureHours' => array_key_exists('lectureHours', $input)
            ? $input['lectureHours']
            : $existing['lectureHours'],
        'labHours' => array_key_exists('labHours', $input)
            ? $input['labHours']
            : $existing['labHours'],
        'preferredRoomType' => array_key_exists('preferredRoomType', $input)
            ? $input['preferredRoomType']
            : $existing['preferredRoomType'],
    ];

    $data = validateSubjectInput($merged, true);
    assertSubjectCodeAvailable(
        $data['departmentId'],
        $data['code'],
        $data['curriculumYear'],
        $subjectId
    );

    $stmt = db()->prepare(
        'UPDATE subject
         SET departmentId = :departmentId,
             servingDepartmentId = :servingDepartmentId,
             code = :code,
             title = :title,
             yearLevel = :yearLevel,
             semester = :semester,
             curriculumYear = :curriculumYear,
             subjectType = :subjectType,
             units = :units,
             lectureHours = :lectureHours,
             labHours = :labHours,
             labSessionCount = :labSessionCount,
             preferredRoomType = :preferredRoomType
         WHERE uid = :uid'
    );
    $stmt->execute([
        ':departmentId' => $data['departmentId'],
        ':servingDepartmentId' => $data['servingDepartmentId'],
        ':code' => $data['code'],
        ':title' => $data['title'],
        ':yearLevel' => $data['yearLevel'],
        ':semester' => $data['semester'],
        ':curriculumYear' => $data['curriculumYear'],
        ':subjectType' => $data['subjectType'],
        ':units' => $data['units'],
        ':lectureHours' => $data['lectureHours'],
        ':labHours' => $data['labHours'],
        ':labSessionCount' => $data['labSessionCount'],
        ':preferredRoomType' => $data['preferredRoomType'],
        ':uid' => $subjectId,
    ]);

    $subject = fetchSubjectById($subjectId);
    if ($subject === null) {
        throw new RuntimeException('Failed to load updated subject.');
    }
    return $subject;
}

/**
 * Soft-archive a subject (keeps FK history for past schedules).
 *
 * @return array<string,mixed>
 */
function archiveSubject(string $subjectId): array
{
    $existing = fetchSubjectById($subjectId);
    if ($existing === null) {
        throw new InvalidArgumentException('Subject not found.');
    }
    if ($existing['status'] === SUBJECT_STATUS_ARCHIVED) {
        return $existing;
    }

    $stmt = db()->prepare('UPDATE subject SET status = :status WHERE uid = :uid');
    $stmt->execute([
        ':status' => SUBJECT_STATUS_ARCHIVED,
        ':uid' => $subjectId,
    ]);

    $subject = fetchSubjectById($subjectId);
    if ($subject === null) {
        throw new RuntimeException('Failed to load archived subject.');
    }
    return $subject;
}

/**
 * Find active subject by department + code, or create a stub curriculum row.
 * Used by schedule import / legacy bridges.
 *
 * @return array<string,mixed>
 */
function findOrCreateSubjectByCode(
    string $departmentId,
    string $code,
    string $title = '',
    string $yearLevel = '1st Year',
    string $semester = '1st Semester',
    float $units = 3.0,
    ?int $curriculumYear = null
): array {
    $code = strtoupper(trim(preg_replace('/\s+/', ' ', $code) ?? $code));
    $curriculumYear = $curriculumYear ?? (int) date('Y');
    $stmt = db()->prepare(
        'SELECT uid FROM subject
         WHERE departmentId = :departmentId
           AND UPPER(code) = :code
           AND curriculumYear = :curriculumYear
         LIMIT 1'
    );
    $stmt->execute([
        ':departmentId' => $departmentId,
        ':code' => $code,
        ':curriculumYear' => $curriculumYear,
    ]);
    $uid = $stmt->fetchColumn();
    if ($uid) {
        $subject = fetchSubjectById((string) $uid);
        if ($subject !== null) {
            return $subject;
        }
    }

    return createSubject([
        'departmentId' => $departmentId,
        'code' => $code,
        'title' => $title !== '' ? $title : $code,
        'yearLevel' => in_array($yearLevel, SUBJECT_YEAR_LEVELS, true) ? $yearLevel : '1st Year',
        'semester' => in_array($semester, SUBJECT_SEMESTERS, true) ? $semester : '1st Semester',
        'curriculumYear' => $curriculumYear,
        'units' => $units,
        'subjectType' => 'MAJOR',
        'lectureHours' => 1.5,
        'labHours' => 0,
        'labSessionCount' => 1,
    ]);
}

/**
 * Map operational schedule semester (1/2/Summer) to curriculum semester labels.
 */
function curriculumSemesterFromScheduleSemester(string $semester): string
{
    $s = strtolower(trim($semester));
    if ($s === '1' || $s === '1st' || $s === '1st semester') {
        return '1st Semester';
    }
    if ($s === '2' || $s === '2nd' || $s === '2nd semester') {
        return '2nd Semester';
    }
    if ($s === 'summer') {
        return 'Summer';
    }
    return '1st Semester';
}

function scheduleSemesterFromCurriculum(string $semester): string
{
    if ($semester === '2nd Semester') {
        return '2';
    }
    if ($semester === 'Summer') {
        return 'Summer';
    }
    return '1';
}
