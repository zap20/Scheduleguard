<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/FacultyLoad.php';

$commands = [
    '8 load IT 322',
    '8 loads IT 322',
    '8 loads',
    'IT 322 8 loads',
];

foreach ($commands as $cmd) {
    $parsed = parseFacultyLoadCommand($cmd);
    echo $cmd . ' => load=' . $parsed['targetLoad'] . ' subject=' . ($parsed['subjectCode'] ?: '(any)') . PHP_EOL;
}
