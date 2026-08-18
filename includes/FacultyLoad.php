<?php

declare(strict_types=1);

/**
 * Natural-language faculty load assignment (Dean selects faculty first).
 *
 * Examples (with facultyId from UI):
 *   "8 loads"              → pick a random subject still TBF / available to that faculty
 *   "8 loads CICT-ATT"      → use that specific subject
 *   "8 load CICT-ATT"
 *   "CICT-ATT 8 loads"
 *
 * Load per subject offering = (lectureHours + labHours) / 3.
 */

require_once __DIR__ . '/Schedule.php';
require_once __DIR__ . '/Subject.php';
require_once __DIR__ . '/Term.php';

/**
 * @return array{
 *   rawCommand:string,
 *   targetLoad:float,
 *   subjectCode:string,
 *   subjectSpecified:bool,
 *   facultyHint:string,
 *   ready:bool,
 *   missing:list<string>,
 *   confirmSummary:string
 * }
 */
function parseFacultyLoadCommand(string $raw): array
{
    $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
    $missing = [];
    $targetLoad = null;
    $subjectCode = '';
    $facultyHint = '';

    $text = $raw;

    // Optional trailing "for Faculty Name" (UI faculty select is preferred).
    if (preg_match('/\b(?:for|to)\s+(.+)$/i', $text, $m)) {
        $facultyHint = trim($m[1]);
        $text = trim(substr($text, 0, -strlen($m[0])));
    }

    // "N loads" only (subject chosen randomly later)
    if (preg_match('/^(\d+(?:\.\d+)?)\s*loads?$/i', $text, $m)) {
        $targetLoad = (float) $m[1];
        $subjectCode = '';
    } elseif (preg_match('/^(\d+(?:\.\d+)?)\s*loads?\s+(.+)$/i', $text, $m)) {
        $targetLoad = (float) $m[1];
        $subjectCode = trim($m[2]);
    } elseif (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)\s*loads?$/i', $text, $m)) {
        $subjectCode = trim($m[1]);
        $targetLoad = (float) $m[2];
    } elseif (preg_match('/^(.+?)\s+(\d+(?:\.\d+)?)\s*loads?\s+(.+)$/i', $text, $m)) {
        $facultyHint = trim($m[1]);
        $targetLoad = (float) $m[2];
        $subjectCode = trim($m[3]);
    }

    $subjectCode = strtoupper(trim($subjectCode));
    $subjectCode = preg_replace('/\s+/', ' ', $subjectCode) ?? $subjectCode;
    $subjectSpecified = $subjectCode !== '';

    if ($targetLoad === null || $targetLoad <= 0) {
        $missing[] = 'load';
    }

    $ready = $missing === [];
    $summary = $ready
        ? (
            $subjectSpecified
                ? sprintf('Assign %.2f load of %s — confirm?', $targetLoad, $subjectCode)
                : sprintf(
                    'Assign %.2f load — pick a random subject still available (TBF) for the selected faculty — confirm?',
                    $targetLoad
                )
        )
        : 'Could not parse load command. Try “8 loads” or “8 loads CICT-ATT”.';

    return [
        'rawCommand' => $raw,
        'targetLoad' => $targetLoad ?? 0.0,
        'subjectCode' => $subjectCode,
        'subjectSpecified' => $subjectSpecified,
        'facultyHint' => $facultyHint,
        'ready' => $ready,
        'missing' => $missing,
        'confirmSummary' => $summary,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function findSubjectByCodeForLoad(string $code, ?string $departmentId = null): ?array
{
    $code = strtoupper(trim($code));
    $sql = subjectSelectSql() . ' WHERE UPPER(REPLACE(s.code, \' \', \'\')) = UPPER(REPLACE(:code, \' \', \'\'))';
    $params = [':code' => $code];
    if ($departmentId !== null && $departmentId !== '') {
        $sql .= ' AND (
            s.departmentId = :departmentId
            OR s.servingDepartmentId = :departmentIdServe
        )';
        $params[':departmentId'] = $departmentId;
        $params[':departmentIdServe'] = $departmentId;
    }
    $sql .= ' ORDER BY s.status ASC, s.curriculumYear DESC LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? mapSubjectRow($row) : null;
}

/**
 * @return list<array<string,mixed>>
 */
function listActiveFacultyForDepartment(string $departmentId): array
{
    require_once __DIR__ . '/UserManagement.php';

    $stmt = db()->prepare(
        "SELECT uid, firstName, lastName, departmentId, employmentType
         FROM userProfile
         WHERE role = 'Faculty' AND status = 'Active'
           AND (departmentId = :departmentId OR departmentId IS NULL)
         ORDER BY lastName, firstName"
    );
    $stmt->execute([':departmentId' => $departmentId]);
    return array_map(static function (array $row): array {
        $employmentType = normalizeFacultyEmploymentType(
            isset($row['employmentType']) ? (string) $row['employmentType'] : 'Regular',
            false
        ) ?? 'Regular';
        return [
            'uid' => (string) $row['uid'],
            'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
            'departmentId' => $row['departmentId'] !== null ? (string) $row['departmentId'] : null,
            'employmentType' => $employmentType,
            'minLoad' => facultyMinLoadForEmploymentType($employmentType),
        ];
    }, $stmt->fetchAll());
}

/**
 * @param list<array<string,mixed>> $faculty
 */
