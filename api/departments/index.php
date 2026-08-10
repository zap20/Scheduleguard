<?php

declare(strict_types=1);

/**
 * Department list for attendance filter dropdowns.
 * RBAC: HR, Dean (and ProgramHead for future schedule tools).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

requireRoles(['HR', 'Dean', 'ProgramHead']);

$stmt = db()->query(
    'SELECT uid, name
     FROM department
     ORDER BY name ASC'
);

jsonSuccess([
    'departments' => $stmt->fetchAll(),
]);
