<?php

declare(strict_types=1);

/**
 * Natural-language schedule command parsing + block save helpers (Dean).
 */

require_once __DIR__ . '/Subject.php';
require_once __DIR__ . '/Term.php';
require_once __DIR__ . '/Schedule.php';
require_once __DIR__ . '/ScheduleOptimizer.php';
require_once __DIR__ . '/ClassBlock.php';

const STUDENT_TYPE_REGULAR = 'regular';
const STUDENT_TYPE_IRREGULAR = 'irregular';

/**
 * Parse a plain-language schedule generation command.
 *
 * @return array{
 *   rawCommand:string,
 *   yearLevel:?string,
 *   studentType:string,
 *   curriculumYear:?int,
 *   semester:?string,
 *   scheduleSemester:?string,
 *   blockCount:int,
 *   dayCount:?int,
 *   days:?list<string>,
 *   departmentId:string,
 *   missing:list<string>,
 *   confirmSummary:string,
 *   ready:bool
 * }
 */
function parseScheduleCommand(string $rawCommand, string $departmentId): array
{
    $raw = trim($rawCommand);
    if ($raw === '') {
        throw new InvalidArgumentException('Command text is required.');
    }
    if ($departmentId === '') {
        throw new InvalidArgumentException('Dean has no assigned department.');
    }

    // Normalize glued tokens: "regular2026" → "regular 2026"
    $raw = preg_replace('/\b(regular|irregular)(20\d{2})\b/i', '$1 $2', $raw) ?? $raw;
    $raw = preg_replace('/\b(20\d{2})(curriculum)\b/i', '$1 $2', $raw) ?? $raw;

    $lower = strtolower($raw);

    $yearLevel = parseYearLevelFromCommand($lower);
    $studentType = parseStudentTypeFromCommand($lower);
    $curriculumYear = parseCurriculumYearFromCommand($lower);
    $curriculumSemester = parseCurriculumSemesterFromCommand($lower);
    $blockCount = parseBlockCountFromCommand($lower);
    $days = parseDaysFromCommand($lower);
    $dayCount = $days !== null ? count($days) : parseDayCountFromCommand($lower);
    $semesterFromCommand = $curriculumSemester !== null;
    $scheduleSemester = $curriculumSemester !== null
        ? scheduleSemesterFromCurriculum($curriculumSemester)
        : null;

    $missing = [];
    if ($yearLevel === null) {
        $missing[] = 'yearLevel';
    }
    // curriculumYear + semester are optional in text — defaulted in applyScheduleCommandSemesterDefault().
    if ($scheduleSemester === null) {
        $missing[] = 'semester';
    }

    $parts = [];
    $parts[] = $yearLevel ?? '(year level?)';
    $parts[] = ucfirst($studentType);
    $parts[] = ($curriculumYear !== null ? (string) $curriculumYear : '(curriculum year?)') . ' curriculum';
    if ($curriculumSemester !== null) {
        $parts[] = $curriculumSemester;
    } else {
        $parts[] = '(semester?)';
    }
    $parts[] = $blockCount . ($blockCount === 1 ? ' block' : ' blocks');
    $parts[] = formatScheduleCommandDaysLabel($days, $dayCount);

    $confirmSummary = 'Generating: ' . implode(', ', $parts) . ' — confirm?';

    return [
        'rawCommand' => $raw,
        'yearLevel' => $yearLevel,
        'studentType' => $studentType,
        'curriculumYear' => $curriculumYear,
        'semester' => $curriculumSemester,
        'scheduleSemester' => $scheduleSemester,
        'semesterFromCommand' => $semesterFromCommand,
        'blockCount' => $blockCount,
        'dayCount' => $dayCount,
        'days' => $days,
        'departmentId' => $departmentId,
        'missing' => $missing,
        'confirmSummary' => $confirmSummary,
        'ready' => $yearLevel !== null && $curriculumYear !== null && $scheduleSemester !== null,
    ];
}

function parseYearLevelFromCommand(string $lower): ?string
{
    $patterns = [
        '1st Year' => '/\b(?:1st|first)\s*-?\s*year\b|\byear\s*1\b|\b1st\s*yr\b/',
        '2nd Year' => '/\b(?:2nd|second)\s*-?\s*year\b|\byear\s*2\b|\b2nd\s*yr\b/',
        '3rd Year' => '/\b(?:3rd|third)\s*-?\s*year\b|\byear\s*3\b|\b3rd\s*yr\b/',
        '4th Year' => '/\b(?:4th|fourth)\s*-?\s*year\b|\byear\s*4\b|\b4th\s*yr\b/',
    ];
    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $lower) === 1) {
            return $label;
        }
    }
    return null;
}

