<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/FacultyLoad.php';

$dept = 'dept-cict';
$facultyId = 'user-cict-fac-27'; // Renato Aguilar
$start = microtime(true);
$parsed = parseFacultyLoadCommand('8 loads');
$preview = planFacultyLoadAssignment($parsed, $dept, false, $facultyId);
$elapsed = round((microtime(true) - $start) * 1000);
echo "elapsed={$elapsed}ms offerings=" . count($preview['allOfferings'] ?? []) .
    ' suggested=' . count($preview['suggestedOfferingKeys'] ?? []) .
    ' load=' . ($preview['loadTotal']['load'] ?? '?') . PHP_EOL;
