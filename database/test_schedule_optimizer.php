<?php

declare(strict_types=1);

/**
 * Standalone smoke test for generateScheduleOptions() with mock data.
 * Does not touch the database.
 *
 *   php database/test_schedule_optimizer.php
 */

require_once dirname(__DIR__) . '/includes/ScheduleOptimizer.php';

$subjects = [
    [
        'uid' => 'subj-it322',
        'code' => 'IT 322',
        'title' => 'Systems Integration and Architecture',
        'units' => 3.0,
        'yearLevel' => '3rd Year',
        'semester' => '1st Semester',
    ],
    [
        'uid' => 'subj-it323',
        'code' => 'IT 323',
        'title' => 'Applications Development and Emerging Technologies',
        'units' => 3.0,
        'yearLevel' => '3rd Year',
        'semester' => '1st Semester',
    ],
    [
        'uid' => 'subj-it324',
        'code' => 'IT 324',
        'title' => 'Information Assurance and Security',
        'units' => 3.0,
        'yearLevel' => '3rd Year',
        'semester' => '1st Semester',
    ],
    [
        'uid' => 'subj-it325',
        'code' => 'IT 325',
        'title' => 'Quantitative Methods',
        'units' => 3.0,
        'yearLevel' => '3rd Year',
        'semester' => '1st Semester',
    ],
    [
        'uid' => 'subj-it326',
        'code' => 'IT 326',
        'title' => 'Networking 2',
        'units' => 3.0,
        'yearLevel' => '3rd Year',
        'semester' => '1st Semester',
    ],
];

$faculty = [
    ['uid' => 'fac-1', 'fullName' => 'Ana Reyes'],
    ['uid' => 'fac-2', 'fullName' => 'Ben Cruz'],
    ['uid' => 'fac-3', 'fullName' => 'Cara Lim'],
];

$rooms = [
    ['uid' => 'room-1', 'name' => 'Lab 101', 'building' => 'Main', 'capacity' => 40, 'label' => 'Main / Lab 101'],
    ['uid' => 'room-2', 'name' => '205', 'building' => 'Main', 'capacity' => 35, 'label' => 'Main / 205'],
    ['uid' => 'room-3', 'name' => 'Hall A', 'building' => 'Annex', 'capacity' => 60, 'label' => 'Annex / Hall A'],
];

$blocks = [
    ['uid' => 'block-1', 'studentId' => 'stu-blocked', 'status' => 'Active'],
];

$result = generateScheduleOptions([
    'departmentId' => 'dept-comp',
    'yearLevel' => '3rd Year',
    'semester' => '1st Semester',
    'subjects' => $subjects,
    'faculty' => $faculty,
    'rooms' => $rooms,
    'blocks' => $blocks,
    'expectedHeadcount' => 32,
    'optionCount' => 3,
    'populationSize' => 40,
    'generations' => 45,
    'seed' => 42,
]);

$optionCount = count($result['options']);
echo "Generated {$optionCount} option(s) for {$result['yearLevel']} / {$result['semester']}\n";
echo "Expected headcount: {$result['expectedHeadcount']}\n\n";

if ($optionCount < 3) {
    fwrite(STDERR, "FAIL: expected at least 3 conflict-free options, got {$optionCount}\n");
    exit(1);
}

foreach ($result['options'] as $option) {
    echo sprintf(
        "Option #%d  score=%.4f  (util=%.3f balance=%.3f compact=%.3f)\n",
        $option['rank'],
        $option['score'],
        $option['scoreBreakdown']['roomUtilization'],
        $option['scoreBreakdown']['facultyBalance'],
        $option['scoreBreakdown']['studentDayCompactness']
    );

    $codes = [];
    foreach ($option['assignments'] as $a) {
        $codes[] = $a['subjectCode'];
        echo sprintf(
            "  %-8s  %-10s %s–%s  %-18s  %s\n",
            $a['subjectCode'],
            $a['day'],
            $a['startTime'],
            $a['endTime'],
            $a['facultyName'],
            $a['roomLabel']
        );
    }

    // Every subject assigned exactly once.
    if (count($option['assignments']) !== count($subjects)) {
        fwrite(STDERR, "FAIL: option #{$option['rank']} missing subject assignments\n");
        exit(1);
    }

    // Hard conflict check.
    $n = count($option['assignments']);
    for ($i = 0; $i < $n; $i++) {
        for ($j = $i + 1; $j < $n; $j++) {
            $a = $option['assignments'][$i];
            $b = $option['assignments'][$j];
            if ($a['day'] !== $b['day']) {
                continue;
            }
            $overlap = $a['startTime'] < $b['endTime'] && $b['startTime'] < $a['endTime'];
            if ($overlap && ($a['roomId'] === $b['roomId'] || $a['facultyId'] === $b['facultyId'])) {
                fwrite(STDERR, "FAIL: option #{$option['rank']} has an internal conflict\n");
                exit(1);
            }
        }
    }
    echo "\n";
}

echo "PASS: optimizer returned {$optionCount} ranked conflict-free draft options (no DB writes).\n";
