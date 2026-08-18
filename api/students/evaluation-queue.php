<?php

declare(strict_types=1);

/**
 * Program Head: cleared students for enrollment evaluation (optional year/type filters).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Enrollment.php';
require_once dirname(__DIR__, 2) . '/includes/StudentEvaluation.php';
require_once dirname(__DIR__, 2) . '/includes/Term.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['ProgramHead']);
$departmentId = requireProgramHeadDepartment($user['uid']);

$yearLevel = isset($_GET['yearLevel']) ? trim((string) $_GET['yearLevel']) : '';
$studentType = isset($_GET['studentType']) ? trim((string) $_GET['studentType']) : '';
$evalStatus = isset($_GET['evalStatus']) ? trim((string) $_GET['evalStatus']) : '';

jsonSuccess([
    'term' => currentTermWindow(),
    'students' => fetchStudentEvaluationQueue(
        $departmentId,
        $yearLevel !== '' ? $yearLevel : null,
        $studentType !== '' ? $studentType : null,
        $evalStatus !== '' ? $evalStatus : null
    ),
]);
