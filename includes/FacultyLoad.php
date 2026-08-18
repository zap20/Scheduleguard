<?php

declare(strict_types=1);

/**
 * Natural-language faculty load assignment (Dean selects faculty first).
 *
 * Examples (with facultyId from UI):
 *   "8 loads"              → pick a random subject still TBF / available to that faculty
 *   "8 loads CICT-ATT"      → use that specific subject
 *   "3 loads 5pm up"          → only offerings at 5:00 PM or later
 *   "3 loads 7 am to 9 am"    → only offerings within 7:00–9:00 AM
 *   "8 load CICT-ATT"
 *   "CICT-ATT 8 loads"
 *
 * Load per subject offering = (lectureHours + labHours) / 3.
 */

require_once __DIR__ . '/Schedule.php';
require_once __DIR__ . '/Subject.php';
require_once __DIR__ . '/Term.php';

/** Stay below the next whole load (8.33 / 8.67 OK for an 8 target; never 9+). */
const FACULTY_LOAD_TOTAL_CEILING_EPSILON = 0.999;

function buildLoadOfferingKey(string $subjectId, string $blockKey): string
{
    $subjectId = trim($subjectId);
    $blockKey = trim($blockKey);
    if ($subjectId === '' && $blockKey === '') {
        return 'offering';
    }
    if ($subjectId === '') {
        return $blockKey;
    }
    if ($blockKey === '') {
        return $subjectId . '::offering';
    }

    return $subjectId . '::' . $blockKey;
}

function resolveLoadOfferingKey(?array $subject, array $offering): string
{
    $rawKey = trim((string) ($offering['key'] ?? ''));
    $subjectId = $subject !== null ? trim((string) ($subject['uid'] ?? '')) : '';
    if ($rawKey === '') {
        return buildLoadOfferingKey($subjectId, 'offering');
    }
    if ($subjectId !== '' && str_starts_with($rawKey, $subjectId . '::')) {
        return $rawKey;
    }

    return buildLoadOfferingKey($subjectId, $rawKey);
}

function maxTotalLoadForAssignment(
    float $targetTotal,
    float $currentLoad,
    string $loadMode,
    float $loadGap
): float {
    if ($loadMode === 'incremental') {
        return $currentLoad + $loadGap + FACULTY_LOAD_TOTAL_CEILING_EPSILON;
    }

    return $targetTotal + FACULTY_LOAD_TOTAL_CEILING_EPSILON;
}

/**
 * @param list<array<string,mixed>> $offerings
 * @return list<array<string,mixed>>
 */
function trimSelectedOfferingsToCap(array $offerings, float $currentLoad, float $maxTotalLoad): array
{
    $selected = array_values($offerings);
    while ($selected !== []) {
        $sum = 0.0;
        foreach ($selected as $o) {
            $sum += (float) ($o['load'] ?? 0);
        }
        if ($currentLoad + $sum <= $maxTotalLoad + 1e-9) {
            break;
        }
        array_pop($selected);
    }

    return $selected;
}

/**
 * Pick non-overlapping offerings whose load sum falls in [minAssign, maxAssign].
 * Prefers the combination closest to maxAssign (fills toward 8.99 for an 8 target).
 *
 * @param list<array<string,mixed>> $offerings
 * @return array{selected:list<array<string,mixed>>,assignedLoad:float,assignedContactHours:float,suggestedKeys:list<string>}
 */
function assertLoadAssignmentWithinCap(
    float $currentLoad,
    float $assignedLoad,
    string $loadMode,
    float $targetTotal
): void {
    $after = round($currentLoad + $assignedLoad, 4);
    if ($loadMode !== 'total') {
        return;
    }
    $ceiling = $targetTotal + 1.0;
    if ($after >= $ceiling - 1e-9) {
        throw new InvalidArgumentException(
            sprintf(
                'Selected load would total %.2f. For this command the maximum is below %.2f (e.g. 8.33 or 8.67 for an 8 load target). Use a smaller add-on command to go above that.',
                $after,
                $ceiling
            )
        );
    }
}

/**
 * Unassigned (TBF) teaching load for a department this term.
 *
 * @return array{totalLoad:float,contactHours:float,offeringCount:int,meetingCount:int,subjectCount:int}
 */
function computeDepartmentTbfLoadSummary(?string $departmentId): array
{
    $rows = fetchDeanFacultySchedules(SCHEDULE_INSTRUCTOR_TBF, null, $departmentId);
    $computed = computeFacultyTeachingLoad($rows);

    return [
        'totalLoad' => (float) ($computed['load'] ?? 0),
        'contactHours' => (float) ($computed['contactHours'] ?? 0),
        'offeringCount' => (int) ($computed['offeringCount'] ?? 0),
        'meetingCount' => count($rows),
        'subjectCount' => (int) ($computed['subjectCount'] ?? 0),
    ];
}

/**
 * Faculty in a department with zero teaching load this term.
 *
 * @return array{totalFaculty:int,noLoadCount:int,withLoadCount:int}
 */
function computeDepartmentFacultyNoLoadSummary(?string $departmentId): array
{
    if ($departmentId === null || trim($departmentId) === '') {
        return [
            'totalFaculty' => 0,
            'noLoadCount' => 0,
            'withLoadCount' => 0,
        ];
    }

    $term = currentTermWindow();
    $academicYear = (int) $term['academicYear'];
    $semester = (string) $term['semester'];
    $faculty = listActiveFacultyForDepartment($departmentId);
    $noLoadCount = 0;
    foreach ($faculty as $fac) {
        $load = currentFacultyLoadForTerm((string) $fac['uid'], $academicYear, $semester);
        if ((float) ($load['load'] ?? 0) <= 0.001) {
            $noLoadCount++;
        }
    }
    $total = count($faculty);

    return [
        'totalFaculty' => $total,
        'noLoadCount' => $noLoadCount,
        'withLoadCount' => max(0, $total - $noLoadCount),
    ];
}

