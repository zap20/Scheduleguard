<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Department.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead']);
$body = requestBody();
$name = trim((string) ($body['name'] ?? ''));

try {
    $department = findOrCreateDepartmentByName($name);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

if ($department['created']) {
    logAudit(
        $user['uid'],
        'CREATE',
        'department',
        sprintf('Created department %s.', $department['name']),
        $department['uid']
    );
}

jsonSuccess([
    'department' => [
        'uid' => $department['uid'],
        'name' => $department['name'],
    ],
    'created' => $department['created'],
], $department['created'] ? 201 : 200);
