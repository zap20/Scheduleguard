<?php

declare(strict_types=1);

/**
 * Checker mobile → server attendance sync helpers.
 * Writes only into attendanceRecord (same table as the web app).
 */

require_once __DIR__ . '/helpers.php';

/** @var list<string> */
const ATTENDANCE_SYNC_STATUSES = ['Present', 'Late', 'WrongRoom', 'NoSchedule', 'Absent'];

/**
 * @param array<string,mixed> $raw
 * @return array<string,mixed>
 */
function normalizeAttendanceSyncPayload(array $raw): array
{
    return [
        'local_id' => trim((string) ($raw['local_id'] ?? $raw['localId'] ?? '')),
        'faculty_id' => trim((string) ($raw['faculty_id'] ?? $raw['facultyId'] ?? '')),
        'class_block_id' => trim((string) ($raw['class_block_id'] ?? $raw['classBlockId'] ?? '')),
        'schedule_id' => trim((string) ($raw['schedule_id'] ?? $raw['scheduleId'] ?? '')),
        'checker_id' => trim((string) ($raw['checker_id'] ?? $raw['checkerId'] ?? '')),
        'status' => trim((string) ($raw['status'] ?? '')),
        'recorded_at' => trim((string) ($raw['recorded_at'] ?? $raw['recordedAt'] ?? '')),
        'device_meta' => isset($raw['device_meta']) || isset($raw['deviceMeta'])
            ? trim((string) ($raw['device_meta'] ?? $raw['deviceMeta'] ?? ''))
            : null,
    ];
}

