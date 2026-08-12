<?php

declare(strict_types=1);

/**
 * Seed ~2 months of attendance for every Faculty.
 *
 * - Ana Santos (user-cict-fac-01): exactly 8 hours Absent
 *   (4 × 2h class = 8h; full duration counted)
 * - Ben Garcia (user-cict-fac-02): complete attendance (all Present)
 * - Everyone else: mostly Present with occasional Late
 *
 * Usage: php database/seed_attendance_two_months.php
 */

require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/config/env.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/Attendance.php';

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$academicYear = (int) (getenv('CURRENT_ACADEMIC_YEAR') ?: ($_ENV['CURRENT_ACADEMIC_YEAR'] ?? 2025));
$semester = (string) (getenv('CURRENT_SEMESTER') ?: ($_ENV['CURRENT_SEMESTER'] ?? '1'));
$checkerId = 'user-checker';
$absentFacultyId = 'user-cict-fac-01'; // Ana Santos — 8h absent
$perfectFacultyId = 'user-cict-fac-02'; // Ben Garcia — complete
$graceMinutes = attendanceGraceMinutes();
$graceHours = $graceMinutes / 60.0;

$endDate = new DateTimeImmutable('today');
$startDate = $endDate->modify('-60 days');

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$timeSlots = [
    ['07:00:00', '09:00:00'], // 2.0h — Ana: 4 absences = 8h
    ['09:00:00', '10:30:00'], // 1.5h
    ['10:30:00', '12:00:00'],
    ['13:00:00', '14:30:00'],
    ['14:30:00', '16:00:00'],
    ['16:00:00', '17:30:00'],
];

echo "Term {$academicYear}/{$semester}\n";
echo "Window {$startDate->format('Y-m-d')} → {$endDate->format('Y-m-d')}\n";
echo "Grace {$graceMinutes} minutes\n";

$pdo->beginTransaction();

