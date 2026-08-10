<?php

declare(strict_types=1);

/**
 * Dean creates a class section block (before schedules / student assignment).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/ClassBlock.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();

$departmentId = trim((string) ($body['departmentId'] ?? ''));
if ($departmentId === '') {
    $departmentId = (string) (userDepartmentId($user['uid']) ?? '');
}
if ($departmentId === '') {
    jsonError('departmentId is required (Dean has no assigned department).', 422);
}

$body['departmentId'] = $departmentId;

try {
    $block = createClassBlock($body, $user['uid']);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
} catch (RuntimeException $e) {
    jsonError($e->getMessage(), 500);
}

logAudit(
    $user['uid'],
    'CREATE',
    'class_block',
    sprintf(
        'Created class block "%s" (%s, AY%d Sem %s).',
        $block['name'],
        $block['yearLevel'],
        $block['academicYear'],
        $block['semester']
    ),
    $block['uid']
);

jsonSuccess(['block' => $block], 201);
