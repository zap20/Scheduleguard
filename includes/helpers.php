<?php

declare(strict_types=1);

/**
 * Generate a UUID v4 string for primary keys.
 */
function generateUid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Load a user's departmentId from the database.
 */
function userDepartmentId(string $userId): ?string
{
    $stmt = db()->prepare('SELECT departmentId FROM `user` WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $userId]);
    $value = $stmt->fetchColumn();

    if ($value === false || $value === null || $value === '') {
        return null;
    }

    return (string) $value;
}

/**
 * Read JSON body from the request, falling back to form-encoded POST.
 *
 * @return array<string,mixed>
 */
function requestBody(): array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

/**
 * Apply CORS headers for local frontend development.
 */
function applyCorsHeaders(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