function resolveFacultyHint(string $hint, array $faculty): ?array
{
    $hint = trim($hint);
    if ($hint === '') {
        return null;
    }
    $norm = strtolower(preg_replace('/\s+/', ' ', $hint) ?? '');
    foreach ($faculty as $fac) {
        if (strtolower($fac['fullName']) === $norm) {
            return $fac;
        }
    }
    foreach ($faculty as $fac) {
        if (stripos($fac['fullName'], $hint) !== false) {
            return $fac;
        }
    }
    $parts = preg_split('/\s+/', $norm) ?: [];
    $last = $parts[count($parts) - 1] ?? '';
    if ($last !== '') {
        foreach ($faculty as $fac) {
            if (stripos(strtolower($fac['fullName']), $last) !== false) {
                return $fac;
            }
        }
    }

    return null;
}

/**
 * Current faculty load map (uid => load float) for the term.
 *
 * @param list<array<string,mixed>> $faculty
 * @return array<string,float>
 */
function currentFacultyLoadMap(array $faculty, int $academicYear, string $semester): array
{
    $loads = [];
    foreach ($faculty as $fac) {
        $loads[(string) $fac['uid']] = 0.0;
    }
    $rows = fetchDeanFacultySchedules(null, null);
    $byFaculty = [];
    foreach ($rows as $row) {
        if ((int) ($row['academicYear'] ?? 0) !== $academicYear) {
            continue;
        }
        if ((string) ($row['semester'] ?? '') !== $semester) {
            continue;
        }
        $fid = trim((string) ($row['facultyId'] ?? ''));
        if ($fid === '') {
            continue;
        }
        $byFaculty[$fid][] = $row;
    }
    foreach ($byFaculty as $fid => $facRows) {
        $loads[$fid] = (float) computeFacultyTeachingLoad($facRows)['load'];
    }

    return $loads;
}

/**
 * TBF offerings for a subject in the current term, grouped by class block / block.
 *
 * @return list<array{key:string,label:string,scheduleIds:list<string>,rows:list<array<string,mixed>>,load:float}>
 */
function listTbfSubjectOfferings(string $subjectId, int $academicYear, string $semester): array
{
    $rows = fetchDeanFacultySchedules(SCHEDULE_INSTRUCTOR_TBF, null);
    $groups = [];
    foreach ($rows as $row) {
        if ((string) ($row['subjectId'] ?? '') !== $subjectId) {
            continue;
        }
        if ((int) ($row['academicYear'] ?? 0) !== $academicYear) {
            continue;
        }
        if ((string) ($row['semester'] ?? '') !== $semester) {
            continue;
        }
        $blockKey = trim((string) ($row['classBlockId'] ?? ''));
        if ($blockKey === '') {
            $blockKey = 'block:' . trim((string) ($row['blockName'] ?? '')) . ':' . (string) ($row['blockNumber'] ?? '');
        }
        if (!isset($groups[$blockKey])) {
            $groups[$blockKey] = [
                'key' => $blockKey,
                'label' => trim((string) ($row['blockName'] ?? '')) !== ''
                    ? (string) $row['blockName']
                    : 'Offering',
                'scheduleIds' => [],
                'rows' => [],
                'load' => (float) ($row['subjectLoad'] ?? 0),
            ];
        }
        $groups[$blockKey]['scheduleIds'][] = (string) $row['uid'];
        $groups[$blockKey]['rows'][] = $row;
        if ($groups[$blockKey]['load'] <= 0) {
            $groups[$blockKey]['load'] = subjectTeachingLoadFromHours(
                (float) ($row['lectureHours'] ?? 0),
                (float) ($row['labHours'] ?? 0)
            );
        }
    }

    return array_values($groups);
}

/**
 * Contact hours and load from scheduled meeting rows (hours ÷ 3).
 *
 * @param list<array<string,mixed>> $rows
 * @return array{
 *   meetings:list<array{day:string,startTime:string,endTime:string,roomLabel:string,minutes:int,hours:float}>,
 *   contactMinutes:int,
 *   contactHours:float,
 *   load:float,
 *   loadFormula:string
 * }
 */
function offeringContactMetricsFromRows(array $rows): array
{
    $meetings = [];
    $contactMinutes = 0;
    foreach ($rows as $row) {
        $start = substr((string) ($row['startTime'] ?? ''), 0, 5);
        $end = substr((string) ($row['endTime'] ?? ''), 0, 5);
        $minutes = 0;
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $start, $sm)
            && preg_match('/^(\d{1,2}):(\d{2})$/', $end, $em)
        ) {
            $sMin = ((int) $sm[1]) * 60 + (int) $sm[2];
            $eMin = ((int) $em[1]) * 60 + (int) $em[2];
            if ($eMin > $sMin) {
                $minutes = $eMin - $sMin;
                $contactMinutes += $minutes;
            }
        }
        $meetings[] = [
            'day' => (string) ($row['day'] ?? ''),
            'startTime' => $start,
            'endTime' => $end,
            'roomLabel' => trim((string) ($row['roomLabel'] ?? $row['roomName'] ?? '')),
            'minutes' => $minutes,
            'hours' => $minutes > 0 ? round($minutes / 60, 2) : 0.0,
        ];
    }
    $contactHours = round($contactMinutes / 60, 2);
    $load = $contactHours > 0
        ? round($contactHours / FACULTY_LOAD_HOURS_DIVISOR, 4)
        : 0.0;

    return [
        'meetings' => $meetings,
        'contactMinutes' => $contactMinutes,
        'contactHours' => $contactHours,
        'load' => $load,
        'loadFormula' => $contactHours > 0
            ? sprintf('%s h ÷ 3 = %s', rtrim(rtrim(number_format($contactHours, 2), '0'), '.'), rtrim(rtrim(number_format($load, 4), '0'), '.'))
            : '0 h ÷ 3 = 0',
    ];
}

