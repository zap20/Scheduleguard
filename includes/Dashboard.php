<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/Blocking.php';
require_once __DIR__ . '/Enrollment.php';
require_once __DIR__ . '/ClassBlock.php';
require_once __DIR__ . '/Schedule.php';
require_once __DIR__ . '/Attendance.php';
require_once __DIR__ . '/Term.php';

/**
 * @return array<string,int>
 */
function countAttendanceByStatus(
    ?string $facultyId = null,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?int $academicYear = null,
    ?string $semester = null
): array {
    $sql = 'SELECT ar.status, COUNT(*) AS cnt
            FROM attendanceRecord ar
            INNER JOIN schedule s ON s.uid = ar.scheduleId
            WHERE LOWER(s.status) = \'confirmed\'';
    $params = [];

    if ($facultyId !== null && $facultyId !== '') {
        $sql .= ' AND s.facultyId = :facultyId';
        $params[':facultyId'] = $facultyId;
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= ' AND DATE(ar.timestamp) >= :dateFrom';
        $params[':dateFrom'] = $dateFrom;
    }
    if ($dateTo !== null && $dateTo !== '') {
        $sql .= ' AND DATE(ar.timestamp) <= :dateTo';
        $params[':dateTo'] = $dateTo;
    }
    if ($academicYear !== null) {
        $sql .= ' AND s.academicYear = :academicYear';
        $params[':academicYear'] = $academicYear;
    }
    if ($semester !== null && $semester !== '') {
        $sql .= ' AND s.semester = :semester';
        $params[':semester'] = $semester;
    }

    $sql .= ' GROUP BY ar.status';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $counts = [
        'Present' => 0,
        'Late' => 0,
        'Absent' => 0,
    ];

    foreach ($stmt->fetchAll() as $row) {
        $status = (string) $row['status'];
        // WrongRoom / NoSchedule are checker warning notices, not attendance statuses.
        if (isset($counts[$status])) {
            $counts[$status] = (int) $row['cnt'];
        }
    }

    return $counts;
}

/**
 * @return array{labels: list<string>, values: list<int>, total:int}
 */
function attendanceStatusChartPayload(
    ?string $facultyId = null,
    ?string $dateFrom = null,
    ?string $dateTo = null,
    ?int $academicYear = null,
    ?string $semester = null
): array {
    $counts = countAttendanceByStatus($facultyId, $dateFrom, $dateTo, $academicYear, $semester);
    return [
        'labels' => array_keys($counts),
        'values' => array_values($counts),
        'total' => array_sum($counts),
    ];
}

/**
 * @return array<string,mixed>
 */
