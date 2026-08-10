<?php

declare(strict_types=1);

/**
 * AI-based schedule optimization engine (Objective 3).
 *
 * generateScheduleOptions() returns ranked, conflict-free candidate options
 * WITHOUT writing to the schedule table. Confirmation (Script 3) persists rows.
 *
 * Scoring heuristics (higher is better):
 *   - room utilization efficiency (minimize wasted capacity)
 *   - balanced faculty load
 *   - minimal gaps in the student day
 */

require_once __DIR__ . '/Subject.php';
require_once __DIR__ . '/Schedule.php';

const OPTIMIZER_DAYS = [
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
    'Sunday',
];

/** Default 90-minute teaching slots (HH:MM). */
const OPTIMIZER_SLOTS = [
    ['08:00', '09:30'],
    ['09:30', '11:00'],
    ['11:00', '12:30'],
    ['13:00', '14:30'],
    ['14:30', '16:00'],
    ['16:00', '17:30'],
];

/**
 * Generate ranked conflict-free schedule options.
 *
 * @param array{
 *   departmentId:string,
 *   yearLevel:string,
 *   semester:string,
 *   subjects:list<array<string,mixed>>,
 *   faculty:list<array<string,mixed>>,
 *   rooms:list<array<string,mixed>>,
 *   blocks?:list<array<string,mixed>>,
 *   expectedHeadcount?:int,
 *   optionCount?:int,
 *   populationSize?:int,
 *   generations?:int,
 *   seed?:int|null
 * } $input
 * @return array{
 *   departmentId:string,
 *   yearLevel:string,
 *   semester:string,
 *   expectedHeadcount:int,
 *   options:list<array<string,mixed>>
 * }
 */
