<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/Subject.php';
require_once __DIR__ . '/Blocking.php';
require_once __DIR__ . '/Enrollment.php';
require_once __DIR__ . '/StudentEvaluation.php';

const CLASS_BLOCK_STATUS_OPEN = 'Open';
const CLASS_BLOCK_STATUS_CLOSED = 'Closed';
const CLASS_BLOCK_MEMBER_ASSIGNED = 'assigned';
const CLASS_BLOCK_MEMBER_DISTRIBUTED = 'distributed';

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapClassBlockRow(array $row): array
{
    return [
        'uid' => (string) $row['uid'],
        'departmentId' => (string) $row['departmentId'],
        'departmentName' => (string) ($row['departmentName'] ?? ''),
        'yearLevel' => (string) $row['yearLevel'],
        'blockNumber' => (int) $row['blockNumber'],
        'name' => (string) $row['name'],
        'academicYear' => (int) $row['academicYear'],
        'semester' => (string) $row['semester'],
        'studentType' => (string) ($row['studentType'] ?? ''),
        'createdBy' => (string) $row['createdBy'],
        'createdByName' => trim(
            (string) ($row['creatorFirstName'] ?? '') . ' ' . (string) ($row['creatorLastName'] ?? '')
        ),
        'status' => (string) $row['status'],
        'memberCount' => isset($row['memberCount']) ? (int) $row['memberCount'] : 0,
        'scheduleCount' => isset($row['scheduleCount']) ? (int) $row['scheduleCount'] : 0,
        'createdAt' => (string) $row['createdAt'],
        'termLabel' => formatTermLabel((int) $row['academicYear'], (string) $row['semester']),
        'label' => (string) $row['name'],
    ];
}

function classBlockSelectSql(): string
{
    return 'SELECT
                cb.uid,
                cb.departmentId,
                cb.yearLevel,
                cb.blockNumber,
                cb.name,
                cb.academicYear,
                cb.semester,
                cb.studentType,
                cb.createdBy,
                cb.status,
                cb.createdAt,
                d.name AS departmentName,
                u.firstName AS creatorFirstName,
                u.lastName AS creatorLastName,
                (SELECT COUNT(*) FROM classBlockMember m WHERE m.classBlockId = cb.uid) AS memberCount,
                (SELECT COUNT(*) FROM schedule s WHERE s.classBlockId = cb.uid) AS scheduleCount
            FROM classBlock cb
            INNER JOIN department d ON d.uid = cb.departmentId
            INNER JOIN `user` u ON u.uid = cb.createdBy';
}

/**
 * @return array<string,mixed>|null
 */
