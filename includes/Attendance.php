<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

/**
 * Shared read-only attendance queries (attendanceRecord ⋈ schedule ⋈ room ⋈ department ⋈ faculty).
 */
function attendanceSelectSql(): string
{
    return
        'SELECT
            ar.uid AS attendanceUid,
            ar.status AS attendanceStatus,
            ar.isOffline,
            ar.timestamp AS scanTimestamp,
            ar.syncedAt,
            ar.checkerId,
            s.uid AS scheduleUid,
            sub.code AS subjectCode,
            sub.title AS subjectName,
            s.day AS scheduleDay,
            s.startTime,
            s.endTime,
            s.status AS scheduleStatus,
            s.facultyId,
            r.uid AS roomUid,
            r.name AS roomName,
            r.building AS roomBuilding,
            d.uid AS departmentUid,
            d.name AS departmentName,
            f.firstName AS facultyFirstName,
            f.lastName AS facultyLastName,
            f.email AS facultyEmail,
            c.firstName AS checkerFirstName,
            c.lastName AS checkerLastName
         FROM attendanceRecord ar
         INNER JOIN schedule s ON s.uid = ar.scheduleId
         INNER JOIN subject sub ON sub.uid = s.subjectId
         INNER JOIN room r ON r.uid = s.roomId
         INNER JOIN department d ON d.uid = s.departmentId
         INNER JOIN `user` f ON f.uid = s.facultyId
         INNER JOIN `user` c ON c.uid = ar.checkerId';
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapAttendanceRow(array $row): array
{
    return [
        'uid' => (string) $row['attendanceUid'],
        'status' => (string) $row['attendanceStatus'],
        'isOffline' => (bool) $row['isOffline'],
        'timestamp' => (string) $row['scanTimestamp'],
        'syncedAt' => $row['syncedAt'] !== null ? (string) $row['syncedAt'] : null,
        'schedule' => [
            'uid' => (string) $row['scheduleUid'],
            'subjectCode' => (string) ($row['subjectCode'] ?? ''),
            'subjectName' => (string) $row['subjectName'],
            'day' => (string) $row['scheduleDay'],
            'startTime' => substr((string) $row['startTime'], 0, 5),
            'endTime' => substr((string) $row['endTime'], 0, 5),
            'status' => (string) $row['scheduleStatus'],
            'expectedLabel' => sprintf(
                '%s %s–%s',
                (string) $row['scheduleDay'],
                substr((string) $row['startTime'], 0, 5),
                substr((string) $row['endTime'], 0, 5)
            ),
        ],
        'room' => [
            'uid' => (string) $row['roomUid'],
            'name' => (string) $row['roomName'],
            'building' => (string) $row['roomBuilding'],
            'label' => (string) $row['roomBuilding'] . ' / ' . (string) $row['roomName'],
        ],
        'department' => [
            'uid' => (string) $row['departmentUid'],
            'name' => (string) $row['departmentName'],
        ],
        'faculty' => [
            'uid' => (string) $row['facultyId'],
            'firstName' => (string) $row['facultyFirstName'],
            'lastName' => (string) $row['facultyLastName'],
            'email' => (string) $row['facultyEmail'],
            'fullName' => trim((string) $row['facultyFirstName'] . ' ' . (string) $row['facultyLastName']),
        ],
        'checker' => [
            'uid' => (string) $row['checkerId'],
            'firstName' => (string) $row['checkerFirstName'],
            'lastName' => (string) $row['checkerLastName'],
            'fullName' => trim((string) $row['checkerFirstName'] . ' ' . (string) $row['checkerLastName']),
        ],
    ];
}

/**
 * Faculty: only attendance rows tied to schedules owned by this faculty member.
 *
 * @return list<array<string,mixed>>
 */
function fetchFacultyAttendance(string $facultyId): array
{
    // Only confirmed schedules are visible on the Faculty attendance view.
    $sql = attendanceSelectSql() . '
         WHERE s.facultyId = :facultyId
           AND LOWER(s.status) = \'confirmed\'
         ORDER BY ar.timestamp DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute([':facultyId' => $facultyId]);

    return array_map('mapAttendanceRow', $stmt->fetchAll());
}

/**
 * Cross-faculty attendance for HR / Dean, with optional department + date filters.
 *
 * @return list<array<string,mixed>>
 */
function fetchAttendanceReview(?string $departmentId, ?string $dateFrom, ?string $dateTo): array
{
    $sql = attendanceSelectSql() . ' WHERE 1 = 1';
    $params = [];

    if ($departmentId !== null && $departmentId !== '') {
        $sql .= ' AND s.departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
    }

    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND DATE(ar.timestamp) >= :dateFrom';
        $params[':dateFrom'] = $dateFrom;
    }

    if ($dateTo !== null && $dateTo !== '') {
        $sql .= ' AND DATE(ar.timestamp) <= :dateTo';
        $params[':dateTo'] = $dateTo;
    }

    $sql .= ' ORDER BY ar.timestamp DESC, f.lastName ASC, f.firstName ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return array_map('mapAttendanceRow', $stmt->fetchAll());
}

