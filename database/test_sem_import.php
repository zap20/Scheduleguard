<?php

declare(strict_types=1);

/**
 * Smoke-test semester grid parser.
 *   php database/test_sem_import.php
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/SemScheduleImport.php';

$path = dirname(__DIR__) . '/1st-Sem-7-09.xlsx';
if (!is_file($path)) {
    fwrite(STDERR, "Missing {$path}\n");
    exit(1);
}

echo isSemGridWorkbook($path) ? "Detected semester grid workbook.\n" : "NOT detected as grid.\n";

$rows = parseSemGridScheduleXlsx($path);
echo 'Parsed blocks: ' . count($rows) . "\n\n";

$byTeacher = [];
foreach ($rows as $row) {
    $t = $row['facultyName'];
    $byTeacher[$t] = ($byTeacher[$t] ?? 0) + 1;
}
arsort($byTeacher);
echo "Top teachers:\n";
$i = 0;
foreach ($byTeacher as $t => $n) {
    echo "  {$t}: {$n}\n";
    if (++$i >= 12) {
        break;
    }
}

echo "\nSample rows:\n";
foreach (array_slice($rows, 0, 12) as $row) {
    echo sprintf(
        "  %s | %s | %s-%s | %s | %s | room=%s\n",
        $row['facultyName'],
        $row['day'],
        $row['startTime'],
        $row['endTime'],
        $row['subjectCode'],
        $row['subjectName'],
        $row['roomLabel'] !== '' ? $row['roomLabel'] : '(none)'
    );
}
