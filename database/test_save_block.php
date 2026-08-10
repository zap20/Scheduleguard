<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/ScheduleCommand.php';

$cmd = 'create me a schedule for regular 1st year, refer to the 2026 curriculum.';
$parsed = applyScheduleCommandSemesterDefault(
    parseScheduleCommand($cmd, 'dept-comp'),
    '1st Semester'
);

$input = buildScheduleOptimizerInputFromDb(
    $parsed['departmentId'],
    (string) $parsed['yearLevel'],
    (string) $parsed['semester'],
    3,
    (int) $parsed['curriculumYear']
);
$result = generateScheduleOptions($input);
if ($result['options'] === []) {
    fwrite(STDERR, "No options\n");
    exit(1);
}

$saved = saveGeneratedScheduleOption($parsed, $result['options'][0], 'user-dean');
echo 'block=' . $saved['blockName'] . ' confirmed=' . $saved['confirmedCount']
    . ' conflict=' . $saved['conflictCount'] . PHP_EOL;

$saved2 = saveGeneratedScheduleOption($parsed, $result['options'][0], 'user-dean');
echo 'block2=' . $saved2['blockName'] . PHP_EOL;

if ($saved['blockName'] !== '1st Year Block 1' || $saved2['blockName'] !== '1st Year Block 2') {
    fwrite(STDERR, "FAIL block numbering\n");
    exit(1);
}
echo "PASS save + block increment.\n";
