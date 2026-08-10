<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Room.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$body = requestBody();

try {
    $room = createRoom($body);
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 422);
}

logAudit(
    $user['uid'],
    'CREATE',
    'room',
    sprintf(
        'Created %s room %s / %s (capacity %d).',
        $room['roomType'],
        $room['building'],
        $room['name'],
        $room['capacity']
    ),
    $room['uid']
);

jsonSuccess(['room' => $room], 201);
