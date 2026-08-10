<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/env.php';
require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/helpers.php';

/**
 * Start the application session (idempotent).
 */
function startAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $name = (string) env('SESSION_NAME', 'scheduleguard_session');
    session_name($name);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Authenticate via PHP session or Bearer token.
 * Always reloads the user from the database so role/status changes apply
 * on the next request without requiring re-login.
 *
 * @return array{uid:string,email:string,role:string,firstName:string,lastName:string,status:string}
 */
function authenticate(): array
{
    startAppSession();

    $userId = null;

    if (!empty($_SESSION['user']['uid'])) {
        $userId = (string) $_SESSION['user']['uid'];
    }

    if ($userId === null) {
        $token = extractBearerToken();
        if ($token !== null) {
            $fromToken = findUserByApiToken($token);
            if ($fromToken !== null) {
                $userId = $fromToken['uid'];
            }
        }
    }

    if ($userId === null) {
        jsonError('Authentication required.', 401);
    }

    $fresh = findUserById($userId);
    if ($fresh === null) {
        unset($_SESSION['user']);
        jsonError('Authentication required.', 401);
    }

    // Keep session mirror in sync, but never trust it as the RBAC source of truth.
    $_SESSION['user'] = $fresh;

    return $fresh;
}

/**
 * @return array{uid:string,email:string,role:string,firstName:string,lastName:string,status:string}|null
 */
function findUserById(string $userId): ?array
{
    $stmt = db()->prepare(
        'SELECT uid, email, schoolId, role, firstName, lastName, status
         FROM `user`
         WHERE uid = :uid
         LIMIT 1'
    );
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();

    return $row ? normalizeAuthUser($row) : null;
}

/**
 * Require authentication and enforce RBAC for the given roles.
 * Returns 401 if unauthenticated, 403 if the role is not allowed.
 *
 * Usage on a route:
 *   $user = requireRoles(['Dean', 'HR']);
 *
 * @param list<string> $allowedRoles
 * @return array{uid:string,email:string,role:string,firstName:string,lastName:string,status:string}
 */
function requireRoles(array $allowedRoles): array
{
    $user = authenticate();

    if ($user['status'] !== 'Active') {
        jsonError('Account is not active.', 403);
    }

    if ($allowedRoles !== [] && !in_array($user['role'], $allowedRoles, true)) {
        jsonError('Forbidden: insufficient role permissions.', 403, [
            'requiredRoles' => array_values($allowedRoles),
            'currentRole' => $user['role'],
        ]);
    }

    return $user;
}

/**
 * Alias kept for readability at call sites.
 *
 * @param list<string> $allowedRoles
 */
function requireAuth(array $allowedRoles = []): array
{
    return requireRoles($allowedRoles);
}

function extractBearerToken(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if ($header === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, 'Authorization') === 0) {
                $header = (string) $value;
                break;
            }
        }
    }

    if (preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $header, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

/**
 * @return array{uid:string,email:string,role:string,firstName:string,lastName:string,status:string}|null
 */
function findUserByApiToken(string $token): ?array
{
    $stmt = db()->prepare(
        'SELECT uid, email, schoolId, role, firstName, lastName, status, tokenExpiresAt
         FROM `user`
         WHERE apiToken = :token
         LIMIT 1'
    );
    $stmt->execute([':token' => hash('sha256', $token)]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    if (!empty($row['tokenExpiresAt']) && strtotime((string) $row['tokenExpiresAt']) < time()) {
        return null;
    }

    return normalizeAuthUser($row);
}

/**
 * @param array<string,mixed> $row
 * @return array{uid:string,email:string,role:string,firstName:string,lastName:string,status:string}
 */
function normalizeAuthUser(array $row): array
{
    return [
        'uid' => (string) $row['uid'],
        'email' => (string) $row['email'],
        'schoolId' => (string) ($row['schoolId'] ?? ''),
        'role' => (string) $row['role'],
        'firstName' => (string) $row['firstName'],
        'lastName' => (string) $row['lastName'],
        'status' => (string) ($row['status'] ?? 'Active'),
    ];
}

/**
 * Issue a new API token for the user and return the plaintext token once.
 */
function issueApiToken(string $userId): string
{
    $plain = bin2hex(random_bytes(32));
    $ttlHours = (int) env('TOKEN_TTL_HOURS', 24);

    $stmt = db()->prepare(
        'UPDATE `user`
         SET apiToken = :token, tokenExpiresAt = DATE_ADD(NOW(), INTERVAL :ttl HOUR)
         WHERE uid = :uid'
    );
    $stmt->bindValue(':token', hash('sha256', $plain));
    $stmt->bindValue(':ttl', $ttlHours, PDO::PARAM_INT);
    $stmt->bindValue(':uid', $userId);
    $stmt->execute();

    return $plain;
}

function clearApiToken(string $userId): void
{
    $stmt = db()->prepare(
        'UPDATE `user` SET apiToken = NULL, tokenExpiresAt = NULL WHERE uid = :uid'
    );
    $stmt->execute([':uid' => $userId]);
}

/**
 * Public columns safe to return to clients (never includes password/token hashes).
 *
 * @param array<string,mixed> $user
 * @return array<string,mixed>
 */
function publicUser(array $user): array
{
    return [
        'uid' => $user['uid'],
        'email' => $user['email'],
        'schoolId' => $user['schoolId'] ?? '',
        'role' => $user['role'],
        'firstName' => $user['firstName'],
        'lastName' => $user['lastName'],
        'status' => $user['status'] ?? 'Active',
        'departmentId' => $user['departmentId'] ?? null,
        'phoneNumber' => $user['phoneNumber'] ?? null,
    ];
}