function pickOfferingsInLoadWindow(
    array $offerings,
    float $minAssign,
    float $maxAssign,
    string $facultyId,
    int $academicYear,
    string $semester
): array {
    $empty = [
        'selected' => [],
        'assignedLoad' => 0.0,
        'assignedContactHours' => 0.0,
        'suggestedKeys' => [],
    ];
    if ($offerings === [] || $minAssign <= 0 || $maxAssign + 1e-9 < $minAssign) {
        return $empty;
    }

    $pool = [];
    foreach ($offerings as $offering) {
        $piece = (float) ($offering['load'] ?? 0);
        if ($piece <= 0 || $piece > $maxAssign + 1e-9) {
            continue;
        }
        $rawOffering = [
            'scheduleIds' => $offering['scheduleIds'] ?? [],
            'rows' => $offering['rows'] ?? [],
        ];
        if (!facultyCanTakeOffering($facultyId, $rawOffering, $academicYear, $semester)) {
            continue;
        }
        $pool[] = $offering;
    }
    if ($pool === []) {
        return $empty;
    }

    usort($pool, static function (array $a, array $b): int {
        $loadCmp = ($b['load'] ?? 0) <=> ($a['load'] ?? 0);
        if ($loadCmp !== 0) {
            return $loadCmp;
        }

        return strcmp((string) ($a['blockLabel'] ?? ''), (string) ($b['blockLabel'] ?? ''));
    });
    if (count($pool) > 16) {
        $pool = array_slice($pool, 0, 16);
    }

    $loads = array_map(static fn (array $o): float => (float) ($o['load'] ?? 0), $pool);
    $suffixMax = array_fill(0, count($pool) + 1, 0.0);
    for ($i = count($pool) - 1; $i >= 0; $i--) {
        $suffixMax[$i] = $suffixMax[$i + 1] + $loads[$i];
    }

    $bestSelected = [];
    $bestLoad = -1.0;
    $nodes = 0;
    $nodeBudget = 8000;

    $walk = function (
        int $idx,
        array $selected,
        float $sum,
        array $busy
    ) use (
        &$walk,
        &$bestSelected,
        &$bestLoad,
        &$nodes,
        $nodeBudget,
        $pool,
        $suffixMax,
        $minAssign,
        $maxAssign,
        $facultyId,
        $academicYear,
        $semester
    ): void {
        if ($nodes++ > $nodeBudget) {
            return;
        }
        if ($sum >= $minAssign - 1e-9 && $sum <= $maxAssign + 1e-9 && $sum > $bestLoad + 1e-9) {
            $bestLoad = $sum;
            $bestSelected = $selected;
        }
        if ($idx >= count($pool) || $sum > $maxAssign + 1e-9) {
            return;
        }
        if ($sum + $suffixMax[$idx] < $minAssign - 1e-9) {
            return;
        }
        if ($bestLoad >= $maxAssign - 1e-9) {
            return;
        }

        $walk($idx + 1, $selected, $sum, $busy);

        $offering = $pool[$idx];
        $piece = (float) ($offering['load'] ?? 0);
        if ($sum + $piece > $maxAssign + 1e-9) {
            return;
        }
        $rawOffering = [
            'scheduleIds' => $offering['scheduleIds'] ?? [],
            'rows' => $offering['rows'] ?? [],
        ];
        if (!facultyCanTakeOffering($facultyId, $rawOffering, $academicYear, $semester, $busy)) {
            return;
        }
        $newBusy = $busy;
        foreach ($offering['rows'] ?? [] as $row) {
            $newBusy[] = [
                'day' => (string) ($row['day'] ?? ''),
                'startTime' => (string) ($row['startTime'] ?? ''),
                'endTime' => (string) ($row['endTime'] ?? ''),
            ];
        }
        $selected[] = $offering;
        $walk($idx + 1, $selected, $sum + $piece, $newBusy);
    };

    $walk(0, [], 0.0, []);

    if ($bestSelected === []) {
        return $empty;
    }

    $assignedLoad = 0.0;
    $assignedContactHours = 0.0;
    foreach ($bestSelected as $offering) {
        $assignedLoad += (float) ($offering['load'] ?? 0);
        $assignedContactHours += (float) ($offering['contactHours'] ?? 0);
    }

    return [
        'selected' => $bestSelected,
        'assignedLoad' => round($assignedLoad, 4),
        'assignedContactHours' => round($assignedContactHours, 2),
        'suggestedKeys' => array_map(static fn (array $o): string => (string) ($o['offeringKey'] ?? ''), $bestSelected),
    ];
}

function loadCommandTimeToMinutes(string $hhmm): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', trim($hhmm), $m)) {
        return null;
    }
    $hour = (int) $m[1];
    $minute = (int) $m[2];
    if ($hour > 23 || $minute > 59) {
        return null;
    }

    return $hour * 60 + $minute;
}

/**
 * Parse "7 am", "5pm", "17:00", "7:30 PM" into minutes from midnight.
 */
function parseLoadCommandTimeToken(string $token, ?string $inheritAmPm = null): ?int
{
    $token = strtolower(trim(preg_replace('/\s+/', ' ', $token) ?? ''));
    if ($token === '') {
        return null;
    }

    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $token, $m)) {
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return $hour * 60 + $minute;
    }

    if (!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/', $token, $m)) {
        return null;
    }

    $hour = (int) $m[1];
    $minute = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
    $ampm = $m[3] ?? '';
    if ($ampm === '' && $inheritAmPm !== null && $inheritAmPm !== '') {
        $ampm = $inheritAmPm;
    }
    if ($ampm === 'pm' && $hour < 12) {
        $hour += 12;
    } elseif ($ampm === 'am' && $hour === 12) {
        $hour = 0;
    } elseif ($ampm === '' && $hour >= 1 && $hour <= 11) {
        $hour = $hour === 12 ? 0 : $hour;
    } elseif ($ampm === '' && $hour === 12) {
        $hour = 12;
    }

    if ($hour > 23 || $minute > 59) {
        return null;
    }

    return $hour * 60 + $minute;
}

function formatLoadCommandTimeMinutes(?int $minutes): string
{
    if ($minutes === null) {
        return '';
    }
    $h24 = intdiv($minutes, 60);
    $min = $minutes % 60;
    $suffix = $h24 >= 12 ? 'PM' : 'AM';
    $h12 = $h24 % 12 === 0 ? 12 : $h24 % 12;

    return $h12 . ':' . sprintf('%02d', $min) . ' ' . $suffix;
}

function formatLoadCommandTimeFilterLabel(?int $fromMin, ?int $toMin): string
{
    if ($fromMin === null && $toMin === null) {
        return '';
    }
    if ($toMin === null && $fromMin !== null) {
        return formatLoadCommandTimeMinutes($fromMin) . ' and later';
    }
    if ($fromMin !== null && $toMin !== null) {
        return formatLoadCommandTimeMinutes($fromMin) . '–' . formatLoadCommandTimeMinutes($toMin);
    }

    return '';
}

/**
 * Strip trailing time window from a load command ("5pm up", "7 am to 9 am").
 *
 * @return array{timeFromMinutes:?int,timeToMinutes:?int,timeLabel:string}
 */