/**
 * Top faculty by absence-related scan counts for a semester.
 * Counts Absent + NoSchedule as absence events (WrongRoom excluded).
 *
 * @return list<array<string,mixed>>
 */
function fetchTopAbsentFaculty(int $academicYear, string $semester, ?string $departmentId = null, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));

    $sql = 'SELECT
                f.uid AS facultyId,
                f.firstName,
                f.lastName,
                f.email,
                d.uid AS departmentUid,
                d.name AS departmentName,
                SUM(CASE WHEN ar.status = \'Absent\' THEN 1 ELSE 0 END) AS absentCount,
                SUM(CASE WHEN ar.status = \'NoSchedule\' THEN 1 ELSE 0 END) AS noScheduleCount,
                SUM(CASE WHEN ar.status IN (\'Absent\', \'NoSchedule\') THEN 1 ELSE 0 END) AS totalAbsent
            FROM attendanceRecord ar
            INNER JOIN schedule s ON s.uid = ar.scheduleId
            INNER JOIN `user` f ON f.uid = s.facultyId
            INNER JOIN department d ON d.uid = s.departmentId
            WHERE s.academicYear = :academicYear
              AND s.semester = :semester
              AND ar.status IN (\'Absent\', \'NoSchedule\')';

    $params = [
        ':academicYear' => $academicYear,
        ':semester' => $semester,
    ];

    if ($departmentId !== null && $departmentId !== '') {
        $sql .= ' AND s.departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
    }

    $sql .= ' GROUP BY f.uid, f.firstName, f.lastName, f.email, d.uid, d.name
              HAVING totalAbsent > 0
              ORDER BY totalAbsent DESC, f.lastName ASC, f.firstName ASC
              LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $rows = [];
    $rank = 1;
    foreach ($stmt->fetchAll() as $row) {
        $rows[] = [
            'rank' => $rank++,
            'faculty' => [
                'uid' => (string) $row['facultyId'],
                'firstName' => (string) $row['firstName'],
                'lastName' => (string) $row['lastName'],
                'email' => (string) $row['email'],
                'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
            ],
            'department' => [
                'uid' => (string) $row['departmentUid'],
                'name' => (string) $row['departmentName'],
            ],
            'absentCount' => (int) $row['absentCount'],
            'noScheduleCount' => (int) $row['noScheduleCount'],
            'totalAbsent' => (int) $row['totalAbsent'],
        ];
    }

    return $rows;
}

/**
 * Log that the viewer inspected another user's attendance (accountability).
 * One audit row per distinct faculty whose records appear in the result set.
 * If the result is empty, still log the view attempt with filters described.
 *
 * @param list<array<string,mixed>> $records
 */
function auditAttendanceViews(
    string $viewerId,
    string $viewerRole,
    string $purpose,
    array $records,
    ?string $departmentId,
    ?string $dateFrom,
    ?string $dateTo
): void {
    $filterParts = [];
    if ($departmentId) {
        $filterParts[] = 'department=' . $departmentId;
    }
    if ($dateFrom) {
        $filterParts[] = 'from=' . $dateFrom;
    }
    if ($dateTo) {
        $filterParts[] = 'to=' . $dateTo;
    }
    $filterLabel = $filterParts !== [] ? implode(', ', $filterParts) : 'no filters';

    $facultyIds = [];
    foreach ($records as $record) {
        $fid = (string) ($record['faculty']['uid'] ?? '');
        if ($fid !== '' && $fid !== $viewerId) {
            $facultyIds[$fid] = $record['faculty']['fullName'] ?? $fid;
        }
    }

    if ($facultyIds === []) {
        logAudit(
            $viewerId,
            'viewed',
            'attendance',
            sprintf(
                '%s viewed attendance data (%s) with %s; no other-faculty rows returned.',
                $viewerRole,
                $purpose,
                $filterLabel
            )
        );
        return;
    }

    foreach ($facultyIds as $facultyId => $facultyName) {
        logAudit(
            $viewerId,
            'viewed',
            'attendance',
            sprintf(
                '%s viewed attendance for %s (%s) with %s.',
                $viewerRole,
                $facultyName,
                $purpose,
                $filterLabel
            ),
            $facultyId
        );
    }
}
