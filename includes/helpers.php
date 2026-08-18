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
 * Course code only: drop LEC/LAB (including LAB/LEC) and block tokens.
 * "IT 122 LAB/LEC" → "IT 122", "IT 324 4BA01" → "IT 324", "IT221" → "IT 221".
 */
function normalizeSubjectCode(string $raw): string
{
    $name = strtoupper(trim($raw));
    $name = preg_replace('/\s+/', ' ', $name) ?? $name;
    if ($name === '') {
        return '';
    }

    // LAB, LEC, LAB/LEC, LEC / LAB, LAB-LEC
    $base = preg_replace('/\b(?:LEC|LAB)(?:\s*[\/&-]\s*(?:LEC|LAB))?\b/', ' ', $name);
    $base = preg_replace('/\s+/', ' ', trim($base ?? $name)) ?? $name;

    // Trailing block: 1B01, IB01, 2B12, 4BA01, 1B31A, IRREG…
    $block = '/\s+(?:IRREG[A-Z0-9]*|(?:\d+)?(?:IB|BA|B)\d+[A-Z]?)$/';
    $prev = '';
    while ($prev !== $base) {
        $prev = $base;
        $base = trim(preg_replace($block, '', $base) ?? $base);
    }

    // IT221 → IT 221 (not ITELEC3)
    if (preg_match('/^([A-Z]{2,8})(\d{2,4}[A-Z]?)$/', $base, $m) === 1) {
        $base = $m[1] . ' ' . $m[2];
    }

    return $base !== '' ? $base : $name;
}

/**
 * Load a user's departmentId from department membership.
 */
function userDepartmentId(string $userId): ?string
{
    $stmt = db()->prepare(
        'SELECT departmentId FROM departmentUser WHERE userId = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $value = $stmt->fetchColumn();

    if ($value === false || $value === null || $value === '') {
        return null;
    }

    return (string) $value;
}

/**
 * Subject/Curriculum catalog: users with a department only see subjects they own.
 * Deans without a department may pass an optional department filter.
 */
function resolveOwnedSubjectDepartmentScope(array $user, ?string $requestedDepartmentId = null): ?string
{
    $ownDept = userDepartmentId((string) ($user['uid'] ?? ''));
    if ($ownDept !== null && $ownDept !== '') {
        return $ownDept;
    }
    $requested = trim((string) ($requestedDepartmentId ?? ''));
    return $requested !== '' ? $requested : null;
}

/**
 * Write APIs: block cross-department edits when the user belongs to a department.
 *
 * @param array<string,mixed> $subject
 */
function assertSubjectOwnedByUserDepartment(array $user, array $subject): void
{
    $ownDept = userDepartmentId((string) ($user['uid'] ?? ''));
    if ($ownDept === null || $ownDept === '') {
        return;
    }
    if ((string) ($subject['departmentId'] ?? '') !== $ownDept) {
        throw new InvalidArgumentException('You may only manage subjects owned by your department.');
    }
}

/**
 * Replace a user's department membership (one department per account).
 */
function setUserDepartment(string $userId, ?string $departmentId): void
{
    $stmt = db()->prepare('DELETE FROM departmentUser WHERE userId = :uid');
    $stmt->execute([':uid' => $userId]);

    if ($departmentId === null || $departmentId === '') {
        return;
    }

    $ins = db()->prepare(
        'INSERT INTO departmentUser (userId, departmentId, createdAt)
         VALUES (:uid, :departmentId, NOW())'
    );
    $ins->execute([
        ':uid' => $userId,
        ':departmentId' => $departmentId,
    ]);
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
