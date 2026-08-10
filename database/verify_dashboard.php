<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/Dashboard.php';
require_once dirname(__DIR__) . '/includes/Subject.php';

$d = buildDeanDashboard();
echo 'dashboard_ok role=' . $d['role'] . PHP_EOL;
echo 'subjects=' . count(fetchSubjects()) . PHP_EOL;
$tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'has_subject=' . (in_array('subject', $tables, true) ? 'yes' : 'no') . PHP_EOL;