function parseStudentTypeFromCommand(string $lower): string
{
    if (preg_match('/\birregular\b/', $lower) === 1) {
        return STUDENT_TYPE_IRREGULAR;
    }
    // Default regular when not stated (Deans usually mean the standard track).
    return STUDENT_TYPE_REGULAR;
}

function parseCurriculumYearFromCommand(string $lower): ?int
{
    // Prefer explicit "2026 curriculum" / "curriculum 2026" / "refer to the 2026"
    if (preg_match('/\b(20\d{2})\s*curriculum\b/', $lower, $m) === 1) {
        return (int) $m[1];
    }
    if (preg_match('/\bcurriculum\s*(?:of\s*|for\s*|year\s*)?(20\d{2})\b/', $lower, $m) === 1) {
        return (int) $m[1];
    }
    if (preg_match('/\brefer(?:ring)?\s+to\s+(?:the\s+)?(20\d{2})\b/', $lower, $m) === 1) {
        return (int) $m[1];
    }
    if (preg_match('/\b(20\d{2})\b/', $lower, $m) === 1) {
        return (int) $m[1];
    }
    return null;
}

function parseCurriculumSemesterFromCommand(string $lower): ?string
{
    if (preg_match('/\b(?:1st|first)\s*(?:sem(?:ester)?)\b/', $lower) === 1) {
        return '1st Semester';
    }
    if (preg_match('/\b(?:2nd|second)\s*(?:sem(?:ester)?)\b/', $lower) === 1) {
        return '2nd Semester';
    }
    if (preg_match('/\bsummer\b/', $lower) === 1) {
        return 'Summer';
    }
    return null;
}

/**
 * How many section blocks to create (default 1).
 * Examples: "3 block", "3 blocks", "create 2 blocks".
 */
function parseBlockCountFromCommand(string $lower): int
{
    if (preg_match('/\b(\d{1,2})\s*blocks?\b/', $lower, $m) === 1) {
        return max(1, min(8, (int) $m[1]));
    }
    $words = [
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
        'five' => 5,
        'six' => 6,
    ];
    foreach ($words as $word => $n) {
        if (preg_match('/\b' . $word . '\s+blocks?\b/', $lower) === 1) {
            return $n;
        }
    }
    return 1;
}

/**
 * Limit class meetings to this many weekdays (Mon…), or null for full week.
 * Examples: "2 days", "2 day schedule", "within 3 days".
 * Ignored when specific days were already parsed (M T, Mon Tue, etc.).
 */
function parseDayCountFromCommand(string $lower): ?int
{
    if (preg_match('/\b(\d{1,2})\s*days?\b/', $lower, $m) === 1) {
        return max(1, min(7, (int) $m[1]));
    }
    $words = [
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
        'five' => 5,
        'six' => 6,
        'seven' => 7,
    ];
    foreach ($words as $word => $n) {
        if (preg_match('/\b' . $word . '\s+days?\b/', $lower) === 1) {
            return $n;
        }
    }
    return null;
}

/**
 * Explicit meeting days from the command.
 *
 * Accepts:
 *   - Full / short names: Monday, Mon, Tue, Wed, Thu, Fri, Sat, Sun
 *   - School letters: M T W TH F Sa Su (and R for Thursday)
 *   - Compact codes: MW, MWF, MT, TTH, …
 *
 * Examples:
 *   "fri" / "friday" → Friday
 *   "mon thu sat" → Monday, Thursday, Saturday (3 days)
 *   "M Sa" → Monday, Saturday
 *
 * @return list<string>|null  Canonical day names, or null if not specified
 */