function generateScheduleOptions(array $input): array
{
    $departmentId = trim((string) ($input['departmentId'] ?? ''));
    $yearLevel = trim((string) ($input['yearLevel'] ?? ''));
    $semester = trim((string) ($input['semester'] ?? ''));
    $subjects = array_values($input['subjects'] ?? []);
    $faculty = array_values($input['faculty'] ?? []);
    $rooms = array_values($input['rooms'] ?? []);
    $blocks = array_values($input['blocks'] ?? []);
    $optionCount = max(1, (int) ($input['optionCount'] ?? 3));
    $populationSize = max(20, (int) ($input['populationSize'] ?? 48));
    $generations = max(10, (int) ($input['generations'] ?? 60));
    $seed = $input['seed'] ?? null;
    $dayCount = isset($input['dayCount']) && $input['dayCount'] !== null
        ? max(1, min(7, (int) $input['dayCount']))
        : null;
    $allowedDays = null;
    if (!empty($input['allowedDays']) && is_array($input['allowedDays'])) {
        $allowedDays = [];
        foreach ($input['allowedDays'] as $day) {
            $day = trim((string) $day);
            if ($day !== '') {
                $allowedDays[] = $day;
            }
        }
        if ($allowedDays === []) {
            $allowedDays = null;
        } else {
            $dayCount = count($allowedDays);
        }
    }
    $reservedAssignments = array_values($input['reservedAssignments'] ?? []);

    if ($departmentId === '') {
        throw new InvalidArgumentException('departmentId is required.');
    }
    if (!in_array($yearLevel, SUBJECT_YEAR_LEVELS, true)) {
        throw new InvalidArgumentException('yearLevel must be one of: ' . implode(', ', SUBJECT_YEAR_LEVELS));
    }
    if (!in_array($semester, SUBJECT_SEMESTERS, true)) {
        throw new InvalidArgumentException('semester must be one of: ' . implode(', ', SUBJECT_SEMESTERS));
    }
    if ($subjects === []) {
        throw new InvalidArgumentException('At least one subject is required.');
    }
    // Faculty may be empty — subjects then schedule as TBF (unassigned instructor).
    if ($rooms === []) {
        throw new InvalidArgumentException('At least one room is required.');
    }

    $expectedHeadcount = isset($input['expectedHeadcount'])
        ? max(1, (int) $input['expectedHeadcount'])
        : max(1, optimizerEstimateHeadcount($faculty, $rooms, $blocks));

    if ($seed !== null) {
        mt_srand((int) $seed);
    }

    $slotCatalog = optimizerBuildSlotCatalog($dayCount, $allowedDays);
    if ($slotCatalog === []) {
        throw new InvalidArgumentException('No time slots available for the requested day range.');
    }

    $population = [];
    for ($i = 0; $i < $populationSize; $i++) {
        $population[] = optimizerRandomChromosome(
            $subjects,
            $faculty,
            $rooms,
            $slotCatalog,
            $expectedHeadcount,
            $reservedAssignments
        );
    }

    $elite = [];
    for ($gen = 0; $gen < $generations; $gen++) {
        $scored = [];
        foreach ($population as $chrome) {
            $eval = optimizerEvaluate(
                $chrome,
                $subjects,
                $faculty,
                $rooms,
                $expectedHeadcount,
                $reservedAssignments
            );
            $scored[] = ['chromosome' => $chrome, 'eval' => $eval];
        }

        usort($scored, static function (array $a, array $b): int {
            return $b['eval']['total'] <=> $a['eval']['total'];
        });

        foreach ($scored as $item) {
            if (!$item['eval']['conflictFree']) {
                continue;
            }
            $sig = optimizerSignature($item['chromosome']);
            if (!isset($elite[$sig])) {
                $elite[$sig] = $item;
            }
        }

        // Keep top half + fresh mutants for diversity.
        $next = [];
        $keep = (int) max(4, floor($populationSize / 2));
        for ($i = 0; $i < $keep && $i < count($scored); $i++) {
            $next[] = $scored[$i]['chromosome'];
        }
        while (count($next) < $populationSize) {
            $p1 = $scored[mt_rand(0, min(count($scored), $keep) - 1)]['chromosome'];
            $p2 = $scored[mt_rand(0, min(count($scored), $keep) - 1)]['chromosome'];
            $child = optimizerCrossover($p1, $p2);
            if (mt_rand(0, 100) < 35) {
                $child = optimizerMutate(
                    $child,
                    $faculty,
                    $rooms,
                    $slotCatalog,
                    $expectedHeadcount,
                    $reservedAssignments
                );
            }
            $next[] = $child;
        }
        // Inject a few random individuals each generation to escape local optima.
        for ($i = 0; $i < 3; $i++) {
            $next[mt_rand(0, count($next) - 1)] = optimizerRandomChromosome(
                $subjects,
                $faculty,
                $rooms,
                $slotCatalog,
                $expectedHeadcount,
                $reservedAssignments
            );
        }
        $population = $next;
    }

    // Final sweep.
    foreach ($population as $chrome) {
        $eval = optimizerEvaluate(
            $chrome,
            $subjects,
            $faculty,
            $rooms,
            $expectedHeadcount,
            $reservedAssignments
        );
        if (!$eval['conflictFree']) {
            continue;
        }
        $sig = optimizerSignature($chrome);
        if (!isset($elite[$sig]) || $eval['total'] > $elite[$sig]['eval']['total']) {
            $elite[$sig] = ['chromosome' => $chrome, 'eval' => $eval];
        }
    }

    $ranked = array_values($elite);
    usort($ranked, static function (array $a, array $b): int {
        return $b['eval']['total'] <=> $a['eval']['total'];
    });

    // If GA found fewer than optionCount conflict-free solutions, run repair search.
    $attempts = 0;
    while (count($ranked) < $optionCount && $attempts < 200) {
        $attempts++;
        $chrome = optimizerRandomChromosome(
            $subjects,
            $faculty,
            $rooms,
            $slotCatalog,
            $expectedHeadcount,
            $reservedAssignments
        );
        $chrome = optimizerRepair(
            $chrome,
            $subjects,
            $faculty,
            $rooms,
            $slotCatalog,
            $expectedHeadcount,
            $reservedAssignments
        );
        $eval = optimizerEvaluate(
            $chrome,
            $subjects,
            $faculty,
            $rooms,
            $expectedHeadcount,
            $reservedAssignments
        );
        if (!$eval['conflictFree']) {
            continue;
        }
        $sig = optimizerSignature($chrome);
        if (isset($elite[$sig])) {
            continue;
        }
        $elite[$sig] = ['chromosome' => $chrome, 'eval' => $eval];
        $ranked = array_values($elite);
        usort($ranked, static function (array $a, array $b): int {
            return $b['eval']['total'] <=> $a['eval']['total'];
        });
    }

    $options = [];
    $take = array_slice($ranked, 0, $optionCount);
    $rank = 1;
    foreach ($take as $item) {
        $assignments = [];
        foreach ($item['chromosome'] as $gene) {
            $subject = optimizerFindByUid($subjects, (string) $gene['subjectId']);
            $room = optimizerFindByUid($rooms, (string) $gene['roomId']);
            $facultyId = optimizerFacultyId($gene['facultyId'] ?? '');
            $fac = $facultyId !== '' ? optimizerFindByUid($faculty, $facultyId) : null;
            $assignments[] = [
                'subjectId' => (string) $gene['subjectId'],
                'subjectCode' => (string) ($subject['code'] ?? ''),
                'subjectTitle' => (string) ($subject['title'] ?? ''),
                'units' => isset($subject['units']) ? (float) $subject['units'] : null,
                'facultyId' => $facultyId,
                'facultyName' => $facultyId !== ''
                    ? (string) ($fac['fullName'] ?? $fac['name'] ?? '')
                    : 'TBF',
                'roomId' => (string) $gene['roomId'],
                'roomLabel' => (string) ($room['label'] ?? (($room['building'] ?? '') . ' / ' . ($room['name'] ?? ''))),
                'roomCapacity' => isset($room['capacity']) ? (int) $room['capacity'] : null,
                'day' => (string) $gene['day'],
                'startTime' => (string) $gene['startTime'],
                'endTime' => (string) $gene['endTime'],
            ];
        }

        usort($assignments, static function (array $a, array $b): int {
            $dayOrder = array_flip(OPTIMIZER_DAYS);
            $da = $dayOrder[$a['day']] ?? 99;
            $db = $dayOrder[$b['day']] ?? 99;
            if ($da !== $db) {
                return $da <=> $db;
            }
            return strcmp($a['startTime'], $b['startTime']);
        });

        $options[] = [
            'rank' => $rank,
            'score' => round($item['eval']['total'], 4),
            'scoreBreakdown' => [
                'roomUtilization' => round($item['eval']['roomUtilization'], 4),
                'facultyBalance' => round($item['eval']['facultyBalance'], 4),
                'studentDayCompactness' => round($item['eval']['studentDayCompactness'], 4),
            ],
            'conflictFree' => true,
            'assignments' => $assignments,
        ];
        $rank++;
    }

    return [
        'departmentId' => $departmentId,
        'yearLevel' => $yearLevel,
        'semester' => $semester,
        'expectedHeadcount' => $expectedHeadcount,
        'options' => $options,
        'meta' => [
            'populationSize' => $populationSize,
            'generations' => $generations,
            'candidatesFound' => count($elite),
            'dayCount' => $dayCount,
            'allowedDays' => $allowedDays,
            'note' => 'Draft options only — confirm via Schedule Management to write schedule rows.',
        ],
    ];
}

