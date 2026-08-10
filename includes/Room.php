<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';

const ROOM_TYPE_LAB = 'LAB';
const ROOM_TYPE_LECTURE = 'LECTURE';
const ROOM_TYPES = [ROOM_TYPE_LAB, ROOM_TYPE_LECTURE];

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapRoomRow(array $row): array
{
    $building = (string) $row['building'];
    $name = (string) $row['name'];
    $type = strtoupper((string) ($row['roomType'] ?? ROOM_TYPE_LECTURE));
    if (!in_array($type, ROOM_TYPES, true)) {
        $type = ROOM_TYPE_LECTURE;
    }

    return [
        'uid' => (string) $row['uid'],
        'name' => $name,
        'building' => $building,
        'capacity' => (int) $row['capacity'],
        'roomType' => $type,
        'label' => $building . ' / ' . $name,
        'labelWithType' => $building . ' / ' . $name . ' (' . $type . ')',
        'createdAt' => (string) ($row['createdAt'] ?? ''),
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function fetchRooms(?string $roomType = null, string $search = ''): array
{
    $sql = 'SELECT uid, name, building, capacity, roomType, createdAt FROM room WHERE 1 = 1';
    $params = [];
    if ($roomType !== null && $roomType !== '') {
        $sql .= ' AND roomType = :roomType';
        $params[':roomType'] = strtoupper($roomType);
    }
    if ($search !== '') {
        $sql .= ' AND (name LIKE :q OR building LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }
    $sql .= ' ORDER BY roomType ASC, building ASC, name ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return array_map('mapRoomRow', $stmt->fetchAll());
}

function fetchRoomById(string $roomId): ?array
{
    $stmt = db()->prepare(
        'SELECT uid, name, building, capacity, roomType, createdAt
         FROM room WHERE uid = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $roomId]);
    $row = $stmt->fetch();
    return $row ? mapRoomRow($row) : null;
}

/**
 * @param array<string,mixed> $input
 * @return array{name:string,building:string,capacity:int,roomType:string}
 */
function validateRoomInput(array $input): array
{
    $name = trim((string) ($input['name'] ?? ''));
    $building = trim((string) ($input['building'] ?? ''));
    $capacity = (int) ($input['capacity'] ?? 0);
    $roomType = strtoupper(trim((string) ($input['roomType'] ?? ROOM_TYPE_LECTURE)));

    $missing = [];
    if ($name === '') {
        $missing[] = 'name';
    }
    if ($building === '') {
        $missing[] = 'building';
    }
    if ($missing !== []) {
        throw new InvalidArgumentException('Missing required fields: ' . implode(', ', $missing));
    }
    if (strlen($name) > 100) {
        throw new InvalidArgumentException('name must be 100 characters or fewer.');
    }
    if (strlen($building) > 100) {
        throw new InvalidArgumentException('building must be 100 characters or fewer.');
    }
    if ($capacity < 1 || $capacity > 5000) {
        throw new InvalidArgumentException('capacity must be between 1 and 5000.');
    }
    if (!in_array($roomType, ROOM_TYPES, true)) {
        throw new InvalidArgumentException('roomType must be LAB or LECTURE.');
    }

    return [
        'name' => $name,
        'building' => $building,
        'capacity' => $capacity,
        'roomType' => $roomType,
    ];
}

function assertRoomNameAvailable(string $building, string $name, ?string $excludeUid = null): void
{
    $sql = 'SELECT uid FROM room WHERE building = :building AND name = :name';
    $params = [':building' => $building, ':name' => $name];
    if ($excludeUid) {
        $sql .= ' AND uid <> :uid';
        $params[':uid'] = $excludeUid;
    }
    $sql .= ' LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException('A room with this building and name already exists.');
    }
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function createRoom(array $input): array
{
    $data = validateRoomInput($input);
    assertRoomNameAvailable($data['building'], $data['name']);

    $uid = generateUid();
    $stmt = db()->prepare(
        'INSERT INTO room (uid, name, building, capacity, roomType, createdAt)
         VALUES (:uid, :name, :building, :capacity, :roomType, NOW())'
    );
    $stmt->execute([
        ':uid' => $uid,
        ':name' => $data['name'],
        ':building' => $data['building'],
        ':capacity' => $data['capacity'],
        ':roomType' => $data['roomType'],
    ]);

    $room = fetchRoomById($uid);
    if ($room === null) {
        throw new RuntimeException('Failed to load created room.');
    }
    return $room;
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function updateRoom(string $roomId, array $input): array
{
    $existing = fetchRoomById($roomId);
    if ($existing === null) {
        throw new InvalidArgumentException('Room not found.');
    }

    $merged = [
        'name' => array_key_exists('name', $input) ? $input['name'] : $existing['name'],
        'building' => array_key_exists('building', $input) ? $input['building'] : $existing['building'],
        'capacity' => array_key_exists('capacity', $input) ? $input['capacity'] : $existing['capacity'],
        'roomType' => array_key_exists('roomType', $input) ? $input['roomType'] : $existing['roomType'],
    ];
    $data = validateRoomInput($merged);
    assertRoomNameAvailable($data['building'], $data['name'], $roomId);

    $stmt = db()->prepare(
        'UPDATE room
         SET name = :name,
             building = :building,
             capacity = :capacity,
             roomType = :roomType
         WHERE uid = :uid'
    );
    $stmt->execute([
        ':name' => $data['name'],
        ':building' => $data['building'],
        ':capacity' => $data['capacity'],
        ':roomType' => $data['roomType'],
        ':uid' => $roomId,
    ]);

    $room = fetchRoomById($roomId);
    if ($room === null) {
        throw new RuntimeException('Failed to load updated room.');
    }
    return $room;
}

function inferRoomTypeFromLabel(string $label): string
{
    $upper = strtoupper($label);
    if (str_contains($upper, 'LAB') || str_contains($upper, 'CL ')) {
        return ROOM_TYPE_LAB;
    }
    return ROOM_TYPE_LECTURE;
}