function parseDaysFromCommand(string $lower): ?array
{
    // Compact school codes as a whole token (checked first).
    $codes = [
        'mtwthf' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
        'mtwth' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday'],
        'mwf' => ['Monday', 'Wednesday', 'Friday'],
        'tth' => ['Tuesday', 'Thursday'],
        'mw' => ['Monday', 'Wednesday'],
        'mt' => ['Monday', 'Tuesday'],
        'tf' => ['Tuesday', 'Friday'],
        'wf' => ['Wednesday', 'Friday'],
    ];
    foreach ($codes as $code => $days) {
        if (preg_match('/\b' . $code . '\b/', $lower) === 1) {
            return $days;
        }
    }

    $found = [];
    $working = $lower;

    // Longer aliases first so "thu"/"th" win over bare "t", and "sa"/"su" win over "s".
    $multi = [
        'Thursday' => '/\b(?:thursdays?|thurs|thur|thu|th)\b/',
        'Saturday' => '/\b(?:saturdays?|sats?|sat|sa)\b/',
        'Sunday' => '/\b(?:sundays?|suns?|sun|su)\b/',
        'Tuesday' => '/\b(?:tuesdays?|tues|tue|tu)\b/',
        'Wednesday' => '/\b(?:wednesdays?|weds|wed)\b/',
        'Monday' => '/\b(?:mondays?|mons|mon)\b/',
        'Friday' => '/\b(?:fridays?|fri)\b/',
    ];
    foreach ($multi as $day => $pattern) {
        if (preg_match($pattern, $working) === 1) {
            $found[$day] = true;
            $working = preg_replace($pattern, ' ', $working) ?? $working;
        }
    }

    // Remaining single-letter tokens: M T W R F (and S/U only in a letter list).
    if (preg_match_all('/(?<![a-z0-9])([mtwrfsu])(?![a-z])/i', $working, $matches)) {
        $letterMap = [
            'm' => 'Monday',
            't' => 'Tuesday',
            'w' => 'Wednesday',
            'r' => 'Thursday',
            'f' => 'Friday',
            's' => 'Saturday',
            'u' => 'Sunday',
        ];
        $letters = array_map('strtolower', $matches[1]);
        // Always accept M/T/W/R/F. Accept S/U when there is at least one other day
        // signal (another letter, or a multi-name day already found).
        $coreLetters = array_values(array_filter(
            $letters,
            static fn (string $l): bool => in_array($l, ['m', 't', 'w', 'r', 'f'], true)
        ));
        $allowWeekendLetters = $found !== [] || count($letters) >= 2 || count($coreLetters) >= 1;

        foreach ($letters as $letter) {
            if ($letter === 's' || $letter === 'u') {
                if (!$allowWeekendLetters) {
                    continue;
                }
            }
            if (isset($letterMap[$letter])) {
                $found[$letterMap[$letter]] = true;
            }
        }
    }

    if ($found === []) {
        return null;
    }

    $ordered = [];
    foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $day) {
        if (isset($found[$day])) {
            $ordered[] = $day;
        }
    }
    return $ordered;
}

/**
 * @param list<string>|null $days
 */
function formatScheduleCommandDaysLabel(?array $days, ?int $dayCount): string
{
    if ($days !== null && $days !== []) {
        $short = [
            'Monday' => 'Mon',
            'Tuesday' => 'Tue',
            'Wednesday' => 'Wed',
            'Thursday' => 'Thu',
            'Friday' => 'Fri',
            'Saturday' => 'Sat',
            'Sunday' => 'Sun',
        ];
        return implode('/', array_map(
            static fn (string $d): string => $short[$d] ?? $d,
            $days
        ));
    }
    if ($dayCount !== null) {
        return (int) $dayCount . ((int) $dayCount === 1 ? ' day' : ' days');
    }
    return 'full week';
}

/**
 * Apply semester + curriculum-year defaults when omitted from the command text.
 *
 * @param array<string,mixed> $parsed
 * @return array<string,mixed>
 */