function extractLoadCommandTimeFilter(string &$text): array
{
    $timeFrom = null;
    $timeTo = null;
    $timeLabel = '';

    if (preg_match(
        '/\b(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*(?:to|-)\s*(\d{1,2}(?::\d{2})?\s*(?:am|pm)?)\s*$/i',
        $text,
        $m
    )) {
        $firstAmPm = preg_match('/(am|pm)$/i', trim($m[1]), $ap) ? strtolower($ap[1]) : null;
        $from = parseLoadCommandTimeToken(trim($m[1]));
        $to = parseLoadCommandTimeToken(trim($m[2]), $firstAmPm);
        if ($from !== null && $to !== null && $to > $from) {
            $timeFrom = $from;
            $timeTo = $to;
            $timeLabel = formatLoadCommandTimeFilterLabel($from, $to);
            $text = trim(substr($text, 0, -strlen($m[0])));
        }
    } elseif (preg_match(
        '/\b(?:from\s+)?(\d{1,2}(?::\d{2})?\s*(?:am|pm))\s+up\s*$/i',
        $text,
        $m
    )) {
        $from = parseLoadCommandTimeToken(trim($m[1]));
        if ($from !== null) {
            $timeFrom = $from;
            $timeTo = null;
            $timeLabel = formatLoadCommandTimeFilterLabel($from, null);
            $text = trim(substr($text, 0, -strlen($m[0])));
        }
    }

    return [
        'timeFromMinutes' => $timeFrom,
        'timeToMinutes' => $timeTo,
        'timeLabel' => $timeLabel,
    ];
}

/**
 * True when every meeting in the offering fits the optional time window.
 *
 * @param array<string,mixed> $offering
 */
function offeringMatchesLoadTimeFilter(array $offering, ?int $fromMin, ?int $toMin): bool
{
    if ($fromMin === null && $toMin === null) {
        return true;
    }
    $rows = $offering['rows'] ?? [];
    if ($rows === []) {
        return false;
    }
    foreach ($rows as $row) {
        $start = loadCommandTimeToMinutes(substr((string) ($row['startTime'] ?? ''), 0, 5));
        $end = loadCommandTimeToMinutes(substr((string) ($row['endTime'] ?? ''), 0, 5));
        if ($start === null || $end === null || $end <= $start) {
            return false;
        }
        if ($fromMin !== null && $start < $fromMin) {
            return false;
        }
        if ($toMin !== null && $end > $toMin) {
            return false;
        }
    }

    return true;
}

/**
 * @param list<array<string,mixed>> $offerings
 * @return list<array<string,mixed>>
 */
function filterOfferingsByLoadTimeWindow(array $offerings, ?int $fromMin, ?int $toMin): array
{
    if ($fromMin === null && $toMin === null) {
        return $offerings;
    }

    return array_values(array_filter(
        $offerings,
        static fn (array $offering): bool => offeringMatchesLoadTimeFilter($offering, $fromMin, $toMin)
    ));
}

/**
 * Greedy pick for incremental add-on commands (no strict total window).
 *
 * @param list<array<string,mixed>> $offerings
 * @return array{selected:list<array<string,mixed>>,assignedLoad:float,assignedContactHours:float,suggestedKeys:list<string>}
 */
