<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/env.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Blocking.php';
require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/Schedule.php';

const ENROLLMENT_STATUS_ASSIGNED = 'assigned';
const ENROLLMENT_STATUS_DISTRIBUTED = 'distributed';

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapEnrollmentRow(array $row): array
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
        'studentId' => (string) $row['studentId'],
        'studentName' => trim((string) $row['studentFirstName'] . ' ' . (string) $row['studentLastName']),
        'studentEmail' => (string) $row['studentEmail'],
        'studentSchoolId' => (string) ($row['studentSchoolId'] ?? ''),
        'studentYearLevel' => (string) ($row['studentYearLevel'] ?? ''),
        'studentType' => (string) ($row['studentType'] ?? ''),
        'scheduleId' => (string) $row['scheduleId'],
        'subjectCode' => (string) ($row['subjectCode'] ?? ''),
        'subjectName' => (string) $row['subjectName'],
        'blockName' => (string) ($row['blockName'] ?? ''),
        'day' => (string) $row['day'],
        'startTime' => substr((string) $row['startTime'], 0, 5),
        'endTime' => substr((string) $row['endTime'], 0, 5),
        'roomLabel' => formatRoomDisplayLabel(
            isset($row['roomBuilding']) ? (string) $row['roomBuilding'] : null,
            isset($row['roomName']) ? (string) $row['roomName'] : null
        ),
        'facultyId' => $facultyId,
        'facultyName' => $facultyName,
        'instructor' => $facultyName,
        'departmentId' => (string) $row['departmentId'],
        'departmentName' => (string) $row['departmentName'],
        'academicYear' => isset($row['academicYear']) ? (int) $row['academicYear'] : 0,
        'semester' => (string) ($row['semester'] ?? ''),
        'assignedBy' => (string) $row['assignedBy'],
        'assignedByName' => trim((string) $row['assignerFirstName'] . ' ' . (string) $row['assignerLastName']),
        'status' => (string) $row['status'],
        'createdAt' => (string) $row['createdAt'],
        'scheduleStatus' => (string) $row['scheduleStatus'],
    ];
}

function enrollmentSelectSql(): string
{
    return 'SELECT
                e.uid,
                e.studentId,
                e.scheduleId,
                e.assignedBy,
                e.status,
                e.createdAt,
                sub.code AS subjectCode,
                sub.title AS subjectName,
                s.blockName,
                s.day,
                s.startTime,
                s.endTime,
                s.status AS scheduleStatus,
                s.departmentId,
                s.facultyId,
                s.academicYear,
                s.semester,
                r.name AS roomName,
                r.building AS roomBuilding,
                d.name AS departmentName,
                st.firstName AS studentFirstName,
                st.lastName AS studentLastName,
                st.email AS studentEmail,
                st.schoolId AS studentSchoolId,
                st.yearLevel AS studentYearLevel,
                st.studentType AS studentType,
                f.firstName AS facultyFirstName,
                f.lastName AS facultyLastName,
                a.firstName AS assignerFirstName,
                a.lastName AS assignerLastName
            FROM enrollment e
            INNER JOIN schedule s ON s.uid = e.scheduleId
            INNER JOIN subject sub ON sub.uid = s.subjectId
            INNER JOIN room r ON r.uid = s.roomId
            INNER JOIN department d ON d.uid = s.departmentId
            INNER JOIN userProfile st ON st.uid = e.studentId
            LEFT JOIN `user` f ON f.uid = s.facultyId
            INNER JOIN `user` a ON a.uid = e.assignedBy';
}

/**
 * @return array<string,mixed>|null
 */