function fetchClassBlockById(string $classBlockId): ?array
{
    $stmt = db()->prepare(classBlockSelectSql() . ' WHERE cb.uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $classBlockId]);
    $row = $stmt->fetch();
    return $row ? mapClassBlockRow($row) : null;
}

/**
 * @return list<array<string,mixed>>
 */
function fetchClassBlocks(
    ?string $departmentId = null,
    ?string $yearLevel = null,
    ?int $academicYear = null,
    ?string $semester = null
): array {
    $sql = classBlockSelectSql() . ' WHERE 1 = 1';
    $params = [];

    if ($departmentId !== null && $departmentId !== '') {
        $sql .= ' AND cb.departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
    }
    if ($yearLevel !== null && $yearLevel !== '') {
        $sql .= ' AND cb.yearLevel = :yearLevel';
        $params[':yearLevel'] = $yearLevel;
    }
    if ($academicYear !== null) {
        $sql .= ' AND cb.academicYear = :academicYear';
        $params[':academicYear'] = $academicYear;
    }
    if ($semester !== null && $semester !== '') {
        $sql .= ' AND cb.semester = :semester';
        $params[':semester'] = $semester;
    }

    $sql .= ' ORDER BY cb.yearLevel ASC, cb.blockNumber ASC, cb.name ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('mapClassBlockRow', $stmt->fetchAll());
}

/**
 * Next available block number for a year level / department / term.
 */
function nextClassBlockNumber(
    string $departmentId,
    string $yearLevel,
    int $academicYear,
    string $semester
): int {
    $stmt = db()->prepare(
        'SELECT MAX(blockNumber) FROM classBlock
         WHERE departmentId = :departmentId
           AND yearLevel = :yearLevel
           AND academicYear = :academicYear
           AND semester = :semester'
    );
    $stmt->execute([
        ':departmentId' => $departmentId,
        ':yearLevel' => $yearLevel,
        ':academicYear' => $academicYear,
        ':semester' => $semester,
    ]);
    $max = $stmt->fetchColumn();
    return ($max === false || $max === null) ? 1 : ((int) $max + 1);
}

/**
 * Dean creates a class section block (before schedules / student assignment).
 *
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function createClassBlock(array $input, string $createdBy): array
{
    $departmentId = trim((string) ($input['departmentId'] ?? ''));
    $yearLevel = trim((string) ($input['yearLevel'] ?? ''));
    $studentType = strtolower(trim((string) ($input['studentType'] ?? 'regular')));
    if ($studentType === '') {
        $studentType = 'regular';
    }

    $yearRaw = $input['academicYear'] ?? null;
    $yearInt = $yearRaw === null || $yearRaw === '' ? null : (int) $yearRaw;
    $semesterRaw = isset($input['semester']) ? trim((string) $input['semester']) : null;
    $term = normalizeTermFields($yearInt, $semesterRaw);

    if ($departmentId === '') {
        throw new InvalidArgumentException('departmentId is required.');
    }
    if (!in_array($yearLevel, SUBJECT_YEAR_LEVELS, true)) {
        throw new InvalidArgumentException('yearLevel must be one of: ' . implode(', ', SUBJECT_YEAR_LEVELS));
    }

    $deptStmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
    $deptStmt->execute([':uid' => $departmentId]);
    if (!$deptStmt->fetchColumn()) {
        throw new InvalidArgumentException('departmentId not found.');
    }

    $blockNumberRaw = $input['blockNumber'] ?? null;
    if ($blockNumberRaw === null || $blockNumberRaw === '') {
        $blockNumber = nextClassBlockNumber(
            $departmentId,
            $yearLevel,
            $term['academicYear'],
            $term['semester']
        );
    } else {
        $blockNumber = (int) $blockNumberRaw;
        if ($blockNumber < 1) {
            throw new InvalidArgumentException('blockNumber must be >= 1.');
        }
    }

    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') {
        $name = sprintf('%s Block %d', $yearLevel, $blockNumber);
    }

    $dup = db()->prepare(
        'SELECT uid FROM classBlock
         WHERE departmentId = :departmentId
           AND yearLevel = :yearLevel
           AND academicYear = :academicYear
           AND semester = :semester
           AND blockNumber = :blockNumber
         LIMIT 1'
    );
    $dup->execute([
        ':departmentId' => $departmentId,
        ':yearLevel' => $yearLevel,
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
        ':blockNumber' => $blockNumber,
    ]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException(
            sprintf('%s Block %d already exists for this term.', $yearLevel, $blockNumber)
        );
    }

    $uid = generateUid();
    $stmt = db()->prepare(
        'INSERT INTO classBlock
            (uid, departmentId, yearLevel, blockNumber, name, academicYear, semester, studentType, createdBy, status, createdAt)
         VALUES
            (:uid, :departmentId, :yearLevel, :blockNumber, :name, :academicYear, :semester, :studentType, :createdBy, :status, NOW())'
    );
    $stmt->execute([
        ':uid' => $uid,
        ':departmentId' => $departmentId,
        ':yearLevel' => $yearLevel,
        ':blockNumber' => $blockNumber,
        ':name' => $name,
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
        ':studentType' => $studentType,
        ':createdBy' => $createdBy,
        ':status' => CLASS_BLOCK_STATUS_OPEN,
    ]);

    $block = fetchClassBlockById($uid);
    if ($block === null) {
        throw new RuntimeException('Failed to load created class block.');
    }
    return $block;
}

/**
 * @return array<string,mixed>|null
 */
function findClassBlockBySlot(
    string $departmentId,
    string $yearLevel,
    int $academicYear,
    string $semester,
    int $blockNumber
): ?array {
    $stmt = db()->prepare(
        classBlockSelectSql() . '
         WHERE cb.departmentId = :departmentId
           AND cb.yearLevel = :yearLevel
           AND cb.academicYear = :academicYear
           AND cb.semester = :semester
           AND cb.blockNumber = :blockNumber
         LIMIT 1'
    );
    $stmt->execute([
        ':departmentId' => $departmentId,
        ':yearLevel' => $yearLevel,
        ':academicYear' => $academicYear,
        ':semester' => $semester,
        ':blockNumber' => $blockNumber,
    ]);
    $row = $stmt->fetch();
    return $row ? mapClassBlockRow($row) : null;
}

/**
 * Reuse an existing term slot or create one for a student-block workbook import.
 *
 * @return array<string,mixed>
 */
function ensureImportedClassBlock(
    string $departmentId,
    string $yearLevel,
    int $blockNumber,
    string $name,
    int $academicYear,
    string $semester,
    string $createdBy,
    string $studentType = 'regular'
): array {
    $existing = findClassBlockBySlot(
        $departmentId,
        $yearLevel,
        $academicYear,
        $semester,
        $blockNumber
    );
    if ($existing !== null) {
        return $existing;
    }

    try {
        return createClassBlock([
            'departmentId' => $departmentId,
            'yearLevel' => $yearLevel,
            'blockNumber' => $blockNumber,
            'name' => $name,
            'academicYear' => $academicYear,
            'semester' => $semester,
            'studentType' => $studentType,
        ], $createdBy);
    } catch (InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), 'already exists')) {
            throw $e;
        }
        $again = findClassBlockBySlot(
            $departmentId,
            $yearLevel,
            $academicYear,
            $semester,
            $blockNumber
        );
        if ($again === null) {
            throw $e;
        }
        return $again;
    }
}