/**
 * @param array{key:string,label:string,scheduleIds:list<string>,rows:list<array<string,mixed>>,load:float} $offering
 * @param array<string,mixed>|null $subject
 * @return array<string,mixed>
 */
function enrichOfferingForLoadPreview(array $offering, ?array $subject = null): array
{
    $metrics = offeringContactMetricsFromRows($offering['rows'] ?? []);
    $load = (float) ($metrics['load'] ?? 0);
    if ($load <= 0) {
        $load = (float) ($offering['load'] ?? 0);
        if ($load <= 0 && $subject !== null) {
            $load = subjectTeachingLoadFromHours(
                (float) ($subject['lectureHours'] ?? 0),
                (float) ($subject['labHours'] ?? 0)
            );
            $totalHours = (float) ($subject['lectureHours'] ?? 0) + (float) ($subject['labHours'] ?? 0);
            $metrics['loadFormula'] = sprintf(
                '(%s + %s) ÷ 3 = %s',
                rtrim(rtrim(number_format((float) ($subject['lectureHours'] ?? 0), 2), '0'), '.'),
                rtrim(rtrim(number_format((float) ($subject['labHours'] ?? 0), 2), '0'), '.'),
                rtrim(rtrim(number_format($load, 4), '0'), '.')
            );
            $metrics['contactHours'] = round($totalHours, 2);
        }
    }

    $scheduleSummary = [];
    foreach ($metrics['meetings'] as $m) {
        if ($m['day'] === '') {
            continue;
        }
        $roomPart = $m['roomLabel'] !== '' ? ' · ' . $m['roomLabel'] : '';
        $scheduleSummary[] = $m['day'] . ' ' . $m['startTime'] . '–' . $m['endTime'] . $roomPart;
    }

    return [
        'offeringKey' => (string) ($offering['key'] ?? ''),
        'blockLabel' => (string) ($offering['label'] ?? ''),
        'subjectId' => $subject !== null ? (string) ($subject['uid'] ?? '') : '',
        'subjectCode' => $subject !== null ? (string) ($subject['code'] ?? '') : '',
        'subjectTitle' => $subject !== null ? (string) ($subject['title'] ?? '') : '',
        'lectureHours' => $subject !== null ? (float) ($subject['lectureHours'] ?? 0) : 0.0,
        'labHours' => $subject !== null ? (float) ($subject['labHours'] ?? 0) : 0.0,
        'scheduleIds' => $offering['scheduleIds'] ?? [],
        'meetingCount' => count($offering['scheduleIds'] ?? []),
        'meetings' => $metrics['meetings'],
        'scheduleSummary' => $scheduleSummary,
        'scheduleText' => implode('; ', $scheduleSummary),
        'contactHours' => (float) ($metrics['contactHours'] ?? 0),
        'load' => $load,
        'loadFormula' => (string) ($metrics['loadFormula'] ?? ''),
        'rows' => $offering['rows'] ?? [],
        'suggested' => false,
        'canAssign' => true,
    ];
}

/**
 * Current faculty teaching load for the active term.
 *
 * @return array{load:float,contactHours:float,offeringCount:int,subjectCount:int}
 */
function currentFacultyLoadForTerm(string $facultyId, int $academicYear, string $semester): array
{
    $rows = fetchDeanFacultySchedules($facultyId, null);
    $termRows = [];
    foreach ($rows as $row) {
        if ((int) ($row['academicYear'] ?? 0) !== $academicYear) {
            continue;
        }
        if ((string) ($row['semester'] ?? '') !== $semester) {
            continue;
        }
        $termRows[] = $row;
    }
    $computed = computeFacultyTeachingLoad($termRows);

    return [
        'load' => (float) ($computed['load'] ?? 0),
        'contactHours' => (float) ($computed['contactHours'] ?? 0),
        'offeringCount' => (int) ($computed['offeringCount'] ?? 0),
        'subjectCount' => (int) ($computed['subjectCount'] ?? 0),
    ];
}

/**
 * Greedy non-overlapping offering pick to reach target load.
 *
 * @param list<array<string,mixed>> $offerings enriched offerings (must include rows, load, offeringKey)
 * @return array{selected:list<array<string,mixed>>,assignedLoad:float,assignedContactHours:float,suggestedKeys:list<string>}
 */
