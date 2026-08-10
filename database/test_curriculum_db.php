<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Subject.php';
require_once dirname(__DIR__) . '/includes/Schedule.php';
require_once dirname(__DIR__) . '/includes/ScheduleOptimizer.php';

$subjects = fetchSubjects('dept-comp', '3rd Year', '1st Semester');
echo 'subjects(3rd/1st)=' . count($subjects) . PHP_EOL;

$sched = fetchScheduleById('sched-db101');
echo 'sched-db101 code=' . ($sched['subjectCode'] ?? 'null')
    . ' id=' . ($sched['subjectId'] ?? '') . PHP_EOL;

$result = generateScheduleOptions(
    buildScheduleOptimizerInputFromDb('dept-comp', '3rd Year', '1st Semester', 3)
);
echo 'db-backed options=' . count($result['options']) . PHP_EOL;
foreach ($result['options'] as $opt) {
    echo '  #' . $opt['rank'] . ' score=' . $opt['score']
        . ' assignments=' . count($opt['assignments']) . PHP_EOL;
}