/**
 * Preview the next block slot without creating a row.
 * Matches ensureNextClassBlock reuse rules (empty Open blocks first).
 *
 * @return array{blockNumber:int,blockName:string,classBlockId:?string}
 */
function peekNextClassBlockSlot(
    string $departmentId,
    string $yearLevel,
    int $academicYear,
    string $semester
): array {
    $reuse = db()->prepare(
        "SELECT cb.uid, cb.blockNumber, cb.name
         FROM classBlock cb
         WHERE cb.departmentId = :departmentId
           AND cb.yearLevel = :yearLevel
           AND cb.academicYear = :academicYear
           AND cb.semester = :semester
           AND LOWER(cb.status) = 'open'
           AND NOT EXISTS (
                SELECT 1 FROM schedule s WHERE s.classBlockId = cb.uid
           )
         ORDER BY cb.blockNumber ASC
         LIMIT 1"
    );
    $reuse->execute([
        ':departmentId' => $departmentId,
        ':yearLevel' => $yearLevel,
        ':academicYear' => $academicYear,
        ':semester' => $semester,
    ]);
    $existing = $reuse->fetch();
    if ($existing) {
        return [
            'blockNumber' => (int) $existing['blockNumber'],
            'blockName' => (string) $existing['name'],
            'classBlockId' => (string) $existing['uid'],
        ];
    }
    $next = nextClassBlockNumber($departmentId, $yearLevel, $academicYear, $semester);
    return [
        'blockNumber' => $next,
        'blockName' => sprintf('%s Block %d', $yearLevel, $next),
        'classBlockId' => null,
    ];
}

/**
 * Create (or reuse) the next class block slot — used by schedule command save.
 * Reuses an Open Dean-created block that still has no schedules attached.
 *
 * @return array{uid:string,blockNumber:int,blockName:string,name:string}
 */
