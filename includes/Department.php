<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * @return list<array{uid:string,name:string}>
 */
function fetchDepartments(): array
{
    $stmt = db()->query(
        'SELECT uid, name FROM department ORDER BY name ASC'
    );
    return array_map(static function (array $row): array {
        return [
            'uid' => (string) $row['uid'],
            'name' => (string) $row['name'],
        ];
    }, $stmt->fetchAll());
}

/**
 * Find a department by exact name (case-insensitive), or create it.
 *
 * @return array{uid:string,name:string,created:bool}
 */
function findOrCreateDepartmentByName(string $name): array
{
    $name = trim(preg_replace('/\s+/', ' ', $name) ?? $name);
    if ($name === '') {
        throw new InvalidArgumentException('Department name is required.');
    }
    if (strlen($name) > 150) {
        throw new InvalidArgumentException('Department name must be 150 characters or fewer.');
    }

    $stmt = db()->prepare(
        'SELECT uid, name FROM department WHERE LOWER(name) = LOWER(:name) LIMIT 1'
    );
    $stmt->execute([':name' => $name]);
    $row = $stmt->fetch();
    if ($row) {
        return [
            'uid' => (string) $row['uid'],
            'name' => (string) $row['name'],
            'created' => false,
        ];
    }

    $uid = generateUid();
    // Preserve the user's casing for new departments.
    $insert = db()->prepare(
        'INSERT INTO department (uid, name, createdAt) VALUES (:uid, :name, NOW())'
    );
    try {
        $insert->execute([
            ':uid' => $uid,
            ':name' => $name,
        ]);
    } catch (PDOException $e) {
        // Race on unique name — re-fetch.
        $stmt->execute([':name' => $name]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'uid' => (string) $row['uid'],
                'name' => (string) $row['name'],
                'created' => false,
            ];
        }
        throw $e;
    }

    return [
        'uid' => $uid,
        'name' => $name,
        'created' => true,
    ];
}
