<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/ScheduleCommand.php';

$cases = [
    'create me a schedule for regular 1st year, refer to the 2026 curriculum.',
    '3rd year regular 2026 curriculum, 3 blocks 2 days schedule',
    '3rd year regular 2026 curriculum, 3 blocks M T',
    '1st year regular 2026, 2 blocks Mon Tue',
    '2nd year 2026 curriculum MW',
];

foreach ($cases as $cmd) {
    $parsed = parseScheduleCommand($cmd, 'dept-comp');
    $parsed = applyScheduleCommandSemesterDefault($parsed, null);
    echo $cmd . PHP_EOL;
    echo '  → ' . $parsed['confirmSummary'] . PHP_EOL;
    echo '  days=' . json_encode($parsed['days']) .
        ' dayCount=' . ($parsed['dayCount'] ?? 'full') .
        ' blocks=' . $parsed['blockCount'] . PHP_EOL . PHP_EOL;
}

$mt = parseScheduleCommand('3rd year regular 2026 curriculum, 3 blocks M T', 'dept-comp');
if (($mt['days'] ?? []) !== ['Monday', 'Tuesday'] || (int) $mt['blockCount'] !== 3) {
    fwrite(STDERR, 'FAIL: expected Mon/Tue + 3 blocks, got ' . json_encode($mt['days']) . PHP_EOL);
    exit(1);
}

$named = parseScheduleCommand('1st year regular 2026, 2 blocks Mon Tue', 'dept-comp');
if (($named['days'] ?? []) !== ['Monday', 'Tuesday']) {
    fwrite(STDERR, 'FAIL: Mon Tue parse' . PHP_EOL);
    exit(1);
}

$mw = parseScheduleCommand('2nd year 2026 curriculum MW', 'dept-comp');
if (($mw['days'] ?? []) !== ['Monday', 'Wednesday']) {
    fwrite(STDERR, 'FAIL: MW parse' . PHP_EOL);
    exit(1);
}

echo "PASS day-token parser smoke.\n";
