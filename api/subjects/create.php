<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);
$body = requestBody();

if ($user['role'] === 'ProgramHead') {
    $ownDept = userDepartmentId($user['uid']);
    if ($ownDept === null) {
        jsonError('Program Head has no assigned department.', 403);
    }
    $body['departmentId'] = $ownDept;
}

try {
    $subject = createSubject($body);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'CREATE',
    'subject',
    sprintf(
        'Created subject %s — %s (%s, %s).',
        $subject['code'],
        $subject['title'],
        $subject['yearLevel'],
        $subject['semester']
    ),
    $subject['uid']
);

jsonSuccess(['subject' => $subject], 201);
