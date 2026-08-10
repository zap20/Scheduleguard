<?php

declare(strict_types=1);

/**
 * Student blocking status — own block rows only.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Blocking.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Student']);
$blocks = fetchStudentBlocks($user['uid']);
$cleared = isStudentCleared($user['uid']);
$active = getActiveBlock($user['uid']);

jsonSuccess([
    'cleared' => $cleared,
    'status' => $cleared ? 'Cleared' : 'Active',
    'activeBlock' => $active,
    'blocks' => $blocks,
]);