function normalizeAttendanceTimestamp(string $recordedAt): ?string
{
    if ($recordedAt === '') {
        return null;
    }

    // Accept ISO-8601 and MySQL datetime.
    $normalized = str_replace('T', ' ', $recordedAt);
    $normalized = preg_replace('/Z$/', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/[+-]\d{2}:\d{2}$/', '', $normalized) ?? $normalized;
    $normalized = trim($normalized);

    $ts = strtotime($normalized);
    if ($ts === false) {
        $ts = strtotime($recordedAt);
    }
    if ($ts === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $ts);
}

function facultyExistsForSync(string $facultyId): bool
{
    $stmt = db()->prepare(
        "SELECT userId FROM faculty WHERE userId = :uid LIMIT 1"
    );
    $stmt->execute([':uid' => $facultyId]);
    return (bool) $stmt->fetchColumn();
}

function classBlockExistsForSync(string $classBlockId): bool
{
    $stmt = db()->prepare(
        'SELECT uid FROM classBlock WHERE uid = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $classBlockId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Map mobile faculty + class block to a schedule meeting (required by attendanceRecord).
 */
function resolveScheduleIdForSync(
    string $facultyId,
    string $classBlockId,
    string $recordedAtMysql
): ?string {
    $dayName = date('l', strtotime($recordedAtMysql) ?: time());

    $stmt = db()->prepare(
        'SELECT uid FROM schedule
         WHERE classBlockId = :classBlockId
           AND facultyId = :facultyId
           AND day = :day
         ORDER BY startTime ASC
         LIMIT 1'
    );
    $stmt->execute([
        ':classBlockId' => $classBlockId,
        ':facultyId' => $facultyId,
        ':day' => $dayName,
    ]);
    $uid = $stmt->fetchColumn();
    if ($uid) {
        return (string) $uid;
    }

    $stmt = db()->prepare(
        'SELECT uid FROM schedule
         WHERE classBlockId = :classBlockId
           AND facultyId = :facultyId
         ORDER BY
           CASE WHEN LOWER(status) = \'confirmed\' THEN 0 ELSE 1 END,
           startTime ASC
         LIMIT 1'
    );
    $stmt->execute([
        ':classBlockId' => $classBlockId,
        ':facultyId' => $facultyId,
    ]);
    $uid = $stmt->fetchColumn();
    return $uid ? (string) $uid : null;
}

/**
 * @return array{local_id:string,server_id:?string,status:string,message:?string}
 */
function attendanceSyncResult(
    string $localId,
    ?string $serverId,
    string $status,
    ?string $message = null
): array {
    return [
        'local_id' => $localId,
        'server_id' => $serverId,
        'status' => $status,
        'message' => $message,
    ];
}

/**
 * Process one mobile attendance payload into attendanceRecord.
 *
 * @param array<string,mixed> $raw
 * @return array{local_id:string,server_id:?string,status:string,message:?string}
 */
function syncOneAttendanceRecord(array $raw, string $authCheckerId): array
{
    $row = normalizeAttendanceSyncPayload($raw);
    $localId = $row['local_id'];

    if ($localId === '') {
        return attendanceSyncResult('', null, 'error', 'local_id is required');
    }

    $existing = db()->prepare(
        'SELECT uid FROM attendanceRecord WHERE clientLocalId = :localId LIMIT 1'
    );
    $existing->execute([':localId' => $localId]);
    $existingUid = $existing->fetchColumn();
    if ($existingUid) {
        return attendanceSyncResult(
            $localId,
            (string) $existingUid,
            'duplicate',
            'Already synced'
        );
    }

    if ($row['faculty_id'] === '') {
        return attendanceSyncResult(
            $localId,
            null,
            'error',
            'faculty_id is required'
        );
    }

    if ($row['checker_id'] !== '' && $row['checker_id'] !== $authCheckerId) {
        return attendanceSyncResult(
            $localId,
            null,
            'error',
            'checker_id does not match authenticated Checker'
        );
    }

    if (!in_array($row['status'], ATTENDANCE_SYNC_STATUSES, true)) {
        return attendanceSyncResult(
            $localId,
            null,
            'error',
            'Invalid status'
        );
    }

    $timestamp = normalizeAttendanceTimestamp((string) $row['recorded_at']);
    if ($timestamp === null) {
        return attendanceSyncResult(
            $localId,
            null,
            'error',
            'recorded_at is invalid'
        );
    }

    if (!facultyExistsForSync($row['faculty_id'])) {
        return attendanceSyncResult(
            $localId,
            null,
            'error',
            'faculty_id not found'
        );
    }

    // Prefer explicit schedule_id (mobile cache row is often one meeting).
    $scheduleId = $row['schedule_id'] !== '' ? $row['schedule_id'] : null;
    if ($scheduleId === null && $row['class_block_id'] !== '') {
        $asSchedule = db()->prepare(
            'SELECT uid FROM schedule WHERE uid = :uid LIMIT 1'
        );
        $asSchedule->execute([':uid' => $row['class_block_id']]);
        $hit = $asSchedule->fetchColumn();
        if ($hit) {
            $scheduleId = (string) $hit;
        }
    }

    if ($scheduleId !== null) {
        $sched = db()->prepare(
            'SELECT uid, facultyId, classBlockId FROM schedule WHERE uid = :uid LIMIT 1'
        );
        $sched->execute([':uid' => $scheduleId]);
        $schedRow = $sched->fetch();
        if (!$schedRow) {
            return attendanceSyncResult(
                $localId,
                null,
                'error',
                'schedule_id not found'
            );
        }
        if ((string) $schedRow['facultyId'] !== $row['faculty_id']) {
            return attendanceSyncResult(
                $localId,
                null,
                'error',
                'faculty_id does not match schedule'
            );
        }
        $scheduleId = (string) $schedRow['uid'];
    } else {
        if ($row['class_block_id'] === '' || !classBlockExistsForSync($row['class_block_id'])) {
            return attendanceSyncResult(
                $localId,
                null,
                'error',
                'class_block_id not found'
            );
        }
        $scheduleId = resolveScheduleIdForSync(
            $row['faculty_id'],
            $row['class_block_id'],
            $timestamp
        );
        if ($scheduleId === null) {
            return attendanceSyncResult(
                $localId,
                null,
                'error',
                'No schedule meeting for faculty_id + class_block_id'
            );
        }
    }

    $serverId = generateUid();
    $deviceMeta = $row['device_meta'];
    if ($deviceMeta === '') {
        $deviceMeta = null;
    }
    if ($deviceMeta !== null && strlen($deviceMeta) > 500) {
        $deviceMeta = substr($deviceMeta, 0, 500);
    }

    try {
        $stmt = db()->prepare(
            'INSERT INTO attendanceRecord
               (uid, scheduleId, checkerId, status, isOffline, timestamp, syncedAt, clientLocalId, deviceMeta)
             VALUES
               (:uid, :scheduleId, :checkerId, :status, 1, :timestamp, NOW(), :clientLocalId, :deviceMeta)'
        );
        $stmt->execute([
            ':uid' => $serverId,
            ':scheduleId' => $scheduleId,
            ':checkerId' => $authCheckerId,
            ':status' => $row['status'],
            ':timestamp' => $timestamp,
            ':clientLocalId' => $localId,
            ':deviceMeta' => $deviceMeta,
        ]);
    } catch (PDOException $e) {
        // Race: another request inserted the same clientLocalId.
        $again = db()->prepare(
            'SELECT uid FROM attendanceRecord WHERE clientLocalId = :localId LIMIT 1'
        );
        $again->execute([':localId' => $localId]);
        $againUid = $again->fetchColumn();
        if ($againUid) {
            return attendanceSyncResult(
                $localId,
                (string) $againUid,
                'duplicate',
                'Already synced'
            );
        }

        return attendanceSyncResult(
            $localId,
            null,
            'error',
            'Insert failed'
        );
    }

    return attendanceSyncResult($localId, $serverId, 'created', null);
}

/**
 * @param list<array<string,mixed>> $records
 * @return list<array{local_id:string,server_id:?string,status:string,message:?string}>
 */
function syncAttendanceRecordsBatch(array $records, string $authCheckerId): array
{
    $results = [];
    foreach ($records as $raw) {
        if (!is_array($raw)) {
            $results[] = attendanceSyncResult('', null, 'error', 'Invalid record payload');
            continue;
        }
        $results[] = syncOneAttendanceRecord($raw, $authCheckerId);
    }
    return $results;
}

/**
 * Read-only cache payload for Checker mobile (faculty + denormalized class blocks).
 *
 * @return array{faculty:list<array<string,mixed>>,classBlocks:list<array<string,mixed>>}
 */
function fetchCheckerMobileCaches(): array
{
    $facultyStmt = db()->query(
        "SELECT uid, firstName, lastName, email, schoolId, departmentId, status, employmentType
         FROM userProfile
         WHERE role = 'Faculty' AND status = 'Active'
         ORDER BY lastName ASC, firstName ASC"
    );
    $faculty = [];
    foreach ($facultyStmt->fetchAll() as $row) {
        $faculty[] = [
            'uid' => (string) $row['uid'],
            'firstName' => (string) $row['firstName'],
            'lastName' => (string) $row['lastName'],
            'email' => (string) $row['email'],
            'schoolId' => (string) ($row['schoolId'] ?? ''),
            'departmentId' => $row['departmentId'] !== null ? (string) $row['departmentId'] : null,
            'status' => (string) ($row['status'] ?? 'Active'),
            'employmentType' => (string) ($row['employmentType'] ?? 'Regular'),
        ];
    }

    // One cache row per schedule meeting that is tied to a class block + faculty.
    // Local app keys capture on class_block id; scheduleId is included for mapping.
    $blockStmt = db()->query(
        "SELECT
            COALESCE(cb.uid, s.classBlockId) AS uid,
            s.uid AS scheduleId,
            s.facultyId,
            s.day,
            s.startTime,
            s.endTime,
            s.departmentId,
            s.yearLevel,
            s.academicYear,
            s.semester,
            s.status AS scheduleStatus,
            cb.name AS blockName,
            cb.status AS blockStatus,
            sub.code AS subjectCode,
            sub.title AS subjectName,
            s.roomId AS roomId,
            r.name AS roomName,
            r.building AS roomBuilding
         FROM schedule s
         LEFT JOIN classBlock cb ON cb.uid = s.classBlockId
         INNER JOIN subject sub ON sub.uid = s.subjectId
         INNER JOIN room r ON r.uid = s.roomId
         WHERE s.facultyId IS NOT NULL
           AND s.classBlockId IS NOT NULL
           AND LOWER(s.status) = 'confirmed'
         ORDER BY s.day ASC, s.startTime ASC"
    );

    $classBlocks = [];
    foreach ($blockStmt->fetchAll() as $row) {
        $start = substr((string) $row['startTime'], 0, 5);
        $end = substr((string) $row['endTime'], 0, 5);
        // Unique local cache id per meeting so same classBlock multiple slots don't collide.
        $cacheId = (string) $row['scheduleId'];
        $classBlocks[] = [
            'uid' => $cacheId,
            'id' => $cacheId,
            'classBlockId' => (string) $row['uid'],
            'scheduleId' => (string) $row['scheduleId'],
            'facultyId' => (string) $row['facultyId'],
            'day' => (string) $row['day'],
            'startTime' => $start,
            'endTime' => $end,
            'scheduleTime' => $start . '–' . $end,
            'departmentId' => $row['departmentId'] !== null ? (string) $row['departmentId'] : null,
            'yearLevel' => $row['yearLevel'] !== null ? (string) $row['yearLevel'] : null,
            'academicYear' => $row['academicYear'] !== null ? (int) $row['academicYear'] : null,
            'semester' => $row['semester'] !== null ? (string) $row['semester'] : null,
            'status' => (string) ($row['blockStatus'] ?? $row['scheduleStatus'] ?? 'Open'),
            'blockName' => (string) ($row['blockName'] ?? ''),
            'name' => (string) ($row['blockName'] ?? ''),
            'subjectCode' => (string) ($row['subjectCode'] ?? ''),
            'subjectName' => (string) ($row['subjectName'] ?? ''),
            'roomId' => (string) $row['roomId'],
            'roomName' => (string) ($row['roomName'] ?? ''),
            'roomBuilding' => (string) ($row['roomBuilding'] ?? ''),
        ];
    }

    return [
        'faculty' => $faculty,
        'classBlocks' => $classBlocks,
    ];
}