/**
 * Build mock-ready inputs from the live database for a curriculum slice.
 *
 * @return array<string,mixed>
 */
function buildScheduleOptimizerInputFromDb(
    string $departmentId,
    string $yearLevel,
    string $semester,
    int $optionCount = 3,
    ?int $curriculumYear = null
): array {
    require_once dirname(__DIR__) . '/config/database.php';

    $subjects = fetchSubjects(
        $departmentId,
        $yearLevel,
        $semester,
        SUBJECT_STATUS_ACTIVE,
        '',
        $curriculumYear
    );

    $facultyStmt = db()->prepare(
        "SELECT uid, firstName, lastName, departmentId
         FROM `user`
         WHERE role = 'Faculty' AND status = 'Active'
           AND (departmentId = :departmentId OR departmentId IS NULL)
         ORDER BY lastName, firstName"
    );
    $facultyStmt->execute([':departmentId' => $departmentId]);
    $faculty = array_map(static function (array $row): array {
        return [
            'uid' => (string) $row['uid'],
            'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
            'departmentId' => $row['departmentId'] !== null ? (string) $row['departmentId'] : null,
        ];
    }, $facultyStmt->fetchAll());

    $roomsStmt = db()->query(
        'SELECT uid, name, building, capacity, roomType FROM room ORDER BY building, name'
    );
    $rooms = array_map(static function (array $row): array {
        return [
            'uid' => (string) $row['uid'],
            'name' => (string) $row['name'],
            'building' => (string) $row['building'],
            'capacity' => (int) $row['capacity'],
            'roomType' => strtoupper((string) ($row['roomType'] ?? 'LECTURE')),
            'label' => (string) $row['building'] . ' / ' . (string) $row['name'],
        ];
    }, $roomsStmt->fetchAll());

    $blockStmt = db()->prepare(
        "SELECT uid, studentId, departmentId, status
         FROM block
         WHERE departmentId = :departmentId AND status = 'Active'"
    );
    $blockStmt->execute([':departmentId' => $departmentId]);
    $blocks = $blockStmt->fetchAll();

    $studentStmt = db()->prepare(
        "SELECT COUNT(*) FROM `user`
         WHERE role = 'Student' AND status = 'Active' AND departmentId = :departmentId"
    );
    $studentStmt->execute([':departmentId' => $departmentId]);
    $studentCount = (int) $studentStmt->fetchColumn();
    $blockedCount = count($blocks);
    $expectedHeadcount = max(1, $studentCount - $blockedCount);

    return [
        'departmentId' => $departmentId,
        'yearLevel' => $yearLevel,
        'semester' => $semester,
        'curriculumYear' => $curriculumYear,
        'subjects' => $subjects,
        'faculty' => $faculty,
        'rooms' => $rooms,
        'blocks' => $blocks,
        'expectedHeadcount' => $expectedHeadcount,
        'optionCount' => $optionCount,
        'reservedAssignments' => fetchTermScheduleReservations($departmentId),
    ];
}

