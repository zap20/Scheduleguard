<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

/**
 * Grace window after class start.
 * Arrive within grace → 0 late minutes.
 * Arrive after grace → late minutes count from class start (includes the grace window).
 * Example: 07:00 start, scan 07:15 → 0; scan 07:35 → 35 late minutes.
 */
function attendanceGraceMinutes(): int
{
    $raw = getenv('ATTENDANCE_GRACE_MINUTES');
    if ($raw === false || $raw === '') {
        $raw = $_ENV['ATTENDANCE_GRACE_MINUTES'] ?? '20';
    }
    $minutes = (int) $raw;
    return max(0, min(180, $minutes));
}

/**
 * Scheduled class length in minutes from startTime/endTime (HH:MM or HH:MM:SS).
 */
function scheduleDurationMinutes(string $startTime, string $endTime): int
{
    $start = substr(trim($startTime), 0, 8);
    $end = substr(trim($endTime), 0, 8);
    if (strlen($start) === 5) {
        $start .= ':00';
    }
    if (strlen($end) === 5) {
        $end .= ':00';
    }

    $startTs = strtotime('1970-01-01 ' . $start);
    $endTs = strtotime('1970-01-01 ' . $end);
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        return 0;
    }

    return (int) (($endTs - $startTs) / 60);
}

/**
 * Maximum countable minutes for a class slot (full scheduled duration).
 */
function scheduleBillableMinutes(string $startTime, string $endTime, ?int $graceMinutes = null): int
{
    unset($graceMinutes); // Grace only zeros late when arrival is inside the window.
    return scheduleDurationMinutes($startTime, $endTime);
}

/**
 * Count late / absent minutes for one attendance row using schedule times + grace.
 *
 * Rules:
 * - Present: 0 countable minutes
 * - WrongRoom / NoSchedule: checker warning notices only (0 countable minutes)
 * - Late within grace (start → start+grace): 0 late minutes
 * - Late after grace: minutes from class start to scan (e.g. 07:35 → 35), capped at class end
 * - Absent: full class duration (start → end)
 *
 * @return array{lateMinutes:int,absentMinutes:int,graceMinutes:int,slotMinutes:int,billableMinutes:int}
 */
function attendanceCountedMinutes(
    string $status,
    string $startTime,
    string $endTime,
    string $scanTimestamp,
    ?int $graceMinutes = null
): array {
    $grace = $graceMinutes ?? attendanceGraceMinutes();
    $slotMinutes = scheduleDurationMinutes($startTime, $endTime);
    $result = [
        'lateMinutes' => 0,
        'absentMinutes' => 0,
        'graceMinutes' => $grace,
        'slotMinutes' => $slotMinutes,
        'billableMinutes' => $slotMinutes,
    ];

    if ($slotMinutes <= 0) {
        return $result;
    }

    // Checker warning notices — not attendance outcomes.
    if ($status === 'WrongRoom' || $status === 'NoSchedule') {
        return $result;
    }

    if ($status === 'Absent') {
        $result['absentMinutes'] = $slotMinutes;
        return $result;
    }

    if ($status !== 'Late') {
        return $result;
    }

    $start = substr(trim($startTime), 0, 8);
    $end = substr(trim($endTime), 0, 8);
    if (strlen($start) === 5) {
        $start .= ':00';
    }
    if (strlen($end) === 5) {
        $end .= ':00';
    }

    $scanTime = date('H:i:s', strtotime($scanTimestamp) ?: time());
    $startTs = strtotime('1970-01-01 ' . $start);
    $endTs = strtotime('1970-01-01 ' . $end);
    $scanTs = strtotime('1970-01-01 ' . $scanTime);
    if ($startTs === false || $endTs === false || $scanTs === false) {
        return $result;
    }

    $graceEndTs = $startTs + ($grace * 60);
    if ($scanTs <= $graceEndTs) {
        // Inside grace — forgiven (0 late minutes).
        return $result;
    }

    // Past grace: count from class start (includes the 20 grace minutes).
    $lateUntil = min($scanTs, $endTs);
    $result['lateMinutes'] = max(0, (int) (($lateUntil - $startTs) / 60));
    return $result;
}

/**
 * Format minutes as decimal hours (e.g. 90 → 1.5).
 */