function applyScheduleCommandSemesterDefault(array $parsed, ?string $semesterOverride = null): array
{
    $semesterWasMissing = empty($parsed['semesterFromCommand']);
    $curriculumWasMissing = empty($parsed['curriculumYear']);

    if ($semesterOverride !== null && $semesterOverride !== '') {
        $override = trim($semesterOverride);
        if (in_array($override, SUBJECT_SEMESTERS, true)) {
            $parsed['semester'] = $override;
            $parsed['scheduleSemester'] = scheduleSemesterFromCurriculum($override);
        } elseif (in_array($override, SCHEDULE_SEMESTERS, true)) {
            $parsed['scheduleSemester'] = $override;
            $parsed['semester'] = curriculumSemesterFromScheduleSemester($override);
        } else {
            throw new InvalidArgumentException('Invalid semester.');
        }
    } elseif (($parsed['scheduleSemester'] ?? null) === null) {
        $term = currentTermWindow();
        $parsed['scheduleSemester'] = $term['semester'];
        $parsed['semester'] = curriculumSemesterFromScheduleSemester($term['semester']);
    }

    if (empty($parsed['curriculumYear'])) {
        $parsed['curriculumYear'] = resolveDefaultCurriculumYear(
            (string) ($parsed['departmentId'] ?? ''),
            isset($parsed['yearLevel']) ? (string) $parsed['yearLevel'] : null,
            isset($parsed['semester']) ? (string) $parsed['semester'] : null
        );
    }

    $missing = [];
    if (empty($parsed['yearLevel'])) {
        $missing[] = 'yearLevel';
    }
    $parsed['needsSemesterConfirm'] = $semesterWasMissing;
    $parsed['needsCurriculumYearConfirm'] = $curriculumWasMissing;
    $parsed['missing'] = $missing;
    $parsed['ready'] = $missing === []
        && !empty($parsed['scheduleSemester'])
        && !empty($parsed['curriculumYear']);

    $parts = [
        $parsed['yearLevel'] ?? '(year level?)',
        ucfirst((string) ($parsed['studentType'] ?? STUDENT_TYPE_REGULAR)),
        ((string) $parsed['curriculumYear']) . ' curriculum',
    ];
    if (!empty($parsed['semester'])) {
        $parts[] = (string) $parsed['semester'];
    }
    $blockCount = max(1, (int) ($parsed['blockCount'] ?? 1));
    $dayCount = $parsed['dayCount'] ?? null;
    $days = $parsed['days'] ?? null;
    if (is_array($days) && $days !== []) {
        $dayCount = count($days);
    }
    $parts[] = $blockCount . ($blockCount === 1 ? ' block' : ' blocks');
    $parts[] = formatScheduleCommandDaysLabel(
        is_array($days) ? $days : null,
        $dayCount !== null ? (int) $dayCount : null
    );
    $parsed['blockCount'] = $blockCount;
    $parsed['dayCount'] = $dayCount;
    $parsed['days'] = is_array($days) && $days !== [] ? array_values($days) : null;
    $parsed['confirmSummary'] = 'Generating: ' . implode(', ', $parts) . ' — confirm?';

    return $parsed;
}

/**
 * Prefer the newest curriculum year that has matching subjects; else current term year.
 */
function resolveDefaultCurriculumYear(
    string $departmentId,
    ?string $yearLevel,
    ?string $curriculumSemester
): int {
    $sql = 'SELECT curriculumYear FROM subject
            WHERE status = \'Active\'';
    $params = [];
    if ($departmentId !== '') {
        $sql .= ' AND departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
    }
    if ($yearLevel !== null && $yearLevel !== '') {
        $sql .= ' AND yearLevel = :yearLevel';
        $params[':yearLevel'] = $yearLevel;
    }
    if ($curriculumSemester !== null && $curriculumSemester !== '') {
        $sql .= ' AND semester = :semester';
        $params[':semester'] = $curriculumSemester;
    }
    $sql .= ' ORDER BY curriculumYear DESC LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $found = $stmt->fetchColumn();
    if ($found !== false && $found !== null) {
        return (int) $found;
    }

    $term = currentTermWindow();
    return (int) $term['academicYear'];
}

/**
 * Next auto block name for year level / department / term.
 * Prefers the classBlock registry (Dean-created blocks).
 *
 * @return array{blockNumber:int,blockName:string}
 */
function nextScheduleBlockName(
    string $departmentId,
    string $yearLevel,
    int $academicYear,
    string $scheduleSemester
): array {
    $peek = peekNextClassBlockSlot($departmentId, $yearLevel, $academicYear, $scheduleSemester);
    return [
        'blockNumber' => $peek['blockNumber'],
        'blockName' => $peek['blockName'],
    ];
}

/**
 * Persist one optimizer option as draft/confirmed schedule rows under an auto block name.
 *
 * @param array<string,mixed> $parsedCommand
 * @param array<string,mixed> $option  one item from generateScheduleOptions()['options']
 * @param array{uid:string,blockNumber:int,blockName:string,name?:string}|null $preclaimedBlock
 * @return array{blockName:string,blockNumber:int,classBlockId:string,schedules:list<array<string,mixed>>,confirmedCount:int,conflictCount:int}
 */
