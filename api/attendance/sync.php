<?php

declare(strict_types=1);

/**
 * Checker mobile attendance sync.
 *
 * POST /api/attendance/sync.php — push pending attendance records.
 * GET  /api/attendance/sync.php?mode=caches — pull faculty + class_block caches
 *      (read-only; does not change web attendance endpoints).
 *
 * Auth: Checker (Bearer or session).
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/AttendanceSync.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $auth = requireRoles(['Checker']);
    unset($auth);
    $mode = (string) ($_GET['mode'] ?? 'caches');
    if ($mode !== 'caches' && $mode !== 'today') {
        jsonError('Unknown mode. Use mode=caches.', 400);
    }
    jsonSuccess(fetchCheckerMobileCaches());
}

if ($method !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$auth = requireRoles(['Checker']);

$payload = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($payload)) {
    jsonError('Invalid JSON body.', 400);
}

if (array_is_list($payload)) {
    $records = $payload;
} else {
    $records = $payload['records'] ?? null;
    if (!is_array($records)) {
        jsonError('Body must be an array of records or { "records": [...] }.', 400);
    }
}

if (count($records) > 200) {
    jsonError('Too many records in one request (max 200).', 400);
}

$results = syncAttendanceRecordsBatch($records, (string) $auth['uid']);

$created = 0;
$duplicates = 0;
$errors = 0;
foreach ($results as $r) {
    if ($r['status'] === 'created') {
        $created++;
    } elseif ($r['status'] === 'duplicate') {
        $duplicates++;
    } else {
        $errors++;
    }
}

logAudit(
    (string) $auth['uid'],
    'ATTENDANCE_SYNC',
    'attendance',
    sprintf(
        'Mobile sync: %d created, %d duplicate, %d error (batch %d)',
        $created,
        $duplicates,
        $errors,
        count($records)
    )
);

jsonSuccess([
    'results' => $results,
    'summary' => [
        'created' => $created,
        'duplicate' => $duplicates,
        'error' => $errors,
        'total' => count($results),
    ],
]);