function minutesToHours(int $minutes): float
{
    return round($minutes / 60, 2);
}

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
    $status = (string) $row['attendanceStatus'];
    $startTime = (string) $row['startTime'];
    $endTime = (string) $row['endTime'];
    $timestamp = (string) $row['scanTimestamp'];
    $counted = attendanceCountedMinutes($status, $startTime, $endTime, $timestamp);

    return [
        'uid' => (string) $row['attendanceUid'],
        'status' => $status,
        'isOffline' => (bool) $row['isOffline'],
        'timestamp' => $timestamp,
        'syncedAt' => $row['syncedAt'] !== null ? (string) $row['syncedAt'] : null,
        'counted' => [
            'graceMinutes' => $counted['graceMinutes'],
            'slotMinutes' => $counted['slotMinutes'],
            'billableMinutes' => $counted['billableMinutes'],
            'lateMinutes' => $counted['lateMinutes'],
            'absentMinutes' => $counted['absentMinutes'],
            'lateHours' => minutesToHours($counted['lateMinutes']),
            'absentHours' => minutesToHours($counted['absentMinutes']),
        ],
        'schedule' => [
            'uid' => (string) $row['scheduleUid'],
            'subjectCode' => (string) ($row['subjectCode'] ?? ''),
            'subjectName' => (string) $row['subjectName'],
            'day' => (string) $row['scheduleDay'],
            'startTime' => substr($startTime, 0, 5),
            'endTime' => substr($endTime, 0, 5),
            'status' => (string) $row['scheduleStatus'],
            'expectedLabel' => sprintf(
                '%s %s–%s',
                (string) $row['scheduleDay'],
                substr($startTime, 0, 5),
                substr($endTime, 0, 5)
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
 * Top faculty by counted absent/late hours for a semester.
 *
 * Hours use each schedule's startTime–endTime. Late within the grace window
 * (default 20 min) counts as 0; late after grace counts from class start
 * (scan 07:35 → 35 minutes). Absent uses the full class duration.
 * WrongRoom / NoSchedule are checker warnings and are excluded from hour totals.
 *
 * @return list<array<string,mixed>>
 */
function fetchTopAbsentFaculty(
    ?int $academicYear = null,
    ?string $semester = null,
    ?string $departmentId = null,
    int $limit = 10,
    ?string $dateFrom = null,
    ?string $dateTo = null
): array
{
    $limit = max(1, min(50, $limit));
    $grace = attendanceGraceMinutes();

    $sql = 'SELECT
                ar.status,
                ar.timestamp,
                s.startTime,
                s.endTime,
                f.uid AS facultyId,
                f.firstName,
                f.lastName,
                f.email,
                d.uid AS departmentUid,
                d.name AS departmentName
            FROM attendanceRecord ar
            INNER JOIN schedule s ON s.uid = ar.scheduleId
            INNER JOIN `user` f ON f.uid = s.facultyId
            INNER JOIN department d ON d.uid = s.departmentId
            WHERE ar.status IN (\'Absent\', \'Late\')';

    $params = [];

    if ($academicYear !== null) {
        $sql .= ' AND s.academicYear = :academicYear';
        $params[':academicYear'] = $academicYear;
    }
    if ($semester !== null && $semester !== '') {
        $sql .= ' AND s.semester = :semester';
        $params[':semester'] = $semester;
    }
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

    $sql .= ' ORDER BY f.lastName ASC, f.firstName ASC, ar.timestamp ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    /** @var array<string,array<string,mixed>> $byFaculty */
    $byFaculty = [];
    foreach ($stmt->fetchAll() as $row) {
        $fid = (string) $row['facultyId'];
        if (!isset($byFaculty[$fid])) {
            $byFaculty[$fid] = [
                'facultyId' => $fid,
                'firstName' => (string) $row['firstName'],
                'lastName' => (string) $row['lastName'],
                'email' => (string) $row['email'],
                'departmentUid' => (string) $row['departmentUid'],
                'departmentName' => (string) $row['departmentName'],
                'absentCount' => 0,
                'noScheduleCount' => 0,
                'lateCount' => 0,
                'absentMinutes' => 0,
                'lateMinutes' => 0,
            ];
        }

        $status = (string) $row['status'];
        $counted = attendanceCountedMinutes(
            $status,
            (string) $row['startTime'],
            (string) $row['endTime'],
            (string) $row['timestamp'],
            $grace
        );

        if ($status === 'Absent') {
            $byFaculty[$fid]['absentCount']++;
            $byFaculty[$fid]['absentMinutes'] += $counted['absentMinutes'];
        } elseif ($status === 'Late') {
            $byFaculty[$fid]['lateCount']++;
            $byFaculty[$fid]['lateMinutes'] += $counted['lateMinutes'];
        }
    }

    $ranked = array_values($byFaculty);
    $ranked = array_values(array_filter(
        $ranked,
        static fn (array $row): bool => ((int) $row['absentMinutes']) > 0
    ));
    usort($ranked, static function (array $a, array $b): int {
        if ($a['absentMinutes'] !== $b['absentMinutes']) {
            return $b['absentMinutes'] <=> $a['absentMinutes'];
        }
        if ($a['lateMinutes'] !== $b['lateMinutes']) {
            return $b['lateMinutes'] <=> $a['lateMinutes'];
        }
        return [$a['lastName'], $a['firstName']] <=> [$b['lastName'], $b['firstName']];
    });
    $ranked = array_slice($ranked, 0, $limit);

    $rows = [];
    $rank = 1;
    foreach ($ranked as $row) {
        $absentHours = minutesToHours((int) $row['absentMinutes']);
        $lateHours = minutesToHours((int) $row['lateMinutes']);
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
            'lateCount' => (int) $row['lateCount'],
            'totalAbsent' => (int) $row['absentCount'] + (int) $row['noScheduleCount'],
            'absentMinutes' => (int) $row['absentMinutes'],
            'lateMinutes' => (int) $row['lateMinutes'],
            'absentHours' => $absentHours,
            'lateHours' => $lateHours,
            'totalHours' => minutesToHours((int) $row['absentMinutes'] + (int) $row['lateMinutes']),
            'graceMinutes' => $grace,
        ];
    }

    return $rows;
}

/**
 * Top faculty by Present hours for a semester (Present status only).
 *
 * @return list<array<string,mixed>>
 */
function fetchTopPresentFaculty(
    ?int $academicYear = null,
    ?string $semester = null,
    ?string $departmentId = null,
    int $limit = 10,
    ?string $dateFrom = null,
    ?string $dateTo = null
): array
{
    $limit = max(1, min(50, $limit));

    $sql = 'SELECT
                ar.status,
                ar.timestamp,
                s.startTime,
                s.endTime,
                f.uid AS facultyId,
                f.firstName,
                f.lastName,
                f.email,
                d.uid AS departmentUid,
                d.name AS departmentName
            FROM attendanceRecord ar
            INNER JOIN schedule s ON s.uid = ar.scheduleId
            INNER JOIN `user` f ON f.uid = s.facultyId
            INNER JOIN department d ON d.uid = s.departmentId
            WHERE ar.status = \'Present\'';

    $params = [];

    if ($academicYear !== null) {
        $sql .= ' AND s.academicYear = :academicYear';
        $params[':academicYear'] = $academicYear;
    }
    if ($semester !== null && $semester !== '') {
        $sql .= ' AND s.semester = :semester';
        $params[':semester'] = $semester;
    }
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

    $sql .= ' ORDER BY f.lastName ASC, f.firstName ASC, ar.timestamp ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    /** @var array<string,array<string,mixed>> $byFaculty */
    $byFaculty = [];
    foreach ($stmt->fetchAll() as $row) {
        $fid = (string) $row['facultyId'];
        if (!isset($byFaculty[$fid])) {
            $byFaculty[$fid] = [
                'facultyId' => $fid,
                'firstName' => (string) $row['firstName'],
                'lastName' => (string) $row['lastName'],
                'email' => (string) $row['email'],
                'departmentUid' => (string) $row['departmentUid'],
                'departmentName' => (string) $row['departmentName'],
                'presentCount' => 0,
                'presentMinutes' => 0,
            ];
        }

        $slot = scheduleDurationMinutes((string) $row['startTime'], (string) $row['endTime']);
        $byFaculty[$fid]['presentCount']++;
        $byFaculty[$fid]['presentMinutes'] += $slot;
    }

    $ranked = array_values($byFaculty);
    usort($ranked, static function (array $a, array $b): int {
        if ($a['presentMinutes'] !== $b['presentMinutes']) {
            return $b['presentMinutes'] <=> $a['presentMinutes'];
        }
        if ($a['presentCount'] !== $b['presentCount']) {
            return $b['presentCount'] <=> $a['presentCount'];
        }
        return [$a['lastName'], $a['firstName']] <=> [$b['lastName'], $b['firstName']];
    });
    $ranked = array_slice($ranked, 0, $limit);

    $rows = [];
    $rank = 1;
    foreach ($ranked as $row) {
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
            'presentCount' => (int) $row['presentCount'],
            'presentMinutes' => (int) $row['presentMinutes'],
            'presentHours' => minutesToHours((int) $row['presentMinutes']),
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