function parseFacultyLoadCommand(string $raw): array
{
    $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
    $missing = [];
    $targetLoad = null;
    $subjectCode = '';
    $facultyHint = '';

    $text = $raw;
    $timeFilter = extractLoadCommandTimeFilter($text);
    $timeFromMinutes = $timeFilter['timeFromMinutes'];
    $timeToMinutes = $timeFilter['timeToMinutes'];
    $timeFilterLabel = (string) ($timeFilter['timeLabel'] ?? '');

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
    $timeSuffix = $timeFilterLabel !== '' ? ' during ' . $timeFilterLabel : '';
    $summary = $ready
        ? (
            $subjectSpecified
                ? sprintf('Assign %.2f load of %s%s — confirm?', $targetLoad, $subjectCode, $timeSuffix)
                : sprintf(
                    'Assign %.2f load%s — pick a random subject still available (TBF) for the selected faculty — confirm?',
                    $targetLoad,
                    $timeSuffix
                )
        )
        : 'Could not parse load command. Try "8 loads", "8 loads CICT-ATT", "3 loads 5pm up", or "3 loads 7 am to 9 am".';

    return [
        'rawCommand' => $raw,
        'targetLoad' => $targetLoad ?? 0.0,
        'subjectCode' => $subjectCode,
        'subjectSpecified' => $subjectSpecified,
        'facultyHint' => $facultyHint,
        'timeFromMinutes' => $timeFromMinutes,
        'timeToMinutes' => $timeToMinutes,
        'timeFilterLabel' => $timeFilterLabel,
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
        $groupKey = buildLoadOfferingKey($subjectId, $blockKey);
        if (!isset($groups[$groupKey])) {
            $groups[$groupKey] = [
                'key' => $groupKey,
                'label' => trim((string) ($row['blockName'] ?? '')) !== ''
                    ? (string) $row['blockName']
                    : 'Offering',
                'scheduleIds' => [],
                'rows' => [],
                'load' => (float) ($row['subjectLoad'] ?? 0),
            ];
        }
        $groups[$groupKey]['scheduleIds'][] = (string) $row['uid'];
        $groups[$groupKey]['rows'][] = $row;
        if ($groups[$groupKey]['load'] <= 0) {
            $groups[$groupKey]['load'] = subjectTeachingLoadFromHours(
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
        'offeringKey' => resolveLoadOfferingKey($subject, $offering),
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
/**
 * Greedy pick for incremental add-on commands (no strict total window).
 *
 * @param list<array<string,mixed>> $offerings
 * @return array{selected:list<array<string,mixed>>,assignedLoad:float,assignedContactHours:float,suggestedKeys:list<string>}
 */
function pickOfferingsGreedyForGap(
    array $offerings,
    float $loadGap,
    string $facultyId,
    int $academicYear,
    string $semester
): array {
    $sorted = $offerings;
    usort($sorted, static function (array $a, array $b): int {
        $loadCmp = ($b['load'] ?? 0) <=> ($a['load'] ?? 0);
        if ($loadCmp !== 0) {
            return $loadCmp;
        }

        return strcmp((string) ($a['blockLabel'] ?? ''), (string) ($b['blockLabel'] ?? ''));
    });

    $pendingBusy = [];
    $selected = [];
    $assignedLoad = 0.0;
    $assignedContactHours = 0.0;

    foreach ($sorted as $offering) {
        if ($assignedLoad + 1e-9 >= $loadGap) {
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
        foreach ($offering['rows'] ?? [] as $row) {
            $pendingBusy[] = [
                'day' => (string) ($row['day'] ?? ''),
                'startTime' => (string) ($row['startTime'] ?? ''),
                'endTime' => (string) ($row['endTime'] ?? ''),
            ];
        }
    }

    return [
        'selected' => $selected,
        'assignedLoad' => round($assignedLoad, 4),
        'assignedContactHours' => round($assignedContactHours, 2),
        'suggestedKeys' => array_map(static fn (array $o): string => (string) ($o['offeringKey'] ?? ''), $selected),
    ];
}

/**
 * Fast greedy pick targeting a total-load window (e.g. 8.00–8.99).
 *
 * @param list<array<string,mixed>> $offerings
 * @return array{selected:list<array<string,mixed>>,assignedLoad:float,assignedContactHours:float,suggestedKeys:list<string>}
 */
function pickOfferingsGreedyForWindow(
    array $offerings,
    float $minAssign,
    float $maxAssign,
    string $facultyId,
    int $academicYear,
    string $semester
): array {
    $empty = [
        'selected' => [],
        'assignedLoad' => 0.0,
        'assignedContactHours' => 0.0,
        'suggestedKeys' => [],
    ];
    if ($offerings === [] || $minAssign <= 0 || $maxAssign + 1e-9 < $minAssign) {
        return $empty;
    }

    $sorted = $offerings;
    usort($sorted, static function (array $a, array $b): int {
        $loadCmp = ($b['load'] ?? 0) <=> ($a['load'] ?? 0);
        if ($loadCmp !== 0) {
            return $loadCmp;
        }

        return strcmp((string) ($a['blockLabel'] ?? ''), (string) ($b['blockLabel'] ?? ''));
    });

    $bestSelected = [];
    $bestLoad = 0.0;
    $bestContact = 0.0;
    $pendingBusy = [];
    $selected = [];
    $sum = 0.0;
    $contact = 0.0;

    foreach ($sorted as $offering) {
        $piece = (float) ($offering['load'] ?? 0);
        if ($piece <= 0 || $sum + $piece > $maxAssign + 1e-9) {
            continue;
        }
        $rawOffering = [
            'scheduleIds' => $offering['scheduleIds'] ?? [],
            'rows' => $offering['rows'] ?? [],
        ];
        if (!facultyCanTakeOffering($facultyId, $rawOffering, $academicYear, $semester, $pendingBusy)) {
            continue;
        }
        $selected[] = $offering;
        $sum += $piece;
        $contact += (float) ($offering['contactHours'] ?? 0);
        foreach ($offering['rows'] ?? [] as $row) {
            $pendingBusy[] = [
                'day' => (string) ($row['day'] ?? ''),
                'startTime' => (string) ($row['startTime'] ?? ''),
                'endTime' => (string) ($row['endTime'] ?? ''),
            ];
        }
        if ($sum >= $minAssign - 1e-9 && $sum <= $maxAssign + 1e-9 && $sum > $bestLoad + 1e-9) {
            $bestLoad = $sum;
            $bestSelected = $selected;
            $bestContact = $contact;
        }
    }

    if ($bestSelected === []) {
        return $empty;
    }

    return [
        'selected' => $bestSelected,
        'assignedLoad' => round($bestLoad, 4),
        'assignedContactHours' => round($bestContact, 2),
        'suggestedKeys' => array_map(static fn (array $o): string => (string) ($o['offeringKey'] ?? ''), $bestSelected),
    ];
}

/**
 * Pick offerings for a load command. Total-mode commands target the 8–8.99 window.
 *
 * @param list<array<string,mixed>> $offerings
 * @return array{selected:list<array<string,mixed>>,assignedLoad:float,assignedContactHours:float,suggestedKeys:list<string>}
 */
function suggestOfferingsForLoadTarget(
    array $offerings,
    float $loadGap,
    string $facultyId,
    int $academicYear,
    string $semester,
    float $currentLoad = 0.0,
    ?float $maxTotalLoad = null
): array {
    if ($offerings === [] || $loadGap <= 0) {
        return [
            'selected' => [],
            'assignedLoad' => 0.0,
            'assignedContactHours' => 0.0,
            'suggestedKeys' => [],
        ];
    }

    if ($maxTotalLoad === null) {
        return pickOfferingsGreedyForGap($offerings, $loadGap, $facultyId, $academicYear, $semester);
    }

    $minAssign = $loadGap;
    $maxAssign = max(0.0, round($maxTotalLoad - $currentLoad, 4));
    if ($maxAssign + 1e-9 < $minAssign) {
        return [
            'selected' => [],
            'assignedLoad' => 0.0,
            'assignedContactHours' => 0.0,
            'suggestedKeys' => [],
        ];
    }

    $greedy = pickOfferingsGreedyForWindow(
        $offerings,
        $minAssign,
        $maxAssign,
        $facultyId,
        $academicYear,
        $semester
    );
    if ($greedy['assignedLoad'] + 1e-9 >= $minAssign) {
        return $greedy;
    }

    $full = pickOfferingsInLoadWindow(
        $offerings,
        $minAssign,
        $maxAssign,
        $facultyId,
        $academicYear,
        $semester
    );
    if ($full['assignedLoad'] + 1e-9 >= $minAssign) {
        return $full;
    }

    $best = $greedy['assignedLoad'] >= $full['assignedLoad'] ? $greedy : $full;

    $bySubject = [];
    foreach ($offerings as $offering) {
        $key = trim((string) ($offering['subjectId'] ?? ''));
        if ($key === '') {
            $key = trim((string) ($offering['subjectCode'] ?? 'unknown'));
        }
        $bySubject[$key][] = $offering;
    }
    if (count($bySubject) <= 1) {
        return $best;
    }

    $groups = array_values($bySubject);
    usort($groups, static function (array $a, array $b): int {
        $sumA = 0.0;
        foreach ($a as $o) {
            $sumA += (float) ($o['load'] ?? 0);
        }
        $sumB = 0.0;
        foreach ($b as $o) {
            $sumB += (float) ($o['load'] ?? 0);
        }

        return $sumB <=> $sumA;
    });

    foreach (array_slice($groups, 0, 6) as $group) {
        $try = pickOfferingsInLoadWindow(
            $group,
            $minAssign,
            $maxAssign,
            $facultyId,
            $academicYear,
            $semester
        );
        if ($try['assignedLoad'] + 1e-9 >= $minAssign) {
            return $try;
        }
        if ($try['assignedLoad'] > $best['assignedLoad'] + 1e-9) {
            $best = $try;
        }
    }

    return $best;
}

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
    $seen = [];
    foreach ($allOfferings as $offering) {
        $key = (string) ($offering['offeringKey'] ?? '');
        if (!isset($want[$key]) || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $selected[] = $offering;
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
 * Remove one faculty meeting from load (returns that single slot to TBF).
 *
 * @return array{
 *   subjectCode:string,
 *   subjectName:string,
 *   blockName:string,
 *   facultyId:string,
 *   facultyName:string,
 *   scheduleIds:list<string>,
 *   updatedMeetings:int,
 *   day:string,
 *   startTime:string,
 *   endTime:string
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

    $blockName = trim((string) ($seed['blockName'] ?? ''));
    $scheduleIds = [(string) $seed['uid']];
    $updated = unassignFacultyFromScheduleIds($scheduleIds);

    return [
        'subjectCode' => (string) ($seed['subjectCode'] ?? ''),
        'subjectName' => (string) ($seed['subjectName'] ?? ''),
        'blockName' => $blockName !== '' ? $blockName : 'Offering',
        'facultyId' => $facultyId,
        'facultyName' => (string) ($seed['facultyName'] ?? $seed['instructor'] ?? ''),
        'scheduleIds' => $scheduleIds,
        'updatedMeetings' => $updated,
        'day' => (string) ($seed['day'] ?? ''),
        'startTime' => substr((string) ($seed['startTime'] ?? ''), 0, 5),
        'endTime' => substr((string) ($seed['endTime'] ?? ''), 0, 5),
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
    return offeringConflictReason($facultyId, $offering, $academicYear, $semester, $extraBusy) === null;
}

function offeringHasConflictedMeeting(array $offering): bool
{
    foreach ($offering['rows'] ?? [] as $row) {
        if (strcasecmp((string) ($row['status'] ?? ''), 'conflict') === 0) {
            return true;
        }
    }
    return false;
}

function offeringDisplayLabel(array $offering, ?array $subject = null): string
{
    $code = trim((string) ($subject['code'] ?? $offering['subjectCode'] ?? ''));
    $block = trim((string) ($offering['label'] ?? $offering['blockLabel'] ?? ''));
    if ($code !== '' && $block !== '') {
        return $code . ' · ' . $block;
    }
    return $code !== '' ? $code : ($block !== '' ? $block : 'offering');
}

function describeExistingFacultyOverlap(
    string $facultyId,
    string $day,
    string $startTime,
    string $endTime,
    int $academicYear,
    string $semester,
    array $ignoreScheduleIds = []
): ?string {
    if ($facultyId === '') {
        return null;
    }
    $start = normalizeScheduleTime($startTime) ?? $startTime;
    $end = normalizeScheduleTime($endTime) ?? $endTime;
    if (substr_count($start, ':') === 1) {
        $start .= ':00';
    }
    if (substr_count($end, ':') === 1) {
        $end .= ':00';
    }
    $sql = 'SELECT sub.code AS subjectCode, s.blockName, s.day, s.startTime, s.endTime
            FROM schedule s
            INNER JOIN subject sub ON sub.uid = s.subjectId
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
    $hit = $stmt->fetch();
    if (!$hit) {
        return null;
    }
    $block = trim((string) ($hit['blockName'] ?? ''));
    return sprintf(
        '%s%s %s %s–%s',
        (string) ($hit['subjectCode'] ?? 'class'),
        $block !== '' ? ' · ' . $block : '',
        (string) ($hit['day'] ?? $day),
        substr((string) ($hit['startTime'] ?? ''), 0, 5),
        substr((string) ($hit['endTime'] ?? ''), 0, 5)
    );
}

/**
 * Why this TBF offering cannot be auto-assigned, or null if it is conflict-free.
 *
 * @param list<array{day:string,startTime:string,endTime:string}> $extraBusy
 */
function offeringConflictReason(
    string $facultyId,
    array $offering,
    int $academicYear,
    string $semester,
    array $extraBusy = [],
    ?array $subject = null
): ?string {
    $label = offeringDisplayLabel($offering, $subject);
    $rows = $offering['rows'] ?? [];
    $ignore = $offering['scheduleIds'] ?? [];

    if (offeringHasConflictedMeeting($offering)) {
        return $label . ' is already a conflicted schedule (room or time overlap in the grid).';
    }

    $n = count($rows);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $rows[$i];
            $b = $rows[$j];
            if (strcasecmp((string) ($a['day'] ?? ''), (string) ($b['day'] ?? '')) !== 0) {
                continue;
            }
            if (scheduleTimesOverlap(
                (string) ($a['startTime'] ?? ''),
                (string) ($a['endTime'] ?? ''),
                (string) ($b['startTime'] ?? ''),
                (string) ($b['endTime'] ?? '')
            )) {
                return $label . ' has overlapping meetings inside the same offering.';
            }
        }
    }

    foreach ($rows as $row) {
        $day = (string) ($row['day'] ?? '');
        $start = (string) ($row['startTime'] ?? '');
        $end = (string) ($row['endTime'] ?? '');
        $existing = describeExistingFacultyOverlap(
            $facultyId,
            $day,
            $start,
            $end,
            $academicYear,
            $semester,
            $ignore
        );
        if ($existing !== null) {
            return $label . ' overlaps this faculty’s existing ' . $existing . '.';
        }
        foreach ($extraBusy as $busy) {
            if (strcasecmp((string) ($busy['day'] ?? ''), $day) !== 0) {
                continue;
            }
            if (scheduleTimesOverlap(
                $start,
                $end,
                (string) ($busy['startTime'] ?? ''),
                (string) ($busy['endTime'] ?? '')
            )) {
                return $label . ' overlaps another offering already chosen for this load.';
            }
        }
    }

    return null;
}

/**
 * TBF offerings for this department, split into conflict-free vs blocked for a faculty.
 *
 * @return array{
 *   totalCount:int,
 *   totalLoad:float,
 *   freeCount:int,
 *   freeLoad:float,
 *   blockedCount:int,
 *   blockedLoad:float,
 *   reasons:list<string>
 * }
 */
function inventoryTbfOfferingsForFaculty(
    string $facultyId,
    string $departmentId,
    int $academicYear,
    string $semester,
    ?string $subjectId = null
): array {
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
        if ($sid === '') {
            continue;
        }
        if ($subjectId !== null && $subjectId !== '' && $sid !== $subjectId) {
            continue;
        }
        $bySubject[$sid][] = $row;
    }

    $totalCount = 0;
    $totalLoad = 0.0;
    $freeCount = 0;
    $freeLoad = 0.0;
    $blockedCount = 0;
    $blockedLoad = 0.0;
    $reasons = [];

    foreach ($bySubject as $sid => $_rows) {
        $subject = fetchSubjectById((string) $sid);
        if ($subject === null) {
            continue;
        }
        $owner = (string) ($subject['departmentId'] ?? '');
        $serves = (string) ($subject['servingDepartmentId'] ?? '');
        if ($owner !== $departmentId && $serves !== $departmentId) {
            continue;
        }
        foreach (listTbfSubjectOfferings((string) $sid, $academicYear, $semester) as $offering) {
            $enriched = enrichOfferingForLoadPreview($offering, $subject);
            $load = (float) ($enriched['load'] ?? 0);
            $totalCount++;
            $totalLoad += $load;
            $reason = offeringConflictReason(
                $facultyId,
                $offering,
                $academicYear,
                $semester,
                [],
                $subject
            );
            if ($reason !== null) {
                $blockedCount++;
                $blockedLoad += $load;
                if (count($reasons) < 8) {
                    $reasons[] = $reason;
                }
            } else {
                $freeCount++;
                $freeLoad += $load;
            }
        }
    }

    return [
        'totalCount' => $totalCount,
        'totalLoad' => round($totalLoad, 2),
        'freeCount' => $freeCount,
        'freeLoad' => round($freeLoad, 2),
        'blockedCount' => $blockedCount,
        'blockedLoad' => round($blockedLoad, 2),
        'reasons' => $reasons,
    ];
}

function formatNoAvailableScheduleMessage(
    array $inventory,
    string $facultyName,
    float $targetLoad
): string {
    $target = number_format($targetLoad, 2);
    $total = number_format((float) ($inventory['totalLoad'] ?? 0), 2);
    $free = number_format((float) ($inventory['freeLoad'] ?? 0), 2);
    $blocked = (int) ($inventory['blockedCount'] ?? 0);
    $reasons = $inventory['reasons'] ?? [];

    $msg = 'No available schedule for ' . $facultyName . '.';
    if ((float) ($inventory['totalLoad'] ?? 0) + 1e-9 >= $targetLoad
        && (float) ($inventory['freeLoad'] ?? 0) <= 1e-9
    ) {
        $msg .= sprintf(
            ' There is %s TBF load (more than the %s target), but every offering conflicts.',
            $total,
            $target
        );
    } elseif ((float) ($inventory['totalLoad'] ?? 0) <= 1e-9) {
        $msg .= ' There are no undistributed (TBF) offerings for your department this term.';
    } else {
        $msg .= sprintf(
            ' Conflict-free TBF load is %s; listed TBF load is %s (target %s). %d offering(s) conflict.',
            $free,
            $total,
            $target,
            $blocked
        );
    }
    if ($reasons !== []) {
        $msg .= ' Reasons: ' . implode(' ', array_slice($reasons, 0, 5));
    }

    return $msg;
}

/**
 * How much load still needs assigning for this command.
 *
 * - Faculty with no load: command number is the total target (e.g. "8 loads" → 8 total).
 * - Faculty below that number: assign only the gap (e.g. 5 now + "8 loads" → assign 3).
 * - Faculty already at/above baseline (8 regular / 3 part-time): command is total, gap 0.
 * - Small command below current load (e.g. "2 loads" while at 8): add that much more.
 *
 * @return array{mode:string,targetTotal:float,loadGap:float}
 */
function resolveLoadAssignmentGap(float $targetLoad, float $currentLoad, float $minLoad): array
{
    $targetLoad = max(0.0, $targetLoad);
    $currentLoad = max(0.0, $currentLoad);
    $minLoad = max(0.0, $minLoad);

    if ($currentLoad <= 0.001) {
        return [
            'mode' => 'total',
            'targetTotal' => $targetLoad,
            'loadGap' => $targetLoad,
        ];
    }

    if ($targetLoad + 1e-9 >= $currentLoad) {
        return [
            'mode' => 'total',
            'targetTotal' => $targetLoad,
            'loadGap' => max(0.0, round($targetLoad - $currentLoad, 4)),
        ];
    }

    if ($targetLoad + 1e-9 >= $minLoad) {
        return [
            'mode' => 'total',
            'targetTotal' => $targetLoad,
            'loadGap' => 0.0,
        ];
    }

    return [
        'mode' => 'incremental',
        'targetTotal' => round($currentLoad + $targetLoad, 4),
        'loadGap' => $targetLoad,
    ];
}

function formatLoadShortageNotice(
    array $inventory,
    string $facultyName,
    float $targetLoad,
    float $packedLoad,
    int $packedCount
): string {
    $target = number_format($targetLoad, 2);
    $packed = number_format($packedLoad, 2);
    $total = number_format((float) ($inventory['totalLoad'] ?? 0), 2);
    $free = number_format((float) ($inventory['freeLoad'] ?? 0), 2);
    $blocked = (int) ($inventory['blockedCount'] ?? 0);
    $reasons = $inventory['reasons'] ?? [];

    $msg = sprintf(
        'Load is short for %s: packed %s conflict-free load (%d offering(s)) against a %s target.',
        $facultyName,
        $packed,
        $packedCount,
        $target
    );
    if ((float) ($inventory['totalLoad'] ?? 0) + 1e-9 >= $targetLoad
        && $packedLoad + 1e-9 < $targetLoad
    ) {
        $msg .= sprintf(
            ' Listed TBF load is %s (enough on paper), but remaining offerings conflict or overlap the chosen times.',
            $total
        );
    } elseif ((float) ($inventory['freeLoad'] ?? 0) + 1e-9 > $packedLoad) {
        $msg .= sprintf(
            ' Conflict-free TBF load totals %s, but those offerings overlap each other so only %s can be assigned together.',
            $free,
            $packed
        );
    } elseif ($blocked > 0) {
        $msg .= sprintf(' %d other TBF offering(s) conflict for this faculty.', $blocked);
    }
    if ($reasons !== []) {
        $msg .= ' Reasons: ' . implode(' ', array_slice($reasons, 0, 5));
    }

    return $msg;
}

/**
 * Keep offerings that do not conflict with this faculty or with each other.
 *
 * @param list<array<string,mixed>> $offerings
 * @return list<array<string,mixed>>
 */
function selectNonConflictingOfferings(
    array $offerings,
    string $facultyId,
    int $academicYear,
    string $semester
): array {
    $pendingBusy = [];
    $selected = [];
    foreach ($offerings as $offering) {
        $rawOffering = [
            'scheduleIds' => $offering['scheduleIds'] ?? [],
            'rows' => $offering['rows'] ?? [],
        ];
        if (!facultyCanTakeOffering($facultyId, $rawOffering, $academicYear, $semester, $pendingBusy)) {
            continue;
        }
        $selected[] = $offering;
        foreach ($offering['rows'] ?? [] as $row) {
            $pendingBusy[] = [
                'day' => (string) ($row['day'] ?? ''),
                'startTime' => (string) ($row['startTime'] ?? ''),
                'endTime' => (string) ($row['endTime'] ?? ''),
            ];
        }
    }

    return $selected;
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
 * All conflict-free TBF offerings this faculty can take (owned or served subjects).
 *
 * @return array{
 *   offerings:list<array<string,mixed>>,
 *   candidates:list<array{subject:array<string,mixed>,offerings:list<array<string,mixed>>,availableCount:int}>
 * }
 */
function collectConflictFreeLoadOfferings(
    string $facultyId,
    string $departmentId,
    int $academicYear,
    string $semester
): array {
    $candidates = listAvailableSubjectsForFacultyLoad(
        $facultyId,
        $departmentId,
        $academicYear,
        $semester,
        true
    );
    if ($candidates === []) {
        $candidates = listAvailableSubjectsForFacultyLoad(
            $facultyId,
            $departmentId,
            $academicYear,
            $semester,
            false
        );
    }
    $offerings = [];
    foreach ($candidates as $candidate) {
        $subj = $candidate['subject'];
        foreach ($candidate['offerings'] as $offering) {
            $offerings[] = enrichOfferingForLoadPreview($offering, $subj);
        }
    }

    return [
        'offerings' => $offerings,
        'candidates' => $candidates,
    ];
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
    $currentLoadValue = (float) $currentLoad['load'];
    $loadPlan = resolveLoadAssignmentGap($targetLoad, $currentLoadValue, $minLoad);
    $loadGap = (float) $loadPlan['loadGap'];
    $targetTotal = (float) $loadPlan['targetTotal'];
    $loadMode = (string) $loadPlan['mode'];

    if ($loadGap <= 1e-9) {
        throw new InvalidArgumentException(
            sprintf(
                '%s already has %.2f load (target total %.2f). No additional assignment is needed.',
                $selected['fullName'],
                $currentLoadValue,
                $targetTotal
            )
        );
    }

    $maxTotalLoad = maxTotalLoadForAssignment($targetTotal, $currentLoadValue, $loadMode, $loadGap);

    $subjectPickedRandomly = false;

    $subjectCode = trim((string) ($parsed['subjectCode'] ?? ''));
    /** @var list<array<string,mixed>> $allOfferings */
    $allOfferings = [];
    /** @var list<array{subject:array<string,mixed>,offerings:list<array<string,mixed>>,availableCount:int}> $subjectCandidates */
    $subjectCandidates = [];
    $primarySubject = null;
    $hasPickedKeys = $selectedOfferingKeys !== null && $selectedOfferingKeys !== [];

    if ($subjectCode !== '' && !$hasPickedKeys) {
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
            $inventory = inventoryTbfOfferingsForFaculty(
                (string) $selected['uid'],
                $departmentId,
                $academicYear,
                $semester,
                (string) $primarySubject['uid']
            );
            throw new InvalidArgumentException(
                formatNoAvailableScheduleMessage($inventory, $selected['fullName'], $loadGap)
            );
        }
    } else {
        $expanded = collectConflictFreeLoadOfferings(
            (string) $selected['uid'],
            $departmentId,
            $academicYear,
            $semester
        );
        $subjectCandidates = $expanded['candidates'];
        $allOfferings = $expanded['offerings'];
        if ($allOfferings === []) {
            $inventory = inventoryTbfOfferingsForFaculty(
                (string) $selected['uid'],
                $departmentId,
                $academicYear,
                $semester,
                null
            );
            throw new InvalidArgumentException(
                formatNoAvailableScheduleMessage($inventory, $selected['fullName'], $loadGap)
            );
        }
        $subjectPickedRandomly = true;
    }

    $timeFromMinutes = array_key_exists('timeFromMinutes', $parsed)
        ? ($parsed['timeFromMinutes'] !== null ? (int) $parsed['timeFromMinutes'] : null)
        : null;
    $timeToMinutes = array_key_exists('timeToMinutes', $parsed)
        ? ($parsed['timeToMinutes'] !== null ? (int) $parsed['timeToMinutes'] : null)
        : null;
    if ($timeFromMinutes !== null || $timeToMinutes !== null) {
        $allOfferings = filterOfferingsByLoadTimeWindow($allOfferings, $timeFromMinutes, $timeToMinutes);
        if ($allOfferings === []) {
            $timeLabel = trim((string) ($parsed['timeFilterLabel'] ?? ''));
            if ($timeLabel === '') {
                $timeLabel = 'that time window';
            }
            throw new InvalidArgumentException(
                sprintf(
                    'No conflict-free TBF schedules for %s during %s.',
                    $selected['fullName'],
                    $timeLabel
                )
            );
        }
    }

    $suggestion = suggestOfferingsForLoadTarget(
        $allOfferings,
        $loadGap,
        (string) $selected['uid'],
        $academicYear,
        $semester,
        $currentLoadValue,
        $maxTotalLoad
    );
    $suggestedKeys = $suggestion['suggestedKeys'];
    foreach ($allOfferings as $i => $offering) {
        $allOfferings[$i]['suggested'] = in_array((string) ($offering['offeringKey'] ?? ''), $suggestedKeys, true);
    }

    $chosenOfferings = $hasPickedKeys
        ? resolveSelectedOfferings($allOfferings, $selectedOfferingKeys)
        : $suggestion['selected'];
    if ($hasPickedKeys) {
        $wanted = [];
        foreach ($selectedOfferingKeys as $key) {
            $key = trim((string) $key);
            if ($key !== '') {
                $wanted[$key] = true;
            }
        }
        if (count($chosenOfferings) < count($wanted)) {
            $expanded = collectConflictFreeLoadOfferings(
                (string) $selected['uid'],
                $departmentId,
                $academicYear,
                $semester
            );
            $allOfferings = $expanded['offerings'];
            $subjectCandidates = $expanded['candidates'];
            $subjectPickedRandomly = true;
            if ($timeFromMinutes !== null || $timeToMinutes !== null) {
                $allOfferings = filterOfferingsByLoadTimeWindow(
                    $allOfferings,
                    $timeFromMinutes,
                    $timeToMinutes
                );
            }
            $suggestion = suggestOfferingsForLoadTarget(
                $allOfferings,
                $loadGap,
                (string) $selected['uid'],
                $academicYear,
                $semester,
                $currentLoadValue,
                $maxTotalLoad
            );
            $suggestedKeys = $suggestion['suggestedKeys'];
            foreach ($allOfferings as $i => $offering) {
                $allOfferings[$i]['suggested'] = in_array(
                    (string) ($offering['offeringKey'] ?? ''),
                    $suggestedKeys,
                    true
                );
            }
            $chosenOfferings = resolveSelectedOfferings($allOfferings, $selectedOfferingKeys);
        }
    }
    if (!$hasPickedKeys && $chosenOfferings === [] && $suggestion['selected'] !== []) {
        $chosenOfferings = $suggestion['selected'];
    }
    $chosenOfferings = selectNonConflictingOfferings(
        $chosenOfferings,
        (string) $selected['uid'],
        $academicYear,
        $semester
    );
    $chosenOfferings = trimSelectedOfferingsToCap($chosenOfferings, $currentLoadValue, $maxTotalLoad);

    $suggestedKeys = array_values(array_filter(array_map(
        static fn (array $o): string => (string) ($o['offeringKey'] ?? ''),
        $chosenOfferings
    )));
    foreach ($allOfferings as $i => $offering) {
        $allOfferings[$i]['suggested'] = in_array(
            (string) ($offering['offeringKey'] ?? ''),
            $suggestedKeys,
            true
        );
    }

    $alreadyHasLoad = $currentLoadValue > 0.001;
    if ($chosenOfferings === [] && ($apply || !$alreadyHasLoad)) {
        $inventory = inventoryTbfOfferingsForFaculty(
            (string) $selected['uid'],
            $departmentId,
            $academicYear,
            $semester,
            $subjectCode !== '' && $primarySubject !== null
                ? (string) $primarySubject['uid']
                : null
        );
        throw new InvalidArgumentException(
            formatNoAvailableScheduleMessage($inventory, $selected['fullName'], $loadGap)
        );
    }

    if ($primarySubject === null && $chosenOfferings !== []) {
        $firstCode = trim((string) ($chosenOfferings[0]['subjectCode'] ?? ''));
        if ($firstCode !== '') {
            $primarySubject = findSubjectByCodeForLoad($firstCode, $departmentId);
            $parsed['subjectCode'] = $firstCode;
        }
    }
    if ($primarySubject === null && $allOfferings !== []) {
        $firstCode = trim((string) ($allOfferings[0]['subjectCode'] ?? ''));
        if ($firstCode !== '') {
            $primarySubject = findSubjectByCodeForLoad($firstCode, $departmentId);
        }
    }
    if ($primarySubject !== null && $parsed['subjectCode'] === '') {
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
    $offeringsNeeded = (int) max(1, (int) ceil($loadGap / $referenceLoad - 1e-9));

    $assignedLoad = 0.0;
    $assignedContactHours = 0.0;
    foreach ($chosenOfferings as $offering) {
        $assignedLoad += (float) ($offering['load'] ?? 0);
        $assignedContactHours += (float) ($offering['contactHours'] ?? 0);
    }
    $assignedLoad = round($assignedLoad, 4);
    $assignedContactHours = round($assignedContactHours, 2);

    if ($apply || $hasPickedKeys) {
        assertLoadAssignmentWithinCap($currentLoadValue, $assignedLoad, $loadMode, $targetTotal);
    }

    $shortageNotice = null;
    if ($chosenOfferings === [] || $assignedLoad <= 1e-9) {
        $inventory = inventoryTbfOfferingsForFaculty(
            (string) $selected['uid'],
            $departmentId,
            $academicYear,
            $semester,
            $subjectCode !== '' && $primarySubject !== null
                ? (string) $primarySubject['uid']
                : null
        );
        if ($apply || !$alreadyHasLoad) {
            throw new InvalidArgumentException(
                formatNoAvailableScheduleMessage($inventory, $selected['fullName'], $loadGap)
            );
        }
        $shortageNotice = formatLoadShortageNotice(
            $inventory,
            $selected['fullName'],
            $loadGap,
            0.0,
            0
        );
    } elseif ($assignedLoad + 1e-9 < $loadGap) {
        $inventory = inventoryTbfOfferingsForFaculty(
            (string) $selected['uid'],
            $departmentId,
            $academicYear,
            $semester,
            $subjectCode !== '' && $primarySubject !== null
                ? (string) $primarySubject['uid']
                : null
        );
        $shortageNotice = formatLoadShortageNotice(
            $inventory,
            $selected['fullName'],
            $loadGap,
            $assignedLoad,
            count($chosenOfferings)
        );
    }

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
        'targetTotal' => $targetTotal,
        'loadGap' => round($loadGap, 2),
        'loadMode' => $loadMode,
        'maxTotalLoad' => round($maxTotalLoad, 2),
        'loadWindowMin' => round($targetTotal, 2),
        'loadWindowMax' => round($maxTotalLoad, 2),
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
        'requireUserPick' => $alreadyHasLoad,
        'assignedLoad' => round($assignedLoad, 2),
        'assignedContactHours' => $assignedContactHours,
        'shortageNotice' => $shortageNotice,
        'loadTotal' => [
            'targetLoad' => $targetLoad,
            'targetTotal' => $targetTotal,
            'loadGap' => round($loadGap, 2),
            'suggestedLoad' => round((float) $suggestion['assignedLoad'], 2),
            'selectedLoad' => round($assignedLoad, 2),
            'selectedContactHours' => $assignedContactHours,
            'selectedLoadFormula' => $assignedContactHours > 0
                ? sprintf('%s h ÷ 3 = %s', rtrim(rtrim(number_format($assignedContactHours, 2), '0'), '.'), rtrim(rtrim(number_format($assignedLoad, 4), '0'), '.'))
                : '0 h ÷ 3 = 0',
            'afterAssignLoad' => round($currentLoadValue + $assignedLoad, 2),
            'formula' => 'total load = contact hours ÷ 3',
        ],
        'assignments' => $assignments,
        'applied' => $applied,
        'updatedMeetings' => $updatedMeetings,
        'confirmSummary' => $alreadyHasLoad
            ? (
                $loadMode === 'incremental'
                    ? sprintf(
                        'Add %.2f load for %s (currently %.2f → %.2f total) — pick offering(s) of %s.',
                        $loadGap,
                        $selected['fullName'],
                        $currentLoadValue,
                        $targetTotal,
                        $subjectLabel
                    )
                    : sprintf(
                        '%s has %.2f load. Assign %.2f more to reach %.2f total — pick offering(s) of %s.',
                        $selected['fullName'],
                        $currentLoadValue,
                        $loadGap,
                        $targetTotal,
                        $subjectLabel
                    )
            )
            : sprintf(
                'Assign the system-chosen schedule for %s: %d offering(s) of %s = %.2f load (%.2f h ÷ 3). Total after assign: %.2f.',
                $selected['fullName'],
                count($chosenOfferings),
                $subjectLabel,
                $assignedLoad,
                $assignedContactHours,
                round($currentLoadValue + $assignedLoad, 2)
            ),
        'parsed' => $parsed,
    ];
}