function suggestOfferingsForLoadTarget(
    array $offerings,
    float $targetLoad,
    string $facultyId,
    int $academicYear,
    string $semester
): array {
    if ($offerings === [] || $targetLoad <= 0) {
        return [
            'selected' => [],
            'assignedLoad' => 0.0,
            'assignedContactHours' => 0.0,
            'suggestedKeys' => [],
        ];
    }

    $sorted = $offerings;
    usort($sorted, static function (array $a, array $b): int {
        $loadCmp = ($b['load'] ?? 0) <=> ($a['load'] ?? 0);
        if ($loadCmp !== 0) {
            return $loadCmp;
        }

        return strcmp((string) ($a['blockLabel'] ?? ''), (string) ($b['blockLabel'] ?? ''));
    });

    /** @var list<array{day:string,startTime:string,endTime:string}> $pendingBusy */
    $pendingBusy = [];
    $selected = [];
    $assignedLoad = 0.0;
    $assignedContactHours = 0.0;

    foreach ($sorted as $offering) {
        if ($assignedLoad + 1e-9 >= $targetLoad) {
            break;
        }
        $rawOffering = [
            'scheduleIds' => $offering['scheduleIds'] ?? [],
            'rows' => $offering['rows'] ?? [],
        ];
        if (!facultyCanTakeOffering($facultyId, $rawOffering, $academicYear, $semester, $pendingBusy)) {
            continue;
        }
        $piece = (float) ($offering['load'] ?? 0);
        if ($piece <= 0) {
            continue;
        }
        $selected[] = $offering;
        $assignedLoad += $piece;
        $assignedContactHours += (float) ($offering['contactHours'] ?? 0);
        foreach ($offering['rows'] as $row) {
            $pendingBusy[] = [
                'day' => (string) $row['day'],
                'startTime' => (string) $row['startTime'],
                'endTime' => (string) $row['endTime'],
            ];
        }
    }

    $suggestedKeys = array_map(static fn (array $o): string => (string) ($o['offeringKey'] ?? ''), $selected);

    return [
        'selected' => $selected,
        'assignedLoad' => round($assignedLoad, 4),
        'assignedContactHours' => round($assignedContactHours, 2),
        'suggestedKeys' => $suggestedKeys,
    ];
}

/**
 * @param list<array<string,mixed>> $selectedOfferings
 * @param array<string,mixed> $faculty
 * @return list<array<string,mixed>>
 */
function buildLoadAssignmentsFromOfferings(array $selectedOfferings, array $faculty): array
{
    $assignments = [];
    foreach ($selectedOfferings as $offering) {
        $assignments[] = [
            'offeringKey' => (string) ($offering['offeringKey'] ?? ''),
            'blockLabel' => (string) ($offering['blockLabel'] ?? ''),
            'subjectCode' => (string) ($offering['subjectCode'] ?? ''),
            'facultyId' => (string) ($faculty['uid'] ?? ''),
            'facultyName' => (string) ($faculty['fullName'] ?? ''),
            'scheduleIds' => $offering['scheduleIds'] ?? [],
            'meetingCount' => count($offering['scheduleIds'] ?? []),
            'contactHours' => (float) ($offering['contactHours'] ?? 0),
            'load' => (float) ($offering['load'] ?? 0),
            'loadFormula' => (string) ($offering['loadFormula'] ?? ''),
            'scheduleText' => (string) ($offering['scheduleText'] ?? ''),
        ];
    }

    return $assignments;
}

/**
 * @param list<array<string,mixed>> $allOfferings
 * @param list<string>|null $selectedOfferingKeys
 * @return list<array<string,mixed>>
 */
function resolveSelectedOfferings(array $allOfferings, ?array $selectedOfferingKeys): array
{
    if ($selectedOfferingKeys === null || $selectedOfferingKeys === []) {
        return array_values(array_filter($allOfferings, static fn (array $o): bool => !empty($o['suggested'])));
    }
    $want = [];
    foreach ($selectedOfferingKeys as $key) {
        $key = trim((string) $key);
        if ($key !== '') {
            $want[$key] = true;
        }
    }
    if ($want === []) {
        return array_values(array_filter($allOfferings, static fn (array $o): bool => !empty($o['suggested'])));
    }

    $selected = [];
    foreach ($allOfferings as $offering) {
        $key = (string) ($offering['offeringKey'] ?? '');
        if (isset($want[$key])) {
            $selected[] = $offering;
        }
    }

    return $selected;
}

/**
 * True when faculty already has a meeting overlapping day/start/end in term.
 */