/**
 * @param list<string>|null $allowedDays
 * @return list<array{day:string,startTime:string,endTime:string,slotIndex:int}>
 */
function optimizerBuildSlotCatalog(?int $dayCount = null, ?array $allowedDays = null): array
{
    $weekOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    if ($allowedDays !== null && $allowedDays !== []) {
        $wanted = [];
        foreach ($allowedDays as $day) {
            foreach ($weekOrder as $canonical) {
                if (strcasecmp((string) $day, $canonical) === 0) {
                    $wanted[$canonical] = true;
                    break;
                }
            }
        }
        $days = [];
        foreach ($weekOrder as $canonical) {
            if (isset($wanted[$canonical])) {
                $days[] = $canonical;
            }
        }
    } else {
        $days = OPTIMIZER_DAYS;
        if ($dayCount !== null && $dayCount > 0) {
            $days = array_slice(OPTIMIZER_DAYS, 0, min($dayCount, count(OPTIMIZER_DAYS)));
        }
    }

    $catalog = [];
    $i = 0;
    foreach ($days as $day) {
        foreach (OPTIMIZER_SLOTS as $slot) {
            $catalog[] = [
                'day' => $day,
                'startTime' => $slot[0],
                'endTime' => $slot[1],
                'slotIndex' => $i,
            ];
            $i++;
        }
    }
    return $catalog;
}

/**
 * @param list<array<string,mixed>> $subjects
 * @param list<array<string,mixed>> $faculty
 * @param list<array<string,mixed>> $rooms
 * @param list<array<string,mixed>> $slotCatalog
 * @param list<array<string,mixed>> $reserved
 * @return list<array<string,mixed>>
 */
function optimizerRandomChromosome(
    array $subjects,
    array $faculty,
    array $rooms,
    array $slotCatalog,
    int $expectedHeadcount,
    array $reserved = []
): array {
    $chrome = [];
    $usedSlots = [];
    foreach ($subjects as $subject) {
        $usableRooms = optimizerRoomsForSubject($rooms, $subject, $expectedHeadcount);
        if ($usableRooms === []) {
            $fallbackSlot = $slotCatalog[0];
            $chrome[] = [
                'subjectId' => (string) $subject['uid'],
                'facultyId' => '',
                'roomId' => '',
                'day' => (string) $fallbackSlot['day'],
                'startTime' => (string) $fallbackSlot['startTime'],
                'endTime' => (string) $fallbackSlot['endTime'],
            ];
            continue;
        }
        $picked = null;
        for ($attempts = 0; $attempts < 60; $attempts++) {
            $slot = $slotCatalog[mt_rand(0, count($slotCatalog) - 1)];
            $key = $slot['day'] . '|' . $slot['startTime'];
            if (isset($usedSlots[$key])) {
                continue;
            }
            $room = $usableRooms[mt_rand(0, count($usableRooms) - 1)];
            $probe = [
                'day' => (string) $slot['day'],
                'startTime' => (string) $slot['startTime'],
                'endTime' => (string) $slot['endTime'],
                'roomId' => (string) $room['uid'],
                'facultyId' => '', // Dean assigns instructor later (TBF)
            ];
            // Room must be free vs reserved + in-progress chromosome.
            if (optimizerRoomBusyAt((string) $room['uid'], $probe, $chrome, $reserved, null)) {
                continue;
            }
            if (optimizerConflictsWithReserved($probe, $reserved)) {
                continue;
            }
            $picked = [
                'subjectId' => (string) $subject['uid'],
                'facultyId' => '',
                'roomId' => (string) $room['uid'],
                'day' => (string) $slot['day'],
                'startTime' => (string) $slot['startTime'],
                'endTime' => (string) $slot['endTime'],
            ];
            $usedSlots[$key] = true;
            break;
        }
        if ($picked === null) {
            // Exhaustive search: only place when a free room exists.
            foreach ($slotCatalog as $slot) {
                $key = $slot['day'] . '|' . $slot['startTime'];
                if (isset($usedSlots[$key])) {
                    continue;
                }
                foreach ($usableRooms as $room) {
                    $probe = [
                        'day' => (string) $slot['day'],
                        'startTime' => (string) $slot['startTime'],
                        'endTime' => (string) $slot['endTime'],
                        'roomId' => (string) $room['uid'],
                        'facultyId' => '',
                    ];
                    if (optimizerRoomBusyAt((string) $room['uid'], $probe, $chrome, $reserved, null)) {
                        continue;
                    }
                    if (optimizerConflictsWithReserved($probe, $reserved)) {
                        continue;
                    }
                    $picked = [
                        'subjectId' => (string) $subject['uid'],
                        'facultyId' => '',
                        'roomId' => (string) $room['uid'],
                        'day' => (string) $slot['day'],
                        'startTime' => (string) $slot['startTime'],
                        'endTime' => (string) $slot['endTime'],
                    ];
                    $usedSlots[$key] = true;
                    break 2;
                }
            }
        }
        if ($picked === null) {
            // No available room for this subject — mark invalid (fails conflictFree).
            $fallbackSlot = $slotCatalog[0];
            $picked = [
                'subjectId' => (string) $subject['uid'],
                'facultyId' => '',
                'roomId' => '',
                'day' => (string) $fallbackSlot['day'],
                'startTime' => (string) $fallbackSlot['startTime'],
                'endTime' => (string) $fallbackSlot['endTime'],
            ];
        }
        $chrome[] = $picked;
    }
    return $chrome;
}