function buildFacultyDashboard(string $facultyId): array
{
    $schedules = fetchFacultyOwnSchedules($facultyId);
    $attendance = fetchFacultyAttendance($facultyId);
    $chart = attendanceStatusChartPayload($facultyId);

    $recent = array_slice($attendance, 0, 5);
    $preview = array_slice($schedules, 0, 5);

    return [
        'role' => 'Faculty',
        'attendance' => [
            'total' => count($attendance),
            'byStatus' => $chart,
            'recent' => array_map(static function (array $row): array {
                return [
                    'subjectCode' => $row['schedule']['subjectCode'],
                    'subjectName' => $row['schedule']['subjectName'],
                    'status' => $row['status'],
                    'timestamp' => $row['timestamp'],
                ];
            }, $recent),
            'href' => 'attendance-faculty.html',
        ],
        'schedule' => [
            'total' => count($schedules),
            'preview' => array_map(static function (array $row): array {
                return [
                    'subjectCode' => $row['subjectCode'],
                    'subjectName' => $row['subjectName'],
                    'blockName' => $row['blockName'] ?? '',
                    'day' => $row['day'],
                    'startTime' => $row['startTime'],
                    'endTime' => $row['endTime'],
                    'roomLabel' => $row['roomLabel'],
                ];
            }, $preview),
            'href' => 'faculty-schedule.html',
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function buildStudentDashboard(string $studentId): array
{
    $cleared = isStudentCleared($studentId);
    $active = getActiveBlock($studentId);
    $schedules = fetchDistributedStudentSchedule($studentId);

    return [
        'role' => 'Student',
        'blocking' => [
            'cleared' => $cleared,
            'status' => $cleared ? 'Cleared' : 'Active',
            'reason' => $active['reason'] ?? null,
            'href' => 'student-blocks.html',
        ],
        'schedule' => [
            'total' => count($schedules),
            'preview' => array_map(static function (array $row): array {
                return [
                    'subjectCode' => $row['subjectCode'],
                    'subjectName' => $row['subjectName'],
                    'facultyName' => $row['facultyName'],
                    'instructor' => $row['instructor'] ?? $row['facultyName'],
                    'blockName' => $row['blockName'] ?? '',
                    'day' => $row['day'],
                    'startTime' => $row['startTime'],
                    'endTime' => $row['endTime'],
                    'roomLabel' => $row['roomLabel'],
                ];
            }, array_slice($schedules, 0, 5)),
            'href' => 'student-schedule.html',
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function buildDeanDashboard(string $period = 'monthly'): array
{
    $conflicts = fetchSchedules('conflict', null);
    $window = resolveAttendancePeriod($period);
    $chart = attendanceStatusChartPayload(
        null,
        $window['dateFrom'],
        $window['dateTo'],
        $window['academicYear'],
        $window['semester']
    );
    $term = currentTermWindow();

    $statusCounts = db()->query(
        "SELECT LOWER(status) AS status, COUNT(*) AS cnt
         FROM schedule
         GROUP BY LOWER(status)"
    )->fetchAll();

    $scheduleBreakdown = [
        'draft' => 0,
        'conflict' => 0,
        'confirmed' => 0,
    ];
    foreach ($statusCounts as $row) {
        $key = (string) $row['status'];
        if (isset($scheduleBreakdown[$key])) {
            $scheduleBreakdown[$key] = (int) $row['cnt'];
        }
    }

    return [
        'role' => 'Dean',
        'shortcuts' => [
            ['href' => 'curriculum.html', 'label' => 'Subjects', 'tone' => 'primary'],
            ['href' => 'rooms.html', 'label' => 'LAB / LECTURE rooms', 'tone' => 'primary'],
            ['href' => 'student-schedule-dean.html', 'label' => 'Class blocks', 'tone' => 'primary'],
            ['href' => 'faculty-schedule-dean.html', 'label' => 'Faculty schedule', 'tone' => 'primary'],
            ['href' => 'tbf-schedule-dean.html', 'label' => 'TBF (unassigned)', 'tone' => 'primary'],
            ['href' => 'users.html', 'label' => 'User management', 'tone' => 'secondary'],
            ['href' => 'audit.html', 'label' => 'Audit trail', 'tone' => 'secondary'],
        ],
        'conflicts' => [
            'total' => count($conflicts),
            'items' => array_map(static function (array $row): array {
                $blockName = trim((string) ($row['blockName'] ?? ''));
                return [
                    'uid' => $row['uid'],
                    'subjectCode' => $row['subjectCode'],
                    'subjectName' => $row['subjectName'],
                    'facultyName' => $row['facultyName'],
                    'blockName' => $blockName,
                    'classBlockId' => (string) ($row['classBlockId'] ?? ''),
                    'day' => $row['day'],
                    'startTime' => $row['startTime'],
                    'endTime' => $row['endTime'],
                    'roomLabel' => $row['roomLabel'],
                ];
            }, array_slice($conflicts, 0, 8)),
            'href' => 'student-schedule-dean.html',
        ],
        'attendanceChart' => $chart,
        'scheduleBreakdown' => $scheduleBreakdown,
        'topAbsent' => fetchTopAbsentFaculty(
            $window['academicYear'],
            $window['semester'],
            null,
            10,
            $window['dateFrom'],
            $window['dateTo']
        ),
        'topPresent' => fetchTopPresentFaculty(
            $window['academicYear'],
            $window['semester'],
            null,
            10,
            $window['dateFrom'],
            $window['dateTo']
        ),
        'graceMinutes' => attendanceGraceMinutes(),
        'period' => $window,
        'term' => [
            'label' => formatTermLabel((int) $term['academicYear'], (string) $term['semester']),
            'academicYear' => (int) $term['academicYear'],
            'semester' => (string) $term['semester'],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function buildHrDashboard(): array
{
    return [
        'role' => 'HR',
        'attendanceChart' => attendanceStatusChartPayload(null),
        'shortcuts' => [
            ['href' => 'attendance-hr.html', 'label' => 'Open attendance review', 'tone' => 'primary'],
            ['href' => 'blocking.html', 'label' => 'Blocking list (read-only)', 'tone' => 'secondary'],
        ],
    ];
}

/**
 * @return array<string,mixed>
 */
function buildProgramHeadDashboard(string $userId): array
{
    $departmentId = requireProgramHeadDepartment($userId);
    $pending = fetchPendingEnrollmentStudents($departmentId);
    $assigned = fetchDepartmentEnrollments($departmentId, ENROLLMENT_STATUS_ASSIGNED);
    $distributed = fetchDepartmentEnrollments($departmentId, ENROLLMENT_STATUS_DISTRIBUTED);

    $deptNameStmt = db()->prepare('SELECT name FROM department WHERE uid = :uid LIMIT 1');
    $deptNameStmt->execute([':uid' => $departmentId]);
    $departmentName = (string) ($deptNameStmt->fetchColumn() ?: 'Your department');

    // Blocking breakdown by department (PH scoped to own dept; still department-keyed for the chart).
    $blockStmt = db()->prepare(
        'SELECT d.name AS departmentName, LOWER(b.status) AS status, COUNT(*) AS cnt
         FROM block b
         INNER JOIN department d ON d.uid = b.departmentId
         WHERE b.departmentId = :departmentId
         GROUP BY d.name, LOWER(b.status)
         ORDER BY d.name ASC'
    );
    $blockStmt->execute([':departmentId' => $departmentId]);

    $byDepartment = [];
    foreach ($blockStmt->fetchAll() as $row) {
        $name = (string) $row['departmentName'];
        if (!isset($byDepartment[$name])) {
            $byDepartment[$name] = ['Active' => 0, 'Cleared' => 0];
        }
        $status = strtolower((string) $row['status']) === 'active' ? 'Active' : 'Cleared';
        $byDepartment[$name][$status] = (int) $row['cnt'];
    }

    $labels = array_keys($byDepartment);
    if ($labels === []) {
        $labels = [$departmentName];
        $byDepartment[$departmentName] = ['Active' => 0, 'Cleared' => 0];
    }

    return [
        'role' => 'ProgramHead',
        'department' => [
            'uid' => $departmentId,
            'name' => $departmentName,
        ],
        'pendingEnrollments' => [
            'count' => count($pending),
            'href' => 'enrollment.html',
        ],
        'classBlocks' => [
            'count' => count(fetchClassBlocks(
                $departmentId,
                null,
                (int) currentTermWindow()['academicYear'],
                (string) currentTermWindow()['semester']
            )),
            'href' => 'enrollment.html',
        ],
        'blockingChart' => [
            'labels' => $labels,
            'active' => array_map(static fn(string $label): int => $byDepartment[$label]['Active'], $labels),
            'cleared' => array_map(static fn(string $label): int => $byDepartment[$label]['Cleared'], $labels),
            'href' => 'blocking.html',
        ],
        'distribution' => [
            'assigned' => count($assigned),
            'distributed' => count($distributed),
            'href' => 'enrollment.html',
        ],
    ];
}