function ensureNextClassBlock(
    string $departmentId,
    string $yearLevel,
    int $academicYear,
    string $semester,
    string $createdBy,
    ?string $studentType = 'regular'
): array {
    $reuse = db()->prepare(
        "SELECT cb.uid, cb.blockNumber, cb.name
         FROM classBlock cb
         WHERE cb.departmentId = :departmentId
           AND cb.yearLevel = :yearLevel
           AND cb.academicYear = :academicYear
           AND cb.semester = :semester
           AND LOWER(cb.status) = 'open'
           AND NOT EXISTS (
                SELECT 1 FROM schedule s WHERE s.classBlockId = cb.uid
           )
         ORDER BY cb.blockNumber ASC
         LIMIT 1"
    );
    $reuse->execute([
        ':departmentId' => $departmentId,
        ':yearLevel' => $yearLevel,
        ':academicYear' => $academicYear,
        ':semester' => $semester,
    ]);
    $existing = $reuse->fetch();
    if ($existing) {
        return [
            'uid' => (string) $existing['uid'],
            'blockNumber' => (int) $existing['blockNumber'],
            'blockName' => (string) $existing['name'],
            'name' => (string) $existing['name'],
        ];
    }

    $number = nextClassBlockNumber($departmentId, $yearLevel, $academicYear, $semester);
    $block = createClassBlock([
        'departmentId' => $departmentId,
        'yearLevel' => $yearLevel,
        'blockNumber' => $number,
        'academicYear' => $academicYear,
        'semester' => $semester,
        'studentType' => $studentType ?? 'regular',
    ], $createdBy);

    return [
        'uid' => (string) $block['uid'],
        'blockNumber' => (int) $block['blockNumber'],
        'blockName' => (string) $block['name'],
        'name' => (string) $block['name'],
    ];
}

/**
 * Claim N class-block slots (reuse empty Open blocks first, then create).
 * Soft-locks claimed empties as "Claiming" so the next lookup cannot reuse them.
 *
 * @return list<array{uid:string,blockNumber:int,blockName:string,name:string}>
 */