function fetchEnrollmentById(string $enrollmentId): ?array
{
    $stmt = db()->prepare(enrollmentSelectSql() . ' WHERE e.uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $enrollmentId]);
    $row = $stmt->fetch();
    return $row ? mapEnrollmentRow($row) : null;
}

/**
 * Cleared students in a department with no assigned/distributed enrollment
 * to a confirmed schedule in the current term.
 *
 * @return list<array<string,mixed>>
 */
function fetchPendingEnrollmentStudents(string $departmentId): array
{
    $term = currentTermWindow();

    $sql = 'SELECT
                u.uid,
                u.firstName,
                u.lastName,
                u.email,
                u.departmentId,
                u.status
            FROM userProfile u
            WHERE u.role = \'Student\'
              AND u.status = \'Active\'
              AND u.departmentId = :departmentId
              AND NOT EXISTS (
                  SELECT 1
                  FROM enrollment e
                  INNER JOIN schedule s ON s.uid = e.scheduleId
                  WHERE e.studentId = u.uid
                    AND LOWER(s.status) = \'confirmed\'
                    AND s.academicYear = :academicYear
                    AND s.semester = :semester
                    AND LOWER(e.status) IN (\'assigned\', \'distributed\')
              )
            ORDER BY u.lastName ASC, u.firstName ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':departmentId' => $departmentId,
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ]);

    $pending = [];
    foreach ($stmt->fetchAll() as $row) {
        $studentId = (string) $row['uid'];
        if (!isStudentCleared($studentId)) {
            continue;
        }

        $pending[] = [
            'uid' => $studentId,
            'firstName' => (string) $row['firstName'],
            'lastName' => (string) $row['lastName'],
            'email' => (string) $row['email'],
            'departmentId' => (string) $row['departmentId'],
            'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
            'cleared' => true,
        ];
    }

    return $pending;
}

/**
 * Confirmed schedules in the department for the current term (enrollment targets).
 *
 * @return list<array<string,mixed>>
 */
function fetchConfirmedSchedulesForDepartment(string $departmentId): array
{
    $term = currentTermWindow();

    $sql = 'SELECT
                s.uid,
                s.facultyId,
                sub.code AS subjectCode,
                sub.title AS subjectName,
                s.day,
                s.startTime,
                s.endTime,
                s.status,
                s.departmentId,
                s.blockName,
                r.name AS roomName,
                r.building AS roomBuilding,
                f.firstName AS facultyFirstName,
                f.lastName AS facultyLastName,
                d.name AS departmentName
            FROM schedule s
            INNER JOIN subject sub ON sub.uid = s.subjectId
            INNER JOIN room r ON r.uid = s.roomId
            LEFT JOIN `user` f ON f.uid = s.facultyId
            INNER JOIN department d ON d.uid = s.departmentId
            WHERE s.departmentId = :departmentId
              AND LOWER(s.status) = \'confirmed\'
              AND s.academicYear = :academicYear
              AND s.semester = :semester
            ORDER BY s.day, s.startTime, sub.code';

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':departmentId' => $departmentId,
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ]);

    return array_map(static function (array $row): array {
        $facultyId = isset($row['facultyId']) && $row['facultyId'] !== null
            ? (string) $row['facultyId']
            : '';
        $facultyName = scheduleInstructorLabel(
            $facultyId !== '' ? $facultyId : null,
            isset($row['facultyFirstName']) ? (string) $row['facultyFirstName'] : null,
            isset($row['facultyLastName']) ? (string) $row['facultyLastName'] : null
        );
        $blockName = (string) ($row['blockName'] ?? '');

        return [
            'uid' => (string) $row['uid'],
            'subjectCode' => (string) $row['subjectCode'],
            'subjectName' => (string) $row['subjectName'],
            'blockName' => $blockName,
            'day' => (string) $row['day'],
            'startTime' => substr((string) $row['startTime'], 0, 5),
            'endTime' => substr((string) $row['endTime'], 0, 5),
            'status' => (string) $row['status'],
            'departmentId' => (string) $row['departmentId'],
            'departmentName' => (string) $row['departmentName'],
            'roomLabel' => formatRoomDisplayLabel(
            isset($row['roomBuilding']) ? (string) $row['roomBuilding'] : null,
            isset($row['roomName']) ? (string) $row['roomName'] : null
        ),
            'facultyId' => $facultyId,
            'facultyName' => $facultyName,
            'instructor' => $facultyName,
            'label' => sprintf(
                '%s%s — %s %s–%s (%s)',
                (string) $row['subjectCode'],
                $blockName !== '' ? ' · ' . $blockName : '',
                (string) $row['day'],
                substr((string) $row['startTime'], 0, 5),
                substr((string) $row['endTime'], 0, 5),
                $facultyName
            ),
        ];
    }, $stmt->fetchAll());
}