try {
    // Ensure CICT seed subject exists
    $subjId = 'subj-cict-att';
    $exists = $pdo->prepare('SELECT uid FROM subject WHERE uid = ?');
    $exists->execute([$subjId]);
    if (!$exists->fetchColumn()) {
        $pdo->prepare(
            'INSERT INTO subject (
                uid, departmentId, code, title, yearLevel, semester, curriculumYear,
                units, preferredRoomType, status, createdAt
             ) VALUES (
                ?, \'dept-cict\', \'CICT-ATT\', \'CICT Attendance Seed Subject\',
                \'1st Year\', \'1st Semester\', ?, 3.0, \'LECTURE\', \'Active\', NOW()
             )'
        )->execute([$subjId, $academicYear + 1]);
        echo "Created subject {$subjId}\n";
    }

    $rooms = $pdo->query('SELECT uid FROM room ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    if ($rooms === []) {
        throw new RuntimeException('No rooms available.');
    }

    $faculty = $pdo->query(
        "SELECT uid, firstName, lastName, departmentId
         FROM `user`
         WHERE role = 'Faculty' AND status = 'Active'
         ORDER BY lastName, firstName"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Existing occupied room/day/time keys
    $occupied = [];
    $occStmt = $pdo->query(
        "SELECT roomId, day, startTime, endTime
         FROM schedule
         WHERE academicYear = {$academicYear} AND semester = " . $pdo->quote($semester)
    );
    foreach ($occStmt as $row) {
        $occupied[occupancyKey($row['roomId'], $row['day'], $row['startTime'], $row['endTime'])] = true;
    }

    $createdByCache = [];
    $scheduleByFaculty = [];

    foreach ($faculty as $fac) {
        $fid = $fac['uid'];
        $deptId = $fac['departmentId'] ?: 'dept-comp';

        $schedStmt = $pdo->prepare(
            'SELECT uid, day, startTime, endTime
             FROM schedule
             WHERE facultyId = ?
               AND academicYear = ?
               AND semester = ?
               AND status IN (\'confirmed\', \'Confirmed\', \'published\', \'active\')
             ORDER BY FIELD(day, \'Monday\',\'Tuesday\',\'Wednesday\',\'Thursday\',\'Friday\',\'Saturday\'), startTime'
        );
        $schedStmt->execute([$fid, $academicYear, $semester]);
        $existing = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fall back: any schedule for this faculty in term
        if ($existing === []) {
            $schedStmt = $pdo->prepare(
                'SELECT uid, day, startTime, endTime
                 FROM schedule
                 WHERE facultyId = ? AND academicYear = ? AND semester = ?
                 ORDER BY createdAt ASC'
            );
            $schedStmt->execute([$fid, $academicYear, $semester]);
            $existing = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($existing !== []) {
            $scheduleByFaculty[$fid] = $existing;
            continue;
        }

        // Create one weekly schedule for this faculty
        $createdBy = $createdByCache[$deptId] ?? null;
        if ($createdBy === null) {
            $ph = $pdo->prepare(
                "SELECT uid FROM `user` WHERE role = 'ProgramHead' AND departmentId = ? LIMIT 1"
            );
            $ph->execute([$deptId]);
            $createdBy = $ph->fetchColumn() ?: 'user-ph';
            $createdByCache[$deptId] = $createdBy;
        }

        $slot = findFreeSlot($rooms, $days, $timeSlots, $occupied, $fid === $absentFacultyId);
        if ($slot === null) {
            throw new RuntimeException("No free room/time slot for faculty {$fid}");
        }

        [$roomId, $day, $start, $end] = $slot;
        $occupied[occupancyKey($roomId, $day, $start, $end)] = true;

        $schedUid = 'sched-att-' . preg_replace('/^user-/', '', $fid);
        if (strlen($schedUid) > 36) {
            $schedUid = substr(generateUid(), 0, 36);
        }

        // Prefer fixed Mon 07:00–09:00 for absent faculty (2h × 4 = 8h)
        if ($fid === $absentFacultyId) {
            $day = 'Monday';
            $start = '07:00:00';
            $end = '09:00:00';
            $roomId = 'room-206';
            // If taken, still use Ana's preferred times on first free room
            $key = occupancyKey($roomId, $day, $start, $end);
            if (isset($occupied[$key])) {
                foreach ($rooms as $r) {
                    $k = occupancyKey($r, $day, $start, $end);
                    if (!isset($occupied[$k])) {
                        $roomId = $r;
                        $key = $k;
                        break;
                    }
                }
            }
            $occupied[$key] = true;
        }

        if ($fid === $perfectFacultyId) {
            $day = 'Tuesday';
            $start = '07:00:00';
            $end = '09:00:00';
            $roomId = 'room-207';
            $key = occupancyKey($roomId, $day, $start, $end);
            if (isset($occupied[$key])) {
                foreach ($rooms as $r) {
                    $k = occupancyKey($r, $day, $start, $end);
                    if (!isset($occupied[$k])) {
                        $roomId = $r;
                        $key = $k;
                        break;
                    }
                }
            }
            $occupied[$key] = true;
        }

        $subjectForSched = $deptId === 'dept-cict' ? $subjId : 'subj-db101';

        $pdo->prepare(
            'INSERT INTO schedule (
                uid, facultyId, roomId, departmentId, createdBy, subjectId,
                day, startTime, endTime, academicYear, semester, status, createdAt
             ) VALUES (
                ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?, \'confirmed\', NOW()
             )'
        )->execute([
            $schedUid,
            $fid,
            $roomId,
            $deptId,
            $createdBy,
            $subjectForSched,
            $day,
            $start,
            $end,
            $academicYear,
            $semester,
        ]);

        echo "Created schedule {$schedUid} for {$fac['firstName']} {$fac['lastName']} ({$day} {$start}-{$end})\n";
        $scheduleByFaculty[$fid] = [
            ['uid' => $schedUid, 'day' => $day, 'startTime' => $start, 'endTime' => $end],
        ];
    }

    // Reload schedules for faculties we may have just created / already had
    foreach ($faculty as $fac) {
        $fid = $fac['uid'];
        $schedStmt = $pdo->prepare(
            'SELECT uid, day, startTime, endTime FROM schedule WHERE facultyId = ? AND academicYear = ? AND semester = ?'
        );
        $schedStmt->execute([$fid, $academicYear, $semester]);
        $scheduleByFaculty[$fid] = $schedStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Ensure Ana's primary slot is 07:00–09:00 so 4 absences × 2h = 8h.
    if (!empty($scheduleByFaculty[$absentFacultyId])) {
        $anaSchedId = (string) $scheduleByFaculty[$absentFacultyId][0]['uid'];
        $pdo->prepare(
            'UPDATE schedule SET day = \'Monday\', startTime = \'07:00:00\', endTime = \'09:00:00\' WHERE uid = ?'
        )->execute([$anaSchedId]);
        $scheduleByFaculty[$absentFacultyId][0]['day'] = 'Monday';
        $scheduleByFaculty[$absentFacultyId][0]['startTime'] = '07:00:00';
        $scheduleByFaculty[$absentFacultyId][0]['endTime'] = '09:00:00';
        // Keep only the primary Ana schedule for attendance seeding targets.
        $scheduleByFaculty[$absentFacultyId] = [$scheduleByFaculty[$absentFacultyId][0]];
        echo "Aligned Ana schedule {$anaSchedId} to Monday 07:00–09:00\n";
    }

    // Wipe prior attendance in window for these schedules (idempotent re-run)
    $allSchedIds = [];
    foreach ($scheduleByFaculty as $list) {
        foreach ($list as $s) {
            $allSchedIds[$s['uid']] = true;
        }
    }
    $allSchedIds = array_keys($allSchedIds);

    if ($allSchedIds !== []) {
        $placeholders = implode(',', array_fill(0, count($allSchedIds), '?'));
        $params = array_merge(
            $allSchedIds,
            [$startDate->format('Y-m-d 00:00:00'), $endDate->format('Y-m-d 23:59:59')]
        );
        $del = $pdo->prepare(
            "DELETE FROM attendanceRecord
             WHERE scheduleId IN ({$placeholders})
               AND timestamp BETWEEN ? AND ?"
        );
        $del->execute($params);
        echo 'Cleared prior attendance in window: ' . $del->rowCount() . "\n";
    }

    $ins = $pdo->prepare(
        'INSERT INTO attendanceRecord (uid, scheduleId, checkerId, status, isOffline, timestamp, syncedAt)
         VALUES (?, ?, ?, ?, 0, ?, ?)'
    );

    $summary = [];

    foreach ($faculty as $fac) {
        $fid = $fac['uid'];
        $schedules = $scheduleByFaculty[$fid] ?? [];
        if ($schedules === []) {
            echo "WARN: no schedule for {$fid}\n";
            continue;
        }

        $occurrences = [];
        foreach ($schedules as $sched) {
            $dates = occurrenceDates($startDate, $endDate, $sched['day']);
            $slotHours = durationHours($sched['startTime'], $sched['endTime']);
            $billableHours = $slotHours; // full class duration counts when Absent
            foreach ($dates as $date) {
                $ts = $date->format('Y-m-d') . ' ' . normalizeTime($sched['startTime']);
                $occurrences[] = [
                    'scheduleId' => $sched['uid'],
                    'timestamp' => $ts,
                    'startTime' => $sched['startTime'],
                    'endTime' => $sched['endTime'],
                    'hours' => $slotHours,
                    'billableHours' => $billableHours,
                ];
            }
        }

        usort($occurrences, static fn ($a, $b) => strcmp($a['timestamp'], $b['timestamp']));

        $absentHoursTarget = $fid === $absentFacultyId ? 8.0 : 0.0;
        $absentHoursUsed = 0.0;
        $counts = ['Present' => 0, 'Late' => 0, 'Absent' => 0];
        $absentHours = 0.0;
        $lateHours = 0.0;

        foreach ($occurrences as $i => $occ) {
            $status = 'Present';
            $scanTs = $occ['timestamp'];

            if ($fid === $perfectFacultyId) {
                $status = 'Present';
            } elseif ($fid === $absentFacultyId) {
                $remaining = $absentHoursTarget - $absentHoursUsed;
                if ($remaining >= $occ['billableHours'] - 0.001) {
                    $status = 'Absent';
                    $absentHoursUsed += $occ['billableHours'];
                } else {
                    $status = 'Present';
                }
            } else {
                // Light variety: every 7th meeting Late at 07:35-style (start+35 → 35 counted late mins)
                if ($i % 7 === 3) {
                    $status = 'Late';
                    $scanTs = (new DateTimeImmutable($occ['timestamp']))
                        ->modify('+35 minutes')
                        ->format('Y-m-d H:i:s');
                } else {
                    $status = 'Present';
                }
            }

            $uid = generateUid();
            $ins->execute([$uid, $occ['scheduleId'], $checkerId, $status, $scanTs, $scanTs]);
            $counts[$status] = ($counts[$status] ?? 0) + 1;

            $counted = attendanceCountedMinutes(
                $status,
                (string) $occ['startTime'],
                (string) $occ['endTime'],
                $scanTs,
                $graceMinutes
            );
            $absentHours += $counted['absentMinutes'] / 60;
            $lateHours += $counted['lateMinutes'] / 60;
        }

        $summary[] = [
            'faculty' => trim($fac['firstName'] . ' ' . $fac['lastName']),
            'uid' => $fid,
            'meetings' => count($occurrences),
            'Present' => $counts['Present'],
            'Late' => $counts['Late'],
            'Absent' => $counts['Absent'],
            'absentHours' => round($absentHours, 2),
            'lateHours' => round($lateHours, 2),
        ];
    }

    $pdo->commit();

    echo "\n=== Attendance seed summary ===\n";
    foreach ($summary as $row) {
        $tag = '';
        if ($row['uid'] === $absentFacultyId) {
            $tag = '  << 8h ABSENT TARGET';
        } elseif ($row['uid'] === $perfectFacultyId) {
            $tag = '  << COMPLETE ATTENDANCE';
        }
        echo sprintf(
            "%-22s meetings=%2d Present=%2d Late=%2d Absent=%2d absentHours=%s lateHours=%s%s\n",
            $row['faculty'],
            $row['meetings'],
            $row['Present'],
            $row['Late'],
            $row['Absent'],
            $row['absentHours'],
            $row['lateHours'],
            $tag
        );
    }

    $ana = null;
    $ben = null;
    foreach ($summary as $row) {
        if ($row['uid'] === $absentFacultyId) {
            $ana = $row;
        }
        if ($row['uid'] === $perfectFacultyId) {
            $ben = $row;
        }
    }

    if ($ana === null || abs($ana['absentHours'] - 8.0) > 0.01) {
        throw new RuntimeException('Ana Santos absent hours are not 8 (got ' . ($ana['absentHours'] ?? 'n/a') . ')');
    }
    if ($ben === null || $ben['Absent'] !== 0 || $ben['Late'] !== 0 || $ben['Present'] < 1) {
        throw new RuntimeException('Ben Garcia does not have complete Present attendance');
    }

    echo "\nOK: Ana Santos = {$ana['absentHours']}h absent; Ben Garcia = complete ({$ben['Present']} Present).\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/**
 * @param list<string> $rooms
 * @param list<string> $days
 * @param list<array{0:string,1:string}> $timeSlots
 * @param array<string,bool> $occupied
 * @return array{0:string,1:string,2:string,3:string}|null
 */
function findFreeSlot(array $rooms, array $days, array $timeSlots, array &$occupied, bool $preferTwoHour): ?array
{
    $slots = $preferTwoHour
        ? array_values(array_filter($timeSlots, static fn ($t) => durationHours($t[0], $t[1]) >= 1.99))
        : $timeSlots;
    if ($slots === []) {
        $slots = $timeSlots;
    }

    foreach ($days as $day) {
        foreach ($slots as [$start, $end]) {
            foreach ($rooms as $roomId) {
                $key = occupancyKey($roomId, $day, $start, $end);
                if (!isset($occupied[$key])) {
                    $occupied[$key] = true;
                    return [$roomId, $day, $start, $end];
                }
            }
        }
    }

    return null;
}

function occupancyKey(string $roomId, string $day, string $start, string $end): string
{
    return $roomId . '|' . $day . '|' . normalizeTime($start) . '|' . normalizeTime($end);
}

function normalizeTime(string $time): string
{
    if (preg_match('/^\d{2}:\d{2}:\d{2}/', $time, $m)) {
        return $m[0];
    }
    if (preg_match('/^\d{2}:\d{2}/', $time, $m)) {
        return $m[0] . ':00';
    }
    return $time;
}

function durationHours(string $start, string $end): float
{
    $s = strtotime('1970-01-01 ' . normalizeTime($start));
    $e = strtotime('1970-01-01 ' . normalizeTime($end));
    if ($s === false || $e === false || $e <= $s) {
        return 0.0;
    }
    return ($e - $s) / 3600.0;
}

/**
 * @return list<DateTimeImmutable>
 */
function occurrenceDates(DateTimeImmutable $start, DateTimeImmutable $end, string $dayName): array
{
    $map = [
        'Monday' => 1,
        'Tuesday' => 2,
        'Wednesday' => 3,
        'Thursday' => 4,
        'Friday' => 5,
        'Saturday' => 6,
        'Sunday' => 7,
    ];
    $target = $map[$dayName] ?? null;
    if ($target === null) {
        return [];
    }

    $out = [];
    $cursor = $start;
    // Advance to first matching weekday
    while ((int) $cursor->format('N') !== $target) {
        $cursor = $cursor->modify('+1 day');
        if ($cursor > $end) {
            return [];
        }
    }
    while ($cursor <= $end) {
        $out[] = $cursor;
        $cursor = $cursor->modify('+7 days');
    }
    return $out;
}