/**
 * Prefer rooms matching the subject's preferredRoomType (LAB / LECTURE).
 *
 * @param list<array<string,mixed>> $rooms
 * @param array<string,mixed> $subject
 * @return list<array<string,mixed>>
 */
function optimizerRoomsForSubject(array $rooms, array $subject, int $expectedHeadcount): array
{
    $preferred = strtoupper((string) ($subject['preferredRoomType'] ?? 'LECTURE'));
    if ($preferred !== 'LAB' && $preferred !== 'LECTURE') {
        $preferred = 'LECTURE';
    }

    $byCapacity = array_values(array_filter(
        $rooms,
        static fn (array $r): bool => (int) ($r['capacity'] ?? 0) >= $expectedHeadcount
    ));
    if ($byCapacity === []) {
        $byCapacity = $rooms;
    }

    $typed = array_values(array_filter(
        $byCapacity,
        static fn (array $r): bool => strtoupper((string) ($r['roomType'] ?? 'LECTURE')) === $preferred
    ));

    return $typed !== [] ? $typed : $byCapacity;
}

/**
 * @param list<array<string,mixed>> $a
 * @param list<array<string,mixed>> $b
 * @return list<array<string,mixed>>
 */
function optimizerCrossover(array $a, array $b): array
{
    $child = [];
    $n = count($a);
    for ($i = 0; $i < $n; $i++) {
        $child[] = mt_rand(0, 1) === 0 ? $a[$i] : $b[$i];
    }
    return $child;
}

/**
 * @param list<array<string,mixed>> $chrome
 * @param list<array<string,mixed>> $faculty
 * @param list<array<string,mixed>> $rooms
 * @param list<array<string,mixed>> $slotCatalog
 * @param list<array<string,mixed>> $reserved
 * @return list<array<string,mixed>>
 */
function optimizerMutate(
    array $chrome,
    array $faculty,
    array $rooms,
    array $slotCatalog,
    int $expectedHeadcount,
    array $reserved = []
): array {
    $usableRooms = array_values(array_filter(
        $rooms,
        static fn (array $r): bool => (int) ($r['capacity'] ?? 0) >= $expectedHeadcount
    ));
    if ($usableRooms === []) {
        $usableRooms = $rooms;
    }

    $idx = mt_rand(0, count($chrome) - 1);
    if (mt_rand(0, 1) === 0) {
        $slot = $slotCatalog[mt_rand(0, count($slotCatalog) - 1)];
        $chrome[$idx]['day'] = $slot['day'];
        $chrome[$idx]['startTime'] = $slot['startTime'];
        $chrome[$idx]['endTime'] = $slot['endTime'];
    }

    // Always re-pick a free room for the (possibly new) slot; else unplaceable.
    $freeRooms = [];
    foreach ($usableRooms as $room) {
        $probe = [
            'day' => (string) $chrome[$idx]['day'],
            'startTime' => (string) $chrome[$idx]['startTime'],
            'endTime' => (string) $chrome[$idx]['endTime'],
            'roomId' => (string) $room['uid'],
            'facultyId' => '',
        ];
        if (!optimizerRoomBusyAt((string) $room['uid'], $probe, $chrome, $reserved, $idx)
            && !optimizerConflictsWithReserved($probe, $reserved)
        ) {
            $freeRooms[] = $room;
        }
    }
    $chrome[$idx]['roomId'] = $freeRooms === []
        ? ''
        : (string) $freeRooms[mt_rand(0, count($freeRooms) - 1)]['uid'];
    // Instructor stays TBF — Dean assigns subject → faculty later.
    $chrome[$idx]['facultyId'] = '';
    return $chrome;
}