function facultyHasTimeConflict(
    string $facultyId,
    string $day,
    string $startTime,
    string $endTime,
    int $academicYear,
    string $semester,
    array $ignoreScheduleIds = []
): bool {
    if ($facultyId === '') {
        return false;
    }
    $start = normalizeScheduleTime($startTime) ?? $startTime;
    $end = normalizeScheduleTime($endTime) ?? $endTime;
    if (strlen($start) === 5) {
        $start .= ':00';
    }
    if (strlen($end) === 5) {
        $end .= ':00';
    }
    $sql = 'SELECT s.uid
            FROM schedule s
            WHERE s.facultyId = :facultyId
              AND s.academicYear = :academicYear
              AND s.semester = :semester
              AND LOWER(s.day) = LOWER(:day)
              AND s.startTime < :endTime
              AND s.endTime > :startTime
              AND LOWER(s.status) IN (\'confirmed\', \'conflict\', \'draft\')';
    $params = [
        ':facultyId' => $facultyId,
        ':academicYear' => $academicYear,
        ':semester' => $semester,
        ':day' => $day,
        ':endTime' => $end,
        ':startTime' => $start,
    ];
    if ($ignoreScheduleIds !== []) {
        $ph = [];
        foreach (array_values($ignoreScheduleIds) as $i => $id) {
            $key = ':ign' . $i;
            $ph[] = $key;
            $params[$key] = $id;
        }
        $sql .= ' AND s.uid NOT IN (' . implode(',', $ph) . ')';
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

/**
 * Assign facultyId on schedule rows after hard faculty free-time checks.
 *
 * @param list<string> $scheduleIds
 */
function assignFacultyToScheduleIds(array $scheduleIds, string $facultyId): int
{
    $facultyId = normalizeAssignmentFacultyId($facultyId);
    if ($facultyId === null) {
        throw new InvalidArgumentException('facultyId is required.');
    }

    $ids = [];
    foreach ($scheduleIds as $uid) {
        $uid = trim((string) $uid);
        if ($uid !== '') {
            $ids[] = $uid;
        }
    }
    if ($ids === []) {
        return 0;
    }

    $rows = [];
    foreach ($ids as $uid) {
        $row = fetchScheduleById($uid);
        if ($row === null) {
            throw new InvalidArgumentException('Schedule not found: ' . $uid);
        }
        $rows[] = $row;
    }

    // Refuse if meetings in this assign batch already overlap each other.
    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $rows[$i];
            $b = $rows[$j];
            if (strcasecmp((string) $a['day'], (string) $b['day']) !== 0) {
                continue;
            }
            $aStart = (string) $a['startTime'];
            $aEnd = (string) $a['endTime'];
            $bStart = (string) $b['startTime'];
            $bEnd = (string) $b['endTime'];
            if ($aStart < $bEnd && $bStart < $aEnd) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Cannot assign faculty: meetings in this load overlap on %s (%s–%s and %s–%s).',
                        (string) $a['day'],
                        $aStart,
                        $aEnd,
                        $bStart,
                        $bEnd
                    )
                );
            }
        }
    }

    // Hard check against existing faculty schedule (ignore this batch).
    foreach ($rows as $row) {
        assertFacultyAvailableAt(
            $facultyId,
            (string) $row['day'],
            (string) $row['startTime'],
            (string) $row['endTime'],
            (int) $row['academicYear'],
            (string) $row['semester'],
            $ids
        );
    }

    $updated = 0;
    $stmt = db()->prepare('UPDATE schedule SET facultyId = :facultyId WHERE uid = :uid');
    foreach ($ids as $uid) {
        $stmt->execute([':facultyId' => $facultyId, ':uid' => $uid]);
        $updated += $stmt->rowCount();
    }

    return $updated;
}

/**
 * Clear facultyId (return meetings to TBF).
 *
 * @param list<string> $scheduleIds
 */
function unassignFacultyFromScheduleIds(array $scheduleIds): int
{
    $updated = 0;
    $stmt = db()->prepare('UPDATE schedule SET facultyId = NULL WHERE uid = :uid');
    foreach ($scheduleIds as $uid) {
        $uid = trim((string) $uid);
        if ($uid === '') {
            continue;
        }
        $stmt->execute([':uid' => $uid]);
        $updated += $stmt->rowCount();
    }

    return $updated;
}

/**
 * Remove one faculty load offering: unassign every meeting in the same
 * subject + class-block group for that faculty (lec + lab sessions together).
 *
 * @return array{
 *   subjectCode:string,
 *   subjectName:string,
 *   blockName:string,
 *   facultyId:string,
 *   facultyName:string,
 *   scheduleIds:list<string>,
 *   updatedMeetings:int
 * }
 */
function removeFacultyLoadOfferingByScheduleId(string $scheduleId): array
{
    $scheduleId = trim($scheduleId);
    if ($scheduleId === '') {
        throw new InvalidArgumentException('scheduleId is required.');
    }
    $seed = fetchScheduleById($scheduleId);
    if ($seed === null) {
        throw new InvalidArgumentException('Schedule not found.');
    }

    $facultyId = trim((string) ($seed['facultyId'] ?? ''));
    if ($facultyId === '') {
        throw new InvalidArgumentException('This meeting is already unassigned (TBF).');
    }

    $subjectId = trim((string) ($seed['subjectId'] ?? ''));
    if ($subjectId === '') {
        throw new InvalidArgumentException('Schedule has no subject.');
    }

    $academicYear = (int) ($seed['academicYear'] ?? 0);
    $semester = (string) ($seed['semester'] ?? '');
    $classBlockId = trim((string) ($seed['classBlockId'] ?? ''));
    $blockName = trim((string) ($seed['blockName'] ?? ''));
    $blockNumber = $seed['blockNumber'] ?? null;

    $rows = fetchDeanFacultySchedules($facultyId, null);
    $scheduleIds = [];
    foreach ($rows as $row) {
        if (trim((string) ($row['subjectId'] ?? '')) !== $subjectId) {
            continue;
        }
        if ((int) ($row['academicYear'] ?? 0) !== $academicYear) {
            continue;
        }
        if ((string) ($row['semester'] ?? '') !== $semester) {
            continue;
        }
        if ($classBlockId !== '') {
            if (trim((string) ($row['classBlockId'] ?? '')) !== $classBlockId) {
                continue;
            }
        } else {
            if (trim((string) ($row['blockName'] ?? '')) !== $blockName) {
                continue;
            }
            $rowBlockNumber = $row['blockNumber'] ?? null;
            if ($blockNumber !== null && $rowBlockNumber !== null
                && (int) $blockNumber !== (int) $rowBlockNumber) {
                continue;
            }
        }
        $scheduleIds[] = (string) $row['uid'];
    }

    if ($scheduleIds === []) {
        $scheduleIds = [(string) $seed['uid']];
    }

    $updated = unassignFacultyFromScheduleIds($scheduleIds);

    return [
        'subjectCode' => (string) ($seed['subjectCode'] ?? ''),
        'subjectName' => (string) ($seed['subjectName'] ?? ''),
        'blockName' => $blockName !== '' ? $blockName : 'Offering',
        'facultyId' => $facultyId,
        'facultyName' => (string) ($seed['facultyName'] ?? $seed['instructor'] ?? ''),
        'scheduleIds' => $scheduleIds,
        'updatedMeetings' => $updated,
    ];
}

