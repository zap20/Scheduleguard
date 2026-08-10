<?php

declare(strict_types=1);

/**
 * Student's own schedule view — only distributed enrollments on confirmed schedules.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Enrollment.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Student']);

jsonSuccess([
    'term' => currentTermWindow(),
    'schedules' => fetchDistributedStudentSchedule($user['uid']),
]);