/**
 * Greedy repair pass: reassign conflicting genes to free slots/resources.
 *
 * @param list<array<string,mixed>> $chrome
 * @param list<array<string,mixed>> $subjects
 * @param list<array<string,mixed>> $faculty
 * @param list<array<string,mixed>> $rooms
 * @param list<array<string,mixed>> $slotCatalog
 * @param list<array<string,mixed>> $reserved
 * @return list<array<string,mixed>>
 */
function optimizerRepair(
    array $chrome,
    array $subjects,
    array $faculty,
    array $rooms,
    array $slotCatalog,
    int $expectedHeadcount,
    array $reserved = []
): array {
    $usableRooms = array_values(array_filter(
        $rooms,
        static fn (array $r): bool => (int) ($r['capacity'] ?? 0) >= $expectedHeadcount
    ));
    if ($usableRooms === []) {
        $usableRooms = $rooms;
    }

    for ($pass = 0; $pass < 8; $pass++) {
        $eval = optimizerEvaluate($chrome, $subjects, $faculty, $rooms, $expectedHeadcount, $reserved);
        if ($eval['conflictFree']) {
            return $chrome;
        }
        foreach ($eval['conflictIndexes'] as $idx) {
            foreach ($slotCatalog as $slot) {
                foreach ($usableRooms as $room) {
                    $trial = $chrome;
                    $candidate = [
                        'subjectId' => $chrome[$idx]['subjectId'],
                        'facultyId' => '', // Dean assigns later
                        'roomId' => (string) $room['uid'],
                        'day' => (string) $slot['day'],
                        'startTime' => (string) $slot['startTime'],
                        'endTime' => (string) $slot['endTime'],
                    ];
                    if (optimizerRoomBusyAt(
                        $candidate['roomId'],
                        $candidate,
                        $trial,
                        $reserved,
                        $idx
                    )) {
                        continue;
                    }
                    $trial[$idx] = $candidate;
                    $trialEval = optimizerEvaluate(
                        $trial,
                        $subjects,
                        $faculty,
                        $rooms,
                        $expectedHeadcount,
                        $reserved
                    );
                    if (count($trialEval['conflictIndexes']) < count($eval['conflictIndexes'])) {
                        $chrome = $trial;
                        break 2;
                    }
                }
            }
        }
    }
    return $chrome;
}

/**
 * @param array<string,mixed> $gene
 * @param list<array<string,mixed>> $reserved
 */
