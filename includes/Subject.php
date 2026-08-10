<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';

const SUBJECT_STATUS_ACTIVE = 'Active';
const SUBJECT_STATUS_ARCHIVED = 'Archived';

const SUBJECT_YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
const SUBJECT_SEMESTERS = ['1st Semester', '2nd Semester', 'Summer'];
const SUBJECT_ROOM_TYPES = ['LAB', 'LECTURE'];

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

    return [
        'uid' => (string) $row['uid'],
        'departmentId' => (string) $row['departmentId'],
        'departmentName' => (string) ($row['departmentName'] ?? ''),
        'code' => (string) $row['code'],
        'title' => (string) $row['title'],
        'yearLevel' => (string) $row['yearLevel'],
        'semester' => (string) $row['semester'],
        'curriculumYear' => (int) ($row['curriculumYear'] ?? 0),
        'units' => (float) $row['units'],
        'preferredRoomType' => $preferred,
        'status' => (string) $row['status'],
        'createdAt' => (string) $row['createdAt'],
    ];
}

function subjectSelectSql(): string
{
    return 'SELECT
                s.uid,
                s.departmentId,
                s.code,
                s.title,
                s.yearLevel,
                s.semester,
                s.curriculumYear,
                s.units,
                s.preferredRoomType,
                s.status,
                s.createdAt,
                d.name AS departmentName
            FROM subject s
            INNER JOIN department d ON d.uid = s.departmentId';
}

function fetchSubjectById(string $subjectId): ?array
{
    $stmt = db()->prepare(subjectSelectSql() . ' WHERE s.uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $subjectId]);
    $row = $stmt->fetch();
    return $row ? mapSubjectRow($row) : null;
}

/**
 * @return list<array<string,mixed>>
 */
function fetchSubjects(
    ?string $departmentId = null,
    ?string $yearLevel = null,
    ?string $semester = null,
    ?string $status = SUBJECT_STATUS_ACTIVE,
    string $search = '',
    ?int $curriculumYear = null
): array {
    $sql = subjectSelectSql() . ' WHERE 1 = 1';
    $params = [];

    if ($departmentId !== null && $departmentId !== '') {
        $sql .= ' AND s.departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
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
    if ($status !== null && $status !== '') {
        $sql .= ' AND s.status = :status';
        $params[':status'] = $status;
    }
    if ($search !== '') {
        $sql .= ' AND (s.code LIKE :q OR s.title LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }

    $sql .= ' ORDER BY
                FIELD(s.yearLevel, \'1st Year\', \'2nd Year\', \'3rd Year\', \'4th Year\'),
                FIELD(s.semester, \'1st Semester\', \'2nd Semester\', \'Summer\'),
                s.curriculumYear DESC,
                s.code ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('mapSubjectRow', $stmt->fetchAll());
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function validateSubjectInput(array $input, bool $requireAll = true): array
{
    $departmentId = trim((string) ($input['departmentId'] ?? ''));
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

    $units = (float) $unitsRaw;
    if ($units <= 0 || $units > 30) {
        throw new InvalidArgumentException('units must be between 0.5 and 30.');
    }

    $preferredRoomType = strtoupper(trim((string) ($input['preferredRoomType'] ?? 'LECTURE')));
    if ($preferredRoomType === '') {
        $preferredRoomType = 'LECTURE';
    }
    if (!in_array($preferredRoomType, SUBJECT_ROOM_TYPES, true)) {
        throw new InvalidArgumentException('preferredRoomType must be LAB or LECTURE.');
    }

    if ($departmentId !== '') {
        $stmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
        $stmt->execute([':uid' => $departmentId]);
        if (!$stmt->fetchColumn()) {
            throw new InvalidArgumentException('departmentId not found.');
        }
    }

    return [
        'departmentId' => $departmentId,
        'code' => $code,
        'title' => $title,
        'yearLevel' => $yearLevel,
        'semester' => $semester,
        'curriculumYear' => $curriculumYear,
        'units' => $units,
        'preferredRoomType' => $preferredRoomType,
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
            (uid, departmentId, code, title, yearLevel, semester, curriculumYear, units, preferredRoomType, status, createdAt)
         VALUES
            (:uid, :departmentId, :code, :title, :yearLevel, :semester, :curriculumYear, :units, :preferredRoomType, :status, NOW())'
    );
    $stmt->execute([
        ':uid' => $uid,
        ':departmentId' => $data['departmentId'],
        ':code' => $data['code'],
        ':title' => $data['title'],
        ':yearLevel' => $data['yearLevel'],
        ':semester' => $data['semester'],
        ':curriculumYear' => $data['curriculumYear'],
        ':units' => $data['units'],
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
        'units' => array_key_exists('units', $input)
            ? $input['units']
            : $existing['units'],
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
             code = :code,
             title = :title,
             yearLevel = :yearLevel,
             semester = :semester,
             curriculumYear = :curriculumYear,
             units = :units,
             preferredRoomType = :preferredRoomType
         WHERE uid = :uid'
    );
    $stmt->execute([
        ':departmentId' => $data['departmentId'],
        ':code' => $data['code'],
        ':title' => $data['title'],
        ':yearLevel' => $data['yearLevel'],
        ':semester' => $data['semester'],
        ':curriculumYear' => $data['curriculumYear'],
        ':units' => $data['units'],
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