function claimClassBlocksBatch(
    string $departmentId,
    string $yearLevel,
    int $academicYear,
    string $semester,
    string $createdBy,
    int $count,
    ?string $studentType = 'regular'
): array {
    $count = max(1, min(8, $count));
    $claimed = [];

    for ($i = 0; $i < $count; $i++) {
        $slot = ensureNextClassBlock(
            $departmentId,
            $yearLevel,
            $academicYear,
            $semester,
            $createdBy,
            $studentType
        );
        $claimed[] = $slot;
        $lock = db()->prepare(
            "UPDATE classBlock SET status = 'Claiming' WHERE uid = :uid"
        );
        $lock->execute([':uid' => $slot['uid']]);
    }

    foreach ($claimed as $slot) {
        $unlock = db()->prepare(
            "UPDATE classBlock SET status = 'Open' WHERE uid = :uid AND status = 'Claiming'"
        );
        $unlock->execute([':uid' => $slot['uid']]);
    }

    return $claimed;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapClassBlockMemberRow(array $row): array
{
    return [
        'uid' => (string) $row['uid'],
        'classBlockId' => (string) $row['classBlockId'],
        'classBlockName' => (string) ($row['classBlockName'] ?? ''),
        'studentId' => (string) $row['studentId'],
        'studentName' => trim((string) $row['studentFirstName'] . ' ' . (string) $row['studentLastName']),
        'studentEmail' => (string) ($row['studentEmail'] ?? ''),
        'schoolId' => (string) ($row['schoolId'] ?? ''),
        'assignedBy' => (string) $row['assignedBy'],
        'assignedByName' => trim(
            (string) ($row['assignerFirstName'] ?? '') . ' ' . (string) ($row['assignerLastName'] ?? '')
        ),
        'status' => (string) $row['status'],
        'createdAt' => (string) $row['createdAt'],
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function fetchClassBlockMembers(string $classBlockId): array
{
    $sql = 'SELECT
                m.uid,
                m.classBlockId,
                m.studentId,
                m.assignedBy,
                m.status,
                m.createdAt,
                cb.name AS classBlockName,
                st.firstName AS studentFirstName,
                st.lastName AS studentLastName,
                st.email AS studentEmail,
                st.schoolId,
                a.firstName AS assignerFirstName,
                a.lastName AS assignerLastName
            FROM classBlockMember m
            INNER JOIN classBlock cb ON cb.uid = m.classBlockId
            INNER JOIN `user` st ON st.uid = m.studentId
            INNER JOIN `user` a ON a.uid = m.assignedBy
            WHERE m.classBlockId = :classBlockId
            ORDER BY st.lastName ASC, st.firstName ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute([':classBlockId' => $classBlockId]);
    return array_map('mapClassBlockMemberRow', $stmt->fetchAll());
}

/**
 * Cleared students in department not yet in the given class block.
 *
 * @return list<array<string,mixed>>
 */
function fetchStudentsAvailableForClassBlock(string $classBlockId, string $departmentId): array
{
    $block = fetchClassBlockById($classBlockId);
    if ($block === null) {
        throw new InvalidArgumentException('Class block not found.');
    }
    if ($block['departmentId'] !== $departmentId) {
        throw new InvalidArgumentException('Class block is outside your department.');
    }

    $sql = 'SELECT u.uid, u.firstName, u.lastName, u.email, u.schoolId, u.departmentId,
                   u.yearLevel, u.studentType, u.enrollmentEvalStatus
            FROM userProfile u
            WHERE u.role = \'Student\'
              AND u.status = \'Active\'
              AND u.departmentId = :departmentId
              AND u.yearLevel = :yearLevel
              AND u.studentType = :studentType
              AND COALESCE(NULLIF(u.enrollmentEvalStatus, \'\'), \'Pending\') = \'Approved\'
              AND NOT EXISTS (
                    SELECT 1 FROM classBlockMember m
                    WHERE m.classBlockId = :classBlockId AND m.studentId = u.uid
              )
            ORDER BY u.lastName ASC, u.firstName ASC';
    $blockStudentType = trim((string) ($block['studentType'] ?? ''));
    if ($blockStudentType === '') {
        $blockStudentType = 'Regular';
    } else {
        $blockStudentType = strcasecmp($blockStudentType, 'irregular') === 0 ? 'Irregular' : 'Regular';
    }
    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':departmentId' => $departmentId,
        ':classBlockId' => $classBlockId,
        ':yearLevel' => $block['yearLevel'],
        ':studentType' => $blockStudentType,
    ]);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $studentId = (string) $row['uid'];
        if (!isStudentCleared($studentId)) {
            continue;
        }
        $out[] = [
            'uid' => $studentId,
            'firstName' => (string) $row['firstName'],
            'lastName' => (string) $row['lastName'],
            'email' => (string) $row['email'],
            'schoolId' => (string) ($row['schoolId'] ?? ''),
            'departmentId' => (string) $row['departmentId'],
            'yearLevel' => (string) ($row['yearLevel'] ?? ''),
            'studentType' => (string) ($row['studentType'] ?? ''),
            'enrollmentEvalStatus' => (string) ($row['enrollmentEvalStatus'] ?? ENROLLMENT_EVAL_PENDING),
            'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
        ];
    }
    return $out;
}

/**
 * Program Head adds a cleared student to a class block.
 * Also enrolls them into every confirmed schedule already linked to the block.
 *
 * @return array{
 *   member:array<string,mixed>,
 *   enrollments:list<array<string,mixed>>,
 *   enrollmentCount:int
 * }
 */
function assignStudentToClassBlock(string $classBlockId, string $studentId, string $assignedBy): array
{
    $block = fetchClassBlockById($classBlockId);
    if ($block === null) {
        throw new InvalidArgumentException('Class block not found.');
    }
    if (strcasecmp($block['status'], CLASS_BLOCK_STATUS_CLOSED) === 0) {
        throw new InvalidArgumentException('Class block is closed.');
    }

    if (!isStudentCleared($studentId)) {
        $hold = getActiveBlock($studentId);
        $reason = $hold['reason'] ?? 'Student has an active block.';
        throw new DomainException('NOT_CLEARED:' . $reason);
    }
    if (!isStudentEnrollmentApproved($studentId)) {
        throw new DomainException(
            'NOT_EVALUATED:Program Head must evaluate and Approve this student before assigning a class block.'
        );
    }

    $studentStmt = db()->prepare(
        'SELECT uid, role, status, departmentId, yearLevel, studentType
         FROM userProfile WHERE uid = :uid LIMIT 1'
    );
    $studentStmt->execute([':uid' => $studentId]);
    $student = $studentStmt->fetch();
    if (!$student || ($student['role'] ?? '') !== 'Student') {
        throw new InvalidArgumentException('Student not found.');
    }
    if (($student['status'] ?? '') !== 'Active') {
        throw new InvalidArgumentException('Student account is not active.');
    }
    if ((string) $student['departmentId'] !== (string) $block['departmentId']) {
        throw new InvalidArgumentException('Student and class block must belong to the same department.');
    }
    $blockYear = (string) ($block['yearLevel'] ?? '');
    $studentYear = trim((string) ($student['yearLevel'] ?? ''));
    if ($blockYear !== '' && $studentYear !== $blockYear) {
        throw new InvalidArgumentException(
            sprintf('Only %s students can join this block (student is %s).', $blockYear, $studentYear !== '' ? $studentYear : 'unset')
        );
    }
    $blockType = trim((string) ($block['studentType'] ?? ''));
    if ($blockType === '') {
        $blockType = 'Regular';
    } else {
        $blockType = strcasecmp($blockType, 'irregular') === 0 ? 'Irregular' : 'Regular';
    }
    $studentType = trim((string) ($student['studentType'] ?? ''));
    if ($studentType === '') {
        $studentType = 'Regular';
    }
    if (strcasecmp($studentType, $blockType) !== 0) {
        throw new InvalidArgumentException(
            sprintf('Only %s students can join this block (student is %s).', $blockType, $studentType)
        );
    }

    $dup = db()->prepare(
        'SELECT uid FROM classBlockMember WHERE classBlockId = :classBlockId AND studentId = :studentId LIMIT 1'
    );
    $dup->execute([':classBlockId' => $classBlockId, ':studentId' => $studentId]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Student is already in this class block.');
    }

    $memberUid = generateUid();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO classBlockMember (uid, classBlockId, studentId, assignedBy, status, createdAt)
             VALUES (:uid, :classBlockId, :studentId, :assignedBy, :status, NOW())'
        );
        $ins->execute([
            ':uid' => $memberUid,
            ':classBlockId' => $classBlockId,
            ':studentId' => $studentId,
            ':assignedBy' => $assignedBy,
            ':status' => CLASS_BLOCK_MEMBER_ASSIGNED,
        ]);

        $schedStmt = $pdo->prepare(
            "SELECT uid FROM schedule
             WHERE classBlockId = :classBlockId
               AND LOWER(status) = 'confirmed'
               AND academicYear = :academicYear
               AND semester = :semester"
        );
        $schedStmt->execute([
            ':classBlockId' => $classBlockId,
            ':academicYear' => $block['academicYear'],
            ':semester' => $block['semester'],
        ]);
        $scheduleIds = array_map(static fn ($r) => (string) $r['uid'], $schedStmt->fetchAll());
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $enrollments = [];
    foreach ($scheduleIds as $scheduleId) {
        try {
            $enrollments[] = createEnrollment($studentId, $scheduleId, $assignedBy);
        } catch (InvalidArgumentException $e) {
            // Already enrolled in that meeting — skip.
            if (!str_contains($e->getMessage(), 'already enrolled')) {
                throw $e;
            }
        }
    }

    $members = fetchClassBlockMembers($classBlockId);
    $member = null;
    foreach ($members as $row) {
        if ($row['uid'] === $memberUid) {
            $member = $row;
            break;
        }
    }
    if ($member === null) {
        throw new RuntimeException('Failed to load class block member.');
    }

    return [
        'member' => $member,
        'enrollments' => $enrollments,
        'enrollmentCount' => count($enrollments),
    ];
}