function optimizerConflictsWithReserved(array $gene, array $reserved): bool
{
    foreach ($reserved as $other) {
        if (!optimizerTimesOverlap($gene, $other)) {
            continue;
        }
        $sameRoom = (string) ($gene['roomId'] ?? '') === (string) ($other['roomId'] ?? '');
        if ($sameRoom || optimizerSameFaculty($gene, $other)) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array<string,mixed>> $chrome
 * @param list<array<string,mixed>> $subjects
 * @param list<array<string,mixed>> $faculty
 * @param list<array<string,mixed>> $rooms
 * @param list<array<string,mixed>> $reserved
 * @return array{
 *   total:float,
 *   roomUtilization:float,
 *   facultyBalance:float,
 *   studentDayCompactness:float,
 *   conflictFree:bool,
 *   conflictIndexes:list<int>
 * }
 */
function optimizerEvaluate(
    array $chrome,
    array $subjects,
    array $faculty,
    array $rooms,
    int $expectedHeadcount,
    array $reserved = []
): array {
    $conflictIndexes = [];
    $n = count($chrome);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            if (!optimizerTimesOverlap($chrome[$i], $chrome[$j])) {
                continue;
            }
            $sameRoom = $chrome[$i]['roomId'] === $chrome[$j]['roomId']
                && trim((string) $chrome[$i]['roomId']) !== '';
            $sameFac = optimizerSameFaculty($chrome[$i], $chrome[$j]);
            if ($sameRoom || $sameFac) {
                $conflictIndexes[$i] = $i;
                $conflictIndexes[$j] = $j;
            }
        }
        if (trim((string) ($chrome[$i]['roomId'] ?? '')) === '') {
            $conflictIndexes[$i] = $i;
        }
        if (optimizerConflictsWithReserved($chrome[$i], $reserved)) {
            $conflictIndexes[$i] = $i;
        }
    }
    $conflictIndexes = array_values($conflictIndexes);
    $conflictFree = $conflictIndexes === [];

    // Room utilization: prefer rooms close to expected headcount (less waste).
    $utilScores = [];
    foreach ($chrome as $gene) {
        $room = optimizerFindByUid($rooms, (string) $gene['roomId']);
        $cap = max(1, (int) ($room['capacity'] ?? 1));
        if ($cap < $expectedHeadcount) {
            $utilScores[] = 0.1;
            continue;
        }
        $waste = ($cap - $expectedHeadcount) / $cap;
        $utilScores[] = max(0.0, 1.0 - $waste);
    }
    $roomUtilization = $utilScores === [] ? 0.0 : array_sum($utilScores) / count($utilScores);

    // Faculty load balance across the full faculty pool (unused = load 0).
    $loads = [];
    foreach ($faculty as $fac) {
        $loads[(string) $fac['uid']] = 0;
    }
    foreach ($chrome as $gene) {
        $fid = optimizerFacultyId($gene['facultyId'] ?? '');
        if ($fid === '') {
            continue;
        }
        $loads[$fid] = ($loads[$fid] ?? 0) + 1;
    }
    $loadValues = array_values($loads);
    $mean = array_sum($loadValues) / max(1, count($loadValues));
    $variance = 0.0;
    foreach ($loadValues as $v) {
        $variance += ($v - $mean) ** 2;
    }
    $variance = $variance / max(1, count($loadValues));
    $facultyBalance = 1.0 / (1.0 + $variance);

    // Student-day compactness: minimize idle gaps between classes on the same day.
    $byDay = [];
    foreach ($chrome as $gene) {
        $byDay[$gene['day']][] = optimizerMinutes((string) $gene['startTime']);
    }
    $gapPenalties = [];
    foreach ($byDay as $starts) {
        sort($starts);
        if (count($starts) < 2) {
            $gapPenalties[] = 1.0;
            continue;
        }
        $gaps = [];
        for ($i = 1; $i < count($starts); $i++) {
            $gap = $starts[$i] - $starts[$i - 1];
            // Ideal adjacent slot gap ~90 minutes; penalize larger idle stretches.
            $idle = max(0, $gap - 90);
            $gaps[] = 1.0 / (1.0 + ($idle / 60.0));
        }
        $gapPenalties[] = array_sum($gaps) / count($gaps);
    }
    $studentDayCompactness = $gapPenalties === []
        ? 1.0
        : array_sum($gapPenalties) / count($gapPenalties);

    $total = 0.0;
    if ($conflictFree) {
        $total = (0.40 * $roomUtilization)
            + (0.30 * $facultyBalance)
            + (0.30 * $studentDayCompactness);
    } else {
        $total = -1.0 * count($conflictIndexes);
    }

    return [
        'total' => $total,
        'roomUtilization' => $roomUtilization,
        'facultyBalance' => $facultyBalance,
        'studentDayCompactness' => $studentDayCompactness,
        'conflictFree' => $conflictFree,
        'conflictIndexes' => $conflictIndexes,
    ];
}

/**
 * Normalize faculty id; empty string means TBF (unassigned).
 */
function optimizerFacultyId(mixed $value): string
{
    $raw = trim((string) ($value ?? ''));
    if ($raw === '' || strcasecmp($raw, 'TBF') === 0) {
        return '';
    }
    return $raw;
}

/**
 * Two meetings share a real instructor (TBF never conflicts with TBF/faculty).
 */
function optimizerSameFaculty(array $a, array $b): bool
{
    $fa = optimizerFacultyId($a['facultyId'] ?? '');
    $fb = optimizerFacultyId($b['facultyId'] ?? '');
    return $fa !== '' && $fa === $fb;
}

/**
 * @param list<array<string,mixed>> $chrome
 * @param list<array<string,mixed>> $reserved
 */
