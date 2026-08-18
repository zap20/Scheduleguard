<?php

declare(strict_types=1);

/**
 * Ensure every active Faculty has a Thursday class, then seed attendance for "today"
 * (or a given date).
 *
 * Usage:
 *   php database/seed_attendance_today.php
 *   php database/seed_attendance_today.php 2026-08-13
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
$absentFacultyId = 'user-cict-fac-01';
$perfectFacultyId = 'user-cict-fac-02';
$graceMinutes = attendanceGraceMinutes();

$day = isset($argv[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $argv[1])
    ? new DateTimeImmutable($argv[1])
    : new DateTimeImmutable('today');
$weekday = $day->format('l'); // e.g. Thursday
$dateStr = $day->format('Y-m-d');

echo "Seeding attendance for {$dateStr} ({$weekday})\n";
echo "Term {$academicYear}/{$semester}\n";

$rooms = $pdo->query('SELECT uid FROM room ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
if ($rooms === []) {
    fwrite(STDERR, "ERROR: No rooms available.\n");
    exit(1);
}

$faculty = $pdo->query(
    "SELECT uid, firstName, lastName, departmentId
     FROM userProfile
     WHERE role = 'Faculty' AND status = 'Active'
     ORDER BY lastName, firstName"
)->fetchAll(PDO::FETCH_ASSOC);

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
}

$occupied = [];
$occStmt = $pdo->prepare(
    'SELECT roomId, day, startTime, endTime FROM schedule
     WHERE academicYear = ? AND semester = ?'
);
$occStmt->execute([$academicYear, $semester]);
foreach ($occStmt as $row) {
    $occupied[$row['roomId'] . '|' . $row['day'] . '|' . substr((string) $row['startTime'], 0, 8) . '|' . substr((string) $row['endTime'], 0, 8)] = true;
}

$slots = [
    ['07:00:00', '09:00:00'],
    ['09:00:00', '10:30:00'],
    ['10:30:00', '12:00:00'],
    ['13:00:00', '14:30:00'],
    ['14:30:00', '16:00:00'],
    ['16:00:00', '17:30:00'],
];

$pdo->beginTransaction();
try {
    $scheduleIds = [];

    foreach ($faculty as $i => $fac) {
        $fid = (string) $fac['uid'];
        $deptId = $fac['departmentId'] ?: 'dept-cict';

        $schedStmt = $pdo->prepare(
            'SELECT uid, day, startTime, endTime FROM schedule
             WHERE facultyId = ? AND academicYear = ? AND semester = ?
               AND LOWER(day) = LOWER(?)
             ORDER BY startTime ASC'
        );
        $schedStmt->execute([$fid, $academicYear, $semester, $weekday]);
        $existing = $schedStmt->fetchAll(PDO::FETCH_ASSOC);

        if ($existing === []) {
            $roomId = null;
            $start = '07:00:00';
            $end = '09:00:00';
            foreach ($slots as [$s, $e]) {
                foreach ($rooms as $r) {
                    $key = $r . '|' . $weekday . '|' . $s . '|' . $e;
                    if (!isset($occupied[$key])) {
                        $roomId = $r;
                        $start = $s;
                        $end = $e;
                        $occupied[$key] = true;
                        break 2;
                    }
                }
            }
            if ($roomId === null) {
                throw new RuntimeException('No free room/time for ' . $fid . ' on ' . $weekday);
            }

            $ph = $pdo->prepare(
                "SELECT uid FROM userProfile WHERE role = 'ProgramHead' AND departmentId = ? LIMIT 1"
            );
            $ph->execute([$deptId]);
            $createdBy = $ph->fetchColumn() ?: 'user-cict-ph';

            $schedUid = 'sched-att-' . $weekday . '-' . preg_replace('/^user-/', '', $fid);
            if (strlen($schedUid) > 36) {
                $schedUid = substr(generateUid(), 0, 36);
            }

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
                $deptId === 'dept-cict' ? $subjId : 'subj-db101',
                $weekday,
                $start,
                $end,
                $academicYear,
                $semester,
            ]);
            echo "Created {$weekday} schedule for {$fac['firstName']} {$fac['lastName']}\n";
            $existing = [[
                'uid' => $schedUid,
                'day' => $weekday,
                'startTime' => $start,
                'endTime' => $end,
            ]];
        }

        // One primary meeting for today's attendance seed per faculty.
        $scheduleIds[$fid] = $existing[0];
    }

    // Clear prior attendance for these schedules on this date only.
    $ids = array_map(static fn ($s) => $s['uid'], array_values($scheduleIds));
    if ($ids !== []) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$dateStr . ' 00:00:00', $dateStr . ' 23:59:59']);
        $del = $pdo->prepare(
            "DELETE FROM attendanceRecord
             WHERE scheduleId IN ({$ph})
               AND timestamp BETWEEN ? AND ?"
        );
        $del->execute($params);
        echo 'Cleared prior today rows: ' . $del->rowCount() . "\n";
    }

    $ins = $pdo->prepare(
        'INSERT INTO attendanceRecord (uid, scheduleId, checkerId, status, isOffline, timestamp, syncedAt)
         VALUES (?, ?, ?, ?, 0, ?, ?)'
    );

    $present = 0;
    $late = 0;
    $absent = 0;
    $n = 0;
    foreach ($faculty as $i => $fac) {
        $fid = (string) $fac['uid'];
        $sched = $scheduleIds[$fid];
        $start = substr((string) $sched['startTime'], 0, 8);
        if (strlen($start) === 5) {
            $start .= ':00';
        }
        $scanTs = $dateStr . ' ' . $start;
        $status = 'Present';

        if ($fid === $perfectFacultyId) {
            $status = 'Present';
        } elseif ($fid === $absentFacultyId) {
            $status = 'Absent';
        } elseif ($i % 5 === 2) {
            $status = 'Late';
            $scanTs = (new DateTimeImmutable($scanTs))
                ->modify('+35 minutes')
                ->format('Y-m-d H:i:s');
        }

        $ins->execute([generateUid(), $sched['uid'], $checkerId, $status, $scanTs, $scanTs]);
        $n++;
        if ($status === 'Present') {
            $present++;
        } elseif ($status === 'Late') {
            $late++;
        } else {
            $absent++;
        }
    }

    $pdo->commit();
    echo "Inserted {$n} attendance row(s) for {$dateStr}: Present={$present} Late={$late} Absent={$absent}\n";
    echo "OK\n";
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
