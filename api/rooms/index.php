<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/Room.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean', 'ProgramHead', 'HR', 'Checker']);

$roomType = isset($_GET['roomType']) ? trim((string) $_GET['roomType']) : '';
$search = isset($_GET['q']) ? trim((string) $_GET['q']) : '';

if ($roomType !== '' && !in_array(strtoupper($roomType), ROOM_TYPES, true)) {
    jsonError('roomType must be LAB or LECTURE.', 422);
}

$viewerDepartmentId = null;
if (in_array($user['role'], ['Dean', 'ProgramHead'], true)) {
    $viewerDepartmentId = userDepartmentId($user['uid']);
}

$rooms = fetchRoomsForDepartment(
    $viewerDepartmentId,
    $roomType !== '' ? strtoupper($roomType) : null,
    $search
);

jsonSuccess([
    'rooms' => $rooms,
    'meta' => [
        'roomTypes' => ROOM_TYPES,
    ],
]);