function optimizerFacultyBusyAt(
    string $facultyId,
    array $probe,
    array $chrome,
    array $reserved,
    ?int $skipIndex
): bool {
    $facultyId = optimizerFacultyId($facultyId);
    if ($facultyId === '') {
        return false;
    }
    foreach ($chrome as $i => $other) {
        if ($skipIndex !== null && $i === $skipIndex) {
            continue;
        }
        if (!optimizerTimesOverlap($probe, $other)) {
            continue;
        }
        if (optimizerFacultyId($other['facultyId'] ?? '') === $facultyId) {
            return true;
        }
    }
    foreach ($reserved as $other) {
        if (!optimizerTimesOverlap($probe, $other)) {
            continue;
        }
        if (optimizerFacultyId($other['facultyId'] ?? '') === $facultyId) {
            return true;
        }
    }
    return false;
}

/**
 * @param list<array<string,mixed>> $chrome
 * @param list<array<string,mixed>> $reserved
 */
function optimizerRoomBusyAt(
    string $roomId,
    array $probe,
    array $chrome,
    array $reserved,
    ?int $skipIndex
): bool {
    $roomId = trim($roomId);
    if ($roomId === '') {
        return false;
    }
    foreach ($chrome as $i => $other) {
        if ($skipIndex !== null && $i === $skipIndex) {
            continue;
        }
        if (!optimizerTimesOverlap($probe, $other)) {
            continue;
        }
        if ((string) ($other['roomId'] ?? '') === $roomId) {
            return true;
        }
    }
    foreach ($reserved as $other) {
        if (!optimizerTimesOverlap($probe, $other)) {
            continue;
        }
        if ((string) ($other['roomId'] ?? '') === $roomId) {
            return true;
        }
    }
    return false;
}

/**
 * Prefer a free instructor for this subject slot; otherwise TBF (empty).
 *
 * @param list<array<string,mixed>> $faculty
 * @param list<array<string,mixed>> $chrome
 * @param list<array<string,mixed>> $reserved
 */
function optimizerPickFacultyForSlot(
    array $faculty,
    array $probe,
    array $chrome,
    array $reserved,
    ?int $skipIndex
): string {
    $free = [];
    foreach ($faculty as $fac) {
        $uid = optimizerFacultyId($fac['uid'] ?? '');
        if ($uid === '') {
            continue;
        }
        if (!optimizerFacultyBusyAt($uid, $probe, $chrome, $reserved, $skipIndex)) {
            $free[] = $uid;
        }
    }
    if ($free === []) {
        return ''; // TBF — to be followed / assigned later
    }
    return $free[mt_rand(0, count($free) - 1)];
}

/**
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 */
function optimizerTimesOverlap(array $a, array $b): bool
{
    if (strcasecmp((string) $a['day'], (string) $b['day']) !== 0) {
        return false;
    }
    $aStart = optimizerMinutes((string) $a['startTime']);
    $aEnd = optimizerMinutes((string) $a['endTime']);
    $bStart = optimizerMinutes((string) $b['startTime']);
    $bEnd = optimizerMinutes((string) $b['endTime']);
    return $aStart < $bEnd && $bStart < $aEnd;
}

function optimizerMinutes(string $hhmm): int
{
    $parts = explode(':', $hhmm);
    return ((int) $parts[0] * 60) + (int) ($parts[1] ?? 0);
}

/**
 * @param list<array<string,mixed>> $chrome
 */
function optimizerSignature(array $chrome): string
{
    $parts = [];
    foreach ($chrome as $gene) {
        $parts[] = implode(':', [
            $gene['subjectId'],
            $gene['facultyId'],
            $gene['roomId'],
            $gene['day'],
            $gene['startTime'],
        ]);
    }
    sort($parts);
    return implode('|', $parts);
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array<string,mixed>|null
 */
function optimizerFindByUid(array $rows, string $uid): ?array
{
    foreach ($rows as $row) {
        if ((string) ($row['uid'] ?? '') === $uid) {
            return $row;
        }
    }
    return null;
}

/**
 * Fallback headcount estimate when not provided.
 *
 * @param list<array<string,mixed>> $faculty
 * @param list<array<string,mixed>> $rooms
 * @param list<array<string,mixed>> $blocks
 */
function optimizerEstimateHeadcount(array $faculty, array $rooms, array $blocks): int
{
    $minCap = PHP_INT_MAX;
    foreach ($rooms as $room) {
        $cap = (int) ($room['capacity'] ?? 0);
        if ($cap > 0 && $cap < $minCap) {
            $minCap = $cap;
        }
    }
    if ($minCap === PHP_INT_MAX) {
        $minCap = 30;
    }
    // Blocked students reduce effective section demand slightly for capacity planning.
    $blocked = count($blocks);
    return max(1, (int) round($minCap * 0.7) - $blocked);
}