/**
 * @return list<array<string,mixed>>
 */
function fetchDepartmentEnrollments(string $departmentId, ?string $status = null): array
{
    $term = currentTermWindow();
    $sql = enrollmentSelectSql() . '
        WHERE s.departmentId = :departmentId
          AND s.academicYear = :academicYear
          AND s.semester = :semester';
    $params = [
        ':departmentId' => $departmentId,
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ];

    if ($status !== null && $status !== '') {
        $sql .= ' AND LOWER(e.status) = :status';
        $params[':status'] = strtolower($status);
    }

    $sql .= ' ORDER BY e.createdAt DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('mapEnrollmentRow', $stmt->fetchAll());
}

/**
 * Student-visible schedules: distributed enrollments only.
 *
 * @return list<array<string,mixed>>
 */
function fetchDistributedStudentSchedule(string $studentId): array
{
    $term = currentTermWindow();
    $sql = enrollmentSelectSql() . '
        WHERE e.studentId = :studentId
          AND LOWER(e.status) = :status
          AND LOWER(s.status) = \'confirmed\'
          AND s.academicYear = :academicYear
          AND s.semester = :semester
        ORDER BY FIELD(s.day, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'),
                 s.startTime ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute([
        ':studentId' => $studentId,
        ':status' => ENROLLMENT_STATUS_DISTRIBUTED,
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ]);

    return array_map('mapEnrollmentRow', $stmt->fetchAll());
}

/**
 * Dean oversight: enrolled student schedules for the current term
 * (assigned or distributed). Optionally filter to one student.
 *
 * @return list<array<string,mixed>>
 */
function fetchDeanStudentSchedules(?string $studentId = null, ?string $viewerDepartmentId = null): array
{
    $term = currentTermWindow();
    $sql = enrollmentSelectSql() . '
        WHERE LOWER(e.status) IN (\'assigned\', \'distributed\')
          AND LOWER(s.status) = \'confirmed\'
          AND s.academicYear = :academicYear
          AND s.semester = :semester';
    $params = [
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ];

    if ($studentId !== null && $studentId !== '') {
        $sql .= ' AND e.studentId = :studentId';
        $params[':studentId'] = $studentId;
    }

    if ($viewerDepartmentId !== null && $viewerDepartmentId !== '') {
        $sql .= ' AND (
            s.departmentId = :viewerDepartmentId
            OR st.departmentId = :viewerStudentDept
            OR sub.servingDepartmentId = :viewerServeDept
        )';
        $params[':viewerDepartmentId'] = $viewerDepartmentId;
        $params[':viewerStudentDept'] = $viewerDepartmentId;
        $params[':viewerServeDept'] = $viewerDepartmentId;
    }

    $sql .= '
        ORDER BY st.lastName ASC, st.firstName ASC,
                 FIELD(s.day, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\',\'Sunday\'),
                 s.startTime ASC,
                 sub.code ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('mapEnrollmentRow', $stmt->fetchAll());
}

/**
 * @return array<string,mixed>
 */
function createEnrollment(string $studentId, string $scheduleId, string $assignedBy): array
{
    if (!isStudentCleared($studentId)) {
        $block = getActiveBlock($studentId);
        $reason = $block['reason'] ?? 'Student has an active block.';
        throw new DomainException('NOT_CLEARED:' . $reason);
    }

    $studentStmt = db()->prepare(
        'SELECT uid, role, status, departmentId FROM userProfile WHERE uid = :uid LIMIT 1'
    );
    $studentStmt->execute([':uid' => $studentId]);
    $student = $studentStmt->fetch();
    if (!$student || ($student['role'] ?? '') !== 'Student') {
        throw new InvalidArgumentException('Student not found.');
    }
    if (($student['status'] ?? '') !== 'Active') {
        throw new InvalidArgumentException('Student account is not active.');
    }

    $scheduleStmt = db()->prepare(
        'SELECT s.uid, s.departmentId, s.status, sub.title AS subjectName
         FROM schedule s
         INNER JOIN subject sub ON sub.uid = s.subjectId
         WHERE s.uid = :uid
         LIMIT 1'
    );
    $scheduleStmt->execute([':uid' => $scheduleId]);
    $schedule = $scheduleStmt->fetch();
    if (!$schedule) {
        throw new InvalidArgumentException('Schedule not found.');
    }
    if (strtolower((string) $schedule['status']) !== 'confirmed') {
        throw new InvalidArgumentException('Only confirmed schedules can receive enrollments.');
    }

    $term = currentTermWindow();
    $inTermStmt = db()->prepare(
        'SELECT uid FROM schedule
         WHERE uid = :uid
           AND academicYear = :academicYear
           AND semester = :semester
         LIMIT 1'
    );
    $inTermStmt->execute([
        ':uid' => $scheduleId,
        ':academicYear' => $term['academicYear'],
        ':semester' => $term['semester'],
    ]);
    if (!$inTermStmt->fetchColumn()) {
        throw new InvalidArgumentException('Schedule is outside the current academic year / semester.');
    }

    if ((string) $student['departmentId'] !== (string) $schedule['departmentId']) {
        throw new InvalidArgumentException('Student and schedule must belong to the same department.');
    }

    $dup = db()->prepare(
        'SELECT uid FROM enrollment WHERE studentId = :studentId AND scheduleId = :scheduleId LIMIT 1'
    );
    $dup->execute([':studentId' => $studentId, ':scheduleId' => $scheduleId]);
    if ($dup->fetchColumn()) {
        throw new InvalidArgumentException('Student is already enrolled in this schedule.');
    }

    $uid = generateUid();
    $insert = db()->prepare(
        'INSERT INTO enrollment (uid, studentId, scheduleId, assignedBy, status, createdAt)
         VALUES (:uid, :studentId, :scheduleId, :assignedBy, :status, NOW())'
    );
    $insert->execute([
        ':uid' => $uid,
        ':studentId' => $studentId,
        ':scheduleId' => $scheduleId,
        ':assignedBy' => $assignedBy,
        ':status' => ENROLLMENT_STATUS_ASSIGNED,
    ]);

    $enrollment = fetchEnrollmentById($uid);
    if ($enrollment === null) {
        throw new RuntimeException('Failed to load created enrollment.');
    }

    return $enrollment;
}

/**
 * @return array<string,mixed>
 */
function distributeEnrollment(string $enrollmentId): array
{
    $enrollment = fetchEnrollmentById($enrollmentId);
    if ($enrollment === null) {
        throw new InvalidArgumentException('Enrollment not found.');
    }

    if (strtolower($enrollment['status']) === ENROLLMENT_STATUS_DISTRIBUTED) {
        return $enrollment;
    }

    if (strtolower($enrollment['status']) !== ENROLLMENT_STATUS_ASSIGNED) {
        throw new InvalidArgumentException('Only assigned enrollments can be distributed.');
    }

    if (strtolower($enrollment['scheduleStatus']) !== 'confirmed') {
        throw new InvalidArgumentException('Cannot distribute enrollment for a non-confirmed schedule.');
    }

    $stmt = db()->prepare(
        'UPDATE enrollment SET status = :status WHERE uid = :uid'
    );
    $stmt->execute([
        ':status' => ENROLLMENT_STATUS_DISTRIBUTED,
        ':uid' => $enrollmentId,
    ]);

    $updated = fetchEnrollmentById($enrollmentId);
    if ($updated === null) {
        throw new RuntimeException('Failed to load distributed enrollment.');
    }

    return $updated;
}

function requireProgramHeadDepartment(string $userId): string
{
    $departmentId = userDepartmentId($userId);
    if ($departmentId === null) {
        jsonError('Program Head has no assigned department.', 403);
    }
    return $departmentId;
}
