<?php

declare(strict_types=1);

/**
 * Smoke-test student-block grid parser (BSIT Course and Year / Block).
 *   php database/test_student_block_import.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/SemScheduleImport.php';

$path = dirname(__DIR__) . '/public/samples/1st-Sem-7-09.xlsx';
if (!is_file($path)) {
    fwrite(STDERR, "Missing {$path}\n");
    exit(1);
}

echo isSemStudentBlockWorkbook($path) ? "Detected student-block workbook.\n" : "NOT detected.\n";

$groups = parseSemStudentBlockXlsx($path, 'BSIT');
$meetingCount = 0;
$skippedIrreg = 0;
echo 'Class blocks parsed: ' . count($groups) . "\n\n";

foreach ($groups as $group) {
    $n = count($group['meetings']);
    $meetingCount += $n;
    if ($n === 0) {
        continue;
    }
    echo sprintf(
        "  %s | %s | block %s (#%d) | %d meeting(s) | sheet %s\n",
        $group['course'],
        $group['yearLevel'],
        $group['blockLabel'],
        $group['blockNumber'],
        $n,
        $group['sheet']
    );
}

echo "\nTotal meetings: {$meetingCount}\n";
echo "Sample meetings (first block):\n";
$first = $groups[0] ?? null;
if ($first) {
    foreach (array_slice($first['meetings'], 0, 12) as $row) {
        echo sprintf(
            "  %s | %s-%s | %s | %s | room=%s\n",
            $row['day'],
            $row['startTime'],
            $row['endTime'],
            $row['subjectCode'],
            $row['subjectName'],
            $row['roomLabel'] !== '' ? $row['roomLabel'] : '(none)'
        );
    }
}

foreach ($groups as $group) {
    if (stripos($group['course'] . $group['blockLabel'], 'irreg') !== false) {
        $skippedIrreg++;
    }
}
echo "\nIRREG groups leaked: {$skippedIrreg}\n";