/**
 * Subject IDs this faculty already teaches in the term (already distributed load).
 *
 * @return array<string,true>
 */
function facultyAssignedSubjectIds(string $facultyId, int $academicYear, string $semester): array
{
    $rows = fetchDeanFacultySchedules($facultyId, null);
    $ids = [];
    foreach ($rows as $row) {
        if ((int) ($row['academicYear'] ?? 0) !== $academicYear) {
            continue;
        }
        if ((string) ($row['semester'] ?? '') !== $semester) {
            continue;
        }
        $sid = trim((string) ($row['subjectId'] ?? ''));
        if ($sid !== '') {
            $ids[$sid] = true;
        }
    }

    return $ids;
}

/**
 * True when faculty can take every meeting in the offering without time conflict
 * against DB rows and optional in-memory busy slots (same assign batch).
 *
 * @param array{scheduleIds:list<string>,rows:list<array<string,mixed>>} $offering
 * @param list<array{day:string,startTime:string,endTime:string}> $extraBusy
 */
function facultyCanTakeOffering(
    string $facultyId,
    array $offering,
    int $academicYear,
    string $semester,
    array $extraBusy = []
): bool {
    $ignore = $offering['scheduleIds'] ?? [];
    foreach ($offering['rows'] as $row) {
        $day = (string) $row['day'];
        $start = (string) $row['startTime'];
        $end = (string) $row['endTime'];
        if (facultyHasTimeConflict(
            $facultyId,
            $day,
            $start,
            $end,
            $academicYear,
            $semester,
            $ignore
        )) {
            return false;
        }
        foreach ($extraBusy as $busy) {
            if (strcasecmp((string) ($busy['day'] ?? ''), $day) !== 0) {
                continue;
            }
            $bStart = (string) ($busy['startTime'] ?? '');
            $bEnd = (string) ($busy['endTime'] ?? '');
            if ($bStart !== '' && $bEnd !== '' && $start < $bEnd && $end > $bStart) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Subjects with TBF offerings that this faculty can still take, and (by default)
 * that the faculty does not already teach.
 *
 * @return list<array{subject:array<string,mixed>,offerings:list<array<string,mixed>>,availableCount:int}>
 */
function listAvailableSubjectsForFacultyLoad(
    string $facultyId,
    string $departmentId,
    int $academicYear,
    string $semester,
    bool $excludeAlreadyAssigned = true
): array {
    $already = $excludeAlreadyAssigned
        ? facultyAssignedSubjectIds($facultyId, $academicYear, $semester)
        : [];

    $tbfRows = fetchDeanFacultySchedules(SCHEDULE_INSTRUCTOR_TBF, null);
    $bySubject = [];
    foreach ($tbfRows as $row) {
        if ((int) ($row['academicYear'] ?? 0) !== $academicYear) {
            continue;
        }
        if ((string) ($row['semester'] ?? '') !== $semester) {
            continue;
        }
        $sid = trim((string) ($row['subjectId'] ?? ''));
        if ($sid === '' || isset($already[$sid])) {
            continue;
        }
        $bySubject[$sid][] = $row;
    }

    $out = [];
    foreach ($bySubject as $sid => $rows) {
        $offerings = listTbfSubjectOfferings($sid, $academicYear, $semester);
        $usable = [];
        foreach ($offerings as $offering) {
            if (facultyCanTakeOffering($facultyId, $offering, $academicYear, $semester)) {
                $usable[] = $offering;
            }
        }
        if ($usable === []) {
            continue;
        }
        $subject = fetchSubjectById($sid);
        if ($subject === null) {
            continue;
        }
        // Prefer subjects owned by / serving this department.
        $owner = (string) ($subject['departmentId'] ?? '');
        $serves = (string) ($subject['servingDepartmentId'] ?? '');
        if ($owner !== $departmentId && $serves !== $departmentId) {
            continue;
        }
        $out[] = [
            'subject' => $subject,
            'offerings' => $usable,
            'availableCount' => count($usable),
        ];
    }

    return $out;
}

/**
 * Pick one random available subject for the faculty.
 *
 * @param list<array{subject:array<string,mixed>,offerings:list<array<string,mixed>>,availableCount:int}> $candidates
 * @return array{subject:array<string,mixed>,offerings:list<array<string,mixed>>,availableCount:int}|null
 */
function pickRandomAvailableSubjectForLoad(array $candidates): ?array
{
    if ($candidates === []) {
        return null;
    }
    $idx = random_int(0, count($candidates) - 1);

    return $candidates[$idx];
}

/**
 * Preview / apply load assignment from a parsed command for a selected faculty.
 *
 * @param array<string,mixed> $parsed
 * @param list<string>|null $selectedOfferingKeys When set, assign these offerings instead of the suggestion.
 * @return array<string,mixed>
 */
function planFacultyLoadAssignment(
    array $parsed,
    string $departmentId,
    bool $apply = false,
    ?string $facultyId = null,
    ?array $selectedOfferingKeys = null
): array {
    if (!($parsed['ready'] ?? false)) {
        throw new InvalidArgumentException(
            'Command is incomplete. Missing: ' . implode(', ', $parsed['missing'] ?? [])
        );
    }

    $faculty = listActiveFacultyForDepartment($departmentId);
    if ($faculty === []) {
        throw new InvalidArgumentException('No active faculty in this department.');
    }

    $selected = null;
    $facultyId = trim((string) ($facultyId ?? ''));
    if ($facultyId !== '') {
        foreach ($faculty as $fac) {
            if ((string) $fac['uid'] === $facultyId) {
                $selected = $fac;
                break;
            }
        }
        if ($selected === null) {
            throw new InvalidArgumentException('Selected faculty was not found in your department.');
        }
    } elseif (trim((string) ($parsed['facultyHint'] ?? '')) !== '') {
        $selected = resolveFacultyHint((string) $parsed['facultyHint'], $faculty);
        if ($selected === null) {
            throw new InvalidArgumentException('Faculty not found: ' . $parsed['facultyHint']);
        }
    } else {
        throw new InvalidArgumentException('Select a faculty before parsing the load command.');
    }

    $minLoad = (float) ($selected['minLoad'] ?? FACULTY_MIN_LOAD_REGULAR);
    $employmentType = (string) ($selected['employmentType'] ?? 'Regular');
    $targetLoad = (float) $parsed['targetLoad'];

    $term = currentTermWindow();
    $academicYear = (int) $term['academicYear'];
    $semester = (string) $term['semester'];
    $currentLoad = currentFacultyLoadForTerm((string) $selected['uid'], $academicYear, $semester);
    $subjectPickedRandomly = false;

    $subjectCode = trim((string) ($parsed['subjectCode'] ?? ''));
    /** @var list<array<string,mixed>> $allOfferings */
    $allOfferings = [];
    /** @var list<array{subject:array<string,mixed>,offerings:list<array<string,mixed>>,availableCount:int}> $subjectCandidates */
    $subjectCandidates = [];
    $primarySubject = null;

    if ($subjectCode !== '') {
        $primarySubject = findSubjectByCodeForLoad($subjectCode, $departmentId);
        if ($primarySubject === null) {
            throw new InvalidArgumentException('Subject not found: ' . $subjectCode);
        }
        $rawOfferings = listTbfSubjectOfferings((string) $primarySubject['uid'], $academicYear, $semester);
        foreach ($rawOfferings as $offering) {
            $enriched = enrichOfferingForLoadPreview($offering, $primarySubject);
            if (facultyCanTakeOffering((string) $selected['uid'], $offering, $academicYear, $semester)) {
                $allOfferings[] = $enriched;
            }
        }
        if ($allOfferings === []) {
            throw new InvalidArgumentException(
                'No TBF offerings of ' . $primarySubject['code']
                . ' are available for ' . $selected['fullName']
                . ' (none left unassigned, or time conflicts).'
            );
        }
    } else {
        $subjectCandidates = listAvailableSubjectsForFacultyLoad(
            (string) $selected['uid'],
            $departmentId,
            $academicYear,
            $semester,
            true
        );
        if ($subjectCandidates === []) {
            $subjectCandidates = listAvailableSubjectsForFacultyLoad(
                (string) $selected['uid'],
                $departmentId,
                $academicYear,
                $semester,
                false
            );
        }
        if ($subjectCandidates === []) {
            throw new InvalidArgumentException(
                'No undistributed (TBF) subjects are available for ' . $selected['fullName']
                . ' without time conflicts.'
            );
        }
        foreach ($subjectCandidates as $candidate) {
            $subj = $candidate['subject'];
            foreach ($candidate['offerings'] as $offering) {
                $allOfferings[] = enrichOfferingForLoadPreview($offering, $subj);
            }
        }
        $subjectPickedRandomly = true;
    }

    $suggestion = suggestOfferingsForLoadTarget(
        $allOfferings,
        $targetLoad,
        (string) $selected['uid'],
        $academicYear,
        $semester
    );
    $suggestedKeys = $suggestion['suggestedKeys'];
    foreach ($allOfferings as $i => $offering) {
        $allOfferings[$i]['suggested'] = in_array((string) ($offering['offeringKey'] ?? ''), $suggestedKeys, true);
    }

    $chosenOfferings = resolveSelectedOfferings($allOfferings, $selectedOfferingKeys);
    if ($chosenOfferings === [] && $suggestion['selected'] !== []) {
        $chosenOfferings = $suggestion['selected'];
    }

    if ($primarySubject === null && $chosenOfferings !== []) {
        $firstCode = trim((string) ($chosenOfferings[0]['subjectCode'] ?? ''));
        if ($firstCode !== '') {
            $primarySubject = findSubjectByCodeForLoad($firstCode, $departmentId);
            $parsed['subjectCode'] = $firstCode;
        }
    } elseif ($primarySubject !== null) {
        $parsed['subjectCode'] = (string) $primarySubject['code'];
    }

    if ($primarySubject === null) {
        throw new InvalidArgumentException('Could not resolve a subject for this load assignment.');
    }

    $loadPer = subjectTeachingLoadFromHours(
        (float) ($primarySubject['lectureHours'] ?? 0),
        (float) ($primarySubject['labHours'] ?? 0)
    );
    if ($loadPer <= 0 && $allOfferings !== []) {
        $loadPer = (float) ($allOfferings[0]['load'] ?? 0);
    }
    if ($loadPer <= 0) {
        throw new InvalidArgumentException(
            'Subject ' . $primarySubject['code'] . ' has no lecture/lab hours to compute load (hours ÷ 3).'
        );
    }

    $avgOfferingLoad = 0.0;
    if ($allOfferings !== []) {
        $sum = 0.0;
        foreach ($allOfferings as $o) {
            $sum += (float) ($o['load'] ?? 0);
        }
        $avgOfferingLoad = $sum / count($allOfferings);
    }
    $referenceLoad = $avgOfferingLoad > 0 ? $avgOfferingLoad : $loadPer;
    $offeringsNeeded = (int) max(1, (int) ceil($targetLoad / $referenceLoad - 1e-9));

    if ($chosenOfferings === []) {
        throw new InvalidArgumentException(
            'Could not suggest any non-overlapping offerings for '
            . $selected['fullName'] . ' to reach ' . number_format($targetLoad, 2) . ' load.'
            . ($subjectCode !== ''
                ? ' Review available ' . $primarySubject['code'] . ' schedules below.'
                : '')
        );
    }

    $assignedLoad = 0.0;
    $assignedContactHours = 0.0;
    foreach ($chosenOfferings as $offering) {
        $assignedLoad += (float) ($offering['load'] ?? 0);
        $assignedContactHours += (float) ($offering['contactHours'] ?? 0);
    }
    $assignedLoad = round($assignedLoad, 4);
    $assignedContactHours = round($assignedContactHours, 2);
    $assignments = buildLoadAssignmentsFromOfferings($chosenOfferings, $selected);

    $applied = false;
    $updatedMeetings = 0;
    if ($apply) {
        foreach ($assignments as $a) {
            $updatedMeetings += assignFacultyToScheduleIds($a['scheduleIds'], $a['facultyId']);
        }
        $applied = true;
    }

    $subjectCodesInPick = [];
    foreach ($chosenOfferings as $o) {
        $c = trim((string) ($o['subjectCode'] ?? ''));
        if ($c !== '') {
            $subjectCodesInPick[$c] = true;
        }
    }
    $subjectLabel = count($subjectCodesInPick) === 1
        ? $primarySubject['code']
        : implode(', ', array_keys($subjectCodesInPick));

    return [
        'subject' => [
            'uid' => $primarySubject['uid'],
            'code' => $primarySubject['code'],
            'title' => $primarySubject['title'],
            'lectureHours' => (float) $primarySubject['lectureHours'],
            'labHours' => (float) $primarySubject['labHours'],
            'loadPerOffering' => round($referenceLoad, 4),
            'loadFormula' => sprintf(
                '(%s + %s) ÷ 3 = %s (catalog); each offering uses scheduled contact hours ÷ 3',
                rtrim(rtrim(number_format((float) $primarySubject['lectureHours'], 2), '0'), '.'),
                rtrim(rtrim(number_format((float) $primarySubject['labHours'], 2), '0'), '.'),
                rtrim(rtrim(number_format($loadPer, 4), '0'), '.')
            ),
            'pickedRandomly' => $subjectPickedRandomly && $subjectCode === '',
            'label' => $subjectLabel,
        ],
        'faculty' => [
            'uid' => $selected['uid'],
            'fullName' => $selected['fullName'],
            'employmentType' => $employmentType,
            'minLoad' => $minLoad,
            'currentLoad' => $currentLoad['load'],
            'currentContactHours' => $currentLoad['contactHours'],
        ],
        'targetLoad' => $targetLoad,
        'offeringsNeeded' => $offeringsNeeded,
        'availableOfferings' => count($allOfferings),
        'allOfferings' => $allOfferings,
        'subjectCandidates' => array_map(static function (array $c): array {
            return [
                'subject' => [
                    'uid' => $c['subject']['uid'] ?? '',
                    'code' => $c['subject']['code'] ?? '',
                    'title' => $c['subject']['title'] ?? '',
                ],
                'availableCount' => (int) ($c['availableCount'] ?? 0),
            ];
        }, $subjectCandidates),
        'suggestedOfferingKeys' => $suggestedKeys,
        'assignedLoad' => round($assignedLoad, 2),
        'assignedContactHours' => $assignedContactHours,
        'loadTotal' => [
            'targetLoad' => $targetLoad,
            'suggestedLoad' => round((float) $suggestion['assignedLoad'], 2),
            'selectedLoad' => round($assignedLoad, 2),
            'selectedContactHours' => $assignedContactHours,
            'selectedLoadFormula' => $assignedContactHours > 0
                ? sprintf('%s h ÷ 3 = %s', rtrim(rtrim(number_format($assignedContactHours, 2), '0'), '.'), rtrim(rtrim(number_format($assignedLoad, 4), '0'), '.'))
                : '0 h ÷ 3 = 0',
            'afterAssignLoad' => round($currentLoad['load'] + $assignedLoad, 2),
            'formula' => 'total load = contact hours ÷ 3',
        ],
        'assignments' => $assignments,
        'applied' => $applied,
        'updatedMeetings' => $updatedMeetings,
        'confirmSummary' => sprintf(
            'Assign %.2f load (%s) to %s: pick %d of %d available offering(s). Suggested total = %.2f load (%.2f h ÷ 3). Current load %.2f → %.2f after assign.',
            $targetLoad,
            $subjectLabel,
            $selected['fullName'],
            count($chosenOfferings),
            count($allOfferings),
            (float) $suggestion['assignedLoad'],
            (float) $suggestion['assignedContactHours'],
            $currentLoad['load'],
            round($currentLoad['load'] + $assignedLoad, 2)
        ),
        'parsed' => $parsed,
    ];
}
