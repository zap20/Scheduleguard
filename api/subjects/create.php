<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Subject.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);
$body = requestBody();

$scopedDept = resolveOwnedSubjectDepartmentScope($user, (string) ($body['departmentId'] ?? ''));
if (($user['role'] ?? '') === 'ProgramHead' && $scopedDept === null) {
    jsonError('Program Head has no assigned department.', 403);
}
if ($scopedDept !== null && $scopedDept !== '') {
    $body['departmentId'] = $scopedDept;
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