function saveGeneratedScheduleOption(
    array $parsedCommand,
    array $option,
    string $createdBy,
    ?int $academicYear = null,
    ?array $preclaimedBlock = null
): array {
    $departmentId = (string) $parsedCommand['departmentId'];
    $yearLevel = (string) $parsedCommand['yearLevel'];
    $studentType = (string) ($parsedCommand['studentType'] ?? STUDENT_TYPE_REGULAR);
    $scheduleSemester = (string) $parsedCommand['scheduleSemester'];
    $term = normalizeTermFields($academicYear, $scheduleSemester);

    $assignments = array_values($option['assignments'] ?? []);
    if ($assignments === []) {
        throw new InvalidArgumentException('Selected option has no assignments.');
    }

    if ($preclaimedBlock !== null && !empty($preclaimedBlock['uid'])) {
        $block = [
            'uid' => (string) $preclaimedBlock['uid'],
            'blockNumber' => (int) $preclaimedBlock['blockNumber'],
            'blockName' => (string) ($preclaimedBlock['blockName'] ?? $preclaimedBlock['name'] ?? ''),
            'name' => (string) ($preclaimedBlock['name'] ?? $preclaimedBlock['blockName'] ?? ''),
        ];
    } else {
        $block = ensureNextClassBlock(
            $departmentId,
            $yearLevel,
            $term['academicYear'],
            $term['semester'],
            $createdBy,
            $studentType
        );
    }

    $pdo = db();
    $pdo->beginTransaction();
    $created = [];
    try {
        foreach ($assignments as $assignment) {
            $startTime = normalizeScheduleTime((string) $assignment['startTime']);
            $endTime = normalizeScheduleTime((string) $assignment['endTime']);
            if ($startTime === null || $endTime === null) {
                throw new InvalidArgumentException('Invalid time in selected option.');
            }
            $data = [
                // AI never assigns instructors — Dean picks faculty per subject later.
                'facultyId' => null,
                'roomId' => (string) $assignment['roomId'],
                'departmentId' => $departmentId,
                'subjectId' => (string) $assignment['subjectId'],
                'day' => (string) $assignment['day'],
                'startTime' => $startTime,
                'endTime' => $endTime,
                'academicYear' => $term['academicYear'],
                'semester' => $term['semester'],
                'yearLevel' => $yearLevel,
                'blockNumber' => $block['blockNumber'],
                'blockName' => $block['blockName'],
                'classBlockId' => $block['uid'],
                'studentType' => $studentType,
            ];
            $created[] = createDraftSchedule($data, $createdBy);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $confirmedCount = 0;
    $conflictCount = 0;
    $schedules = [];
    foreach ($created as $row) {
        $result = attemptConfirmSchedule((string) $row['uid']);
        $schedules[] = $result['schedule'];
        if (!empty($result['confirmed'])) {
            $confirmedCount++;
        } else {
            $conflictCount++;
        }
    }

    return [
        'blockName' => $block['blockName'],
        'blockNumber' => $block['blockNumber'],
        'classBlockId' => $block['uid'],
        'schedules' => $schedules,
        'confirmedCount' => $confirmedCount,
        'conflictCount' => $conflictCount,
    ];
}

/**
 * Build ranked multi-block plans from a parsed command + optimizer input.
 * Each plan contains blockCount internally conflict-free blocks that also
 * avoid room/faculty clashes with each other.
 *
 * @param array<string,mixed> $baseInput  from buildScheduleOptimizerInputFromDb()
 * @param array<string,mixed> $parsed
 * @return list<array<string,mixed>>
 */
function generateScheduleCommandPlans(array $baseInput, array $parsed, int $planCount = 3): array
{
    $blockCount = max(1, min(8, (int) ($parsed['blockCount'] ?? 1)));
    $days = null;
    if (!empty($parsed['days']) && is_array($parsed['days'])) {
        $days = array_values(array_filter(
            $parsed['days'],
            static fn ($d): bool => is_string($d) && $d !== ''
        ));
        if ($days === []) {
            $days = null;
        }
    }
    $dayCount = $days !== null
        ? count($days)
        : (isset($parsed['dayCount']) && $parsed['dayCount'] !== null
            ? max(1, min(7, (int) $parsed['dayCount']))
            : null);

    $preview = nextScheduleBlockName(
        (string) $parsed['departmentId'],
        (string) $parsed['yearLevel'],
        currentTermWindow()['academicYear'],
        (string) $parsed['scheduleSemester']
    );

    $plans = [];
    for ($p = 0; $p < $planCount; $p++) {
        $reserved = array_values($baseInput['reservedAssignments'] ?? []);
        $blocks = [];
        $scores = [];
        $failed = false;

        for ($b = 0; $b < $blockCount; $b++) {
            $input = $baseInput;
            $input['optionCount'] = 1;
            $input['dayCount'] = $dayCount;
            $input['allowedDays'] = $days;
            $input['reservedAssignments'] = $reserved;
            $input['seed'] = 1000 + ($p * 97) + ($b * 13);
            $input['populationSize'] = 36;
            $input['generations'] = 40;

            $result = generateScheduleOptions($input);
            if (($result['options'] ?? []) === []) {
                $failed = true;
                break;
            }

            $opt = $result['options'][0];
            $blockNumber = $preview['blockNumber'] + $b;
            $blockName = sprintf('%s Block %d', $parsed['yearLevel'], $blockNumber);
            $opt['previewBlockNumber'] = $blockNumber;
            $opt['previewBlockName'] = $blockName;
            $blocks[] = $opt;
            $scores[] = (float) ($opt['score'] ?? 0);

            foreach ($opt['assignments'] as $assignment) {
                $reserved[] = $assignment;
            }
        }

        if ($failed || count($blocks) !== $blockCount) {
            continue;
        }

        $avg = $scores === [] ? 0.0 : array_sum($scores) / count($scores);
        $plans[] = [
            'rank' => count($plans) + 1,
            'score' => round($avg, 4),
            'blockCount' => $blockCount,
            'dayCount' => $dayCount,
            'days' => $days,
            'daysLabel' => formatScheduleCommandDaysLabel($days, $dayCount),
            'previewBlockNames' => array_map(
                static fn (array $b): string => (string) $b['previewBlockName'],
                $blocks
            ),
            'blocks' => $blocks,
        ];
    }

    return $plans;
}

/**
 * Persist every block in a generated plan (consecutive Block N, N+1, …).
 *
 * @param array<string,mixed> $parsedCommand
 * @param array<string,mixed> $plan
 * @return array{
 *   blocks:list<array<string,mixed>>,
 *   blockNames:list<string>,
 *   confirmedCount:int,
 *   conflictCount:int
 * }
 */
function saveGeneratedSchedulePlan(
    array $parsedCommand,
    array $plan,
    string $createdBy,
    ?int $academicYear = null
): array {
    $blocks = array_values($plan['blocks'] ?? []);
    if ($blocks === []) {
        // Legacy: single option → wrap as one-block plan.
        if (!empty($plan['assignments'])) {
            $blocks = [$plan];
        } else {
            throw new InvalidArgumentException('Selected plan has no blocks.');
        }
    }

    $expected = max(1, (int) ($parsedCommand['blockCount'] ?? count($blocks)));
    if (count($blocks) !== $expected) {
        throw new InvalidArgumentException(
            sprintf(
                'Plan has %d block(s) but the command asked for %d. Generate again, then save the full plan.',
                count($blocks),
                $expected
            )
        );
    }

    $departmentId = (string) $parsedCommand['departmentId'];
    $yearLevel = (string) $parsedCommand['yearLevel'];
    $studentType = (string) ($parsedCommand['studentType'] ?? STUDENT_TYPE_REGULAR);
    $scheduleSemester = (string) $parsedCommand['scheduleSemester'];
    $term = normalizeTermFields($academicYear, $scheduleSemester);

    $claimed = claimClassBlocksBatch(
        $departmentId,
        $yearLevel,
        $term['academicYear'],
        $term['semester'],
        $createdBy,
        count($blocks),
        $studentType
    );

    $saved = [];
    $confirmedCount = 0;
    $conflictCount = 0;
    $names = [];

    foreach ($blocks as $idx => $blockOption) {
        $one = saveGeneratedScheduleOption(
            $parsedCommand,
            $blockOption,
            $createdBy,
            $academicYear,
            $claimed[$idx] ?? null
        );
        $saved[] = $one;
        $names[] = $one['blockName'];
        $confirmedCount += (int) $one['confirmedCount'];
        $conflictCount += (int) $one['conflictCount'];
    }

    return [
        'blocks' => $saved,
        'blockNames' => $names,
        'confirmedCount' => $confirmedCount,
        'conflictCount' => $conflictCount,
    ];
}
