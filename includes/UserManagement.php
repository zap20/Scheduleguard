<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Auth.php';

const USER_STATUS_ACTIVE = 'Active';
const USER_STATUS_INACTIVE = 'Inactive';

const USER_ROLES = [
    'Checker',
    'Faculty',
    'Dean',
    'HR',
    'ProgramHead',
    'Student',
];

/**
 * School / campus ID format: YYYY-NNN (e.g. 2020-001).
 * Unique per role only — Student and Faculty may share the same schoolId.
 */
function normalizeSchoolId(string $raw): string
{
    $schoolId = strtoupper(trim($raw));
    if ($schoolId === '') {
        throw new InvalidArgumentException('schoolId is required (format YYYY-NNN, e.g. 2020-001).');
    }
    if (preg_match('/^\d{4}-\d{3}$/', $schoolId) !== 1) {
        throw new InvalidArgumentException('schoolId must match YYYY-NNN (e.g. 2020-001).');
    }

    return $schoolId;
}

function assertSchoolIdAvailable(string $schoolId, string $role, ?string $excludeUserId = null): void
{
    $sql = 'SELECT uid FROM `user` WHERE schoolId = :schoolId AND role = :role';
    $params = [':schoolId' => $schoolId, ':role' => $role];
    if ($excludeUserId !== null && $excludeUserId !== '') {
        $sql .= ' AND uid <> :uid';
        $params[':uid'] = $excludeUserId;
    }
    $sql .= ' LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    if ($stmt->fetchColumn()) {
        throw new InvalidArgumentException(
            sprintf('schoolId %s is already used by another %s account.', $schoolId, $role)
        );
    }
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapManagedUser(array $row): array
{
    return [
        'uid' => (string) $row['uid'],
        'firstName' => (string) $row['firstName'],
        'lastName' => (string) $row['lastName'],
        'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
        'email' => (string) $row['email'],
        'schoolId' => (string) ($row['schoolId'] ?? ''),
        'role' => (string) $row['role'],
        'departmentId' => $row['departmentId'] !== null ? (string) $row['departmentId'] : null,
        'departmentName' => $row['departmentName'] !== null ? (string) $row['departmentName'] : null,
        'phoneNumber' => $row['phoneNumber'] !== null ? (string) $row['phoneNumber'] : null,
        'status' => (string) $row['status'],
        'createdAt' => (string) $row['createdAt'],
    ];
}

/**
 * @return array<string,mixed>|null
 */
function fetchManagedUserById(string $userId): ?array
{
    $sql = 'SELECT
                u.uid,
                u.firstName,
                u.lastName,
                u.email,
                u.schoolId,
                u.role,
                u.departmentId,
                u.phoneNumber,
                u.status,
                u.createdAt,
                d.name AS departmentName
            FROM `user` u
            LEFT JOIN department d ON d.uid = u.departmentId
            WHERE u.uid = :uid
            LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute([':uid' => $userId]);
    $row = $stmt->fetch();

    return $row ? mapManagedUser($row) : null;
}

/**
 * @return array{records: list<array<string,mixed>>, total: int}
 */
function fetchManagedUsers(
    string $search,
    ?string $role,
    ?string $status,
    int $page,
    int $pageSize
): array {
    $where = ['1 = 1'];
    $params = [];

    if ($search !== '') {
        $where[] = '(u.firstName LIKE :q OR u.lastName LIKE :q OR u.email LIKE :q OR u.schoolId LIKE :q OR CONCAT(u.firstName, \' \', u.lastName) LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }
    if ($role !== null && $role !== '') {
        $where[] = 'u.role = :role';
        $params[':role'] = $role;
    }
    if ($status !== null && $status !== '') {
        $where[] = 'u.status = :status';
        $params[':status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = db()->prepare("SELECT COUNT(*) FROM `user` u WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = max(0, ($page - 1) * $pageSize);
    $sql = "SELECT
                u.uid,
                u.firstName,
                u.lastName,
                u.email,
                u.schoolId,
                u.role,
                u.departmentId,
                u.phoneNumber,
                u.status,
                u.createdAt,
                d.name AS departmentName
            FROM `user` u
            LEFT JOIN department d ON d.uid = u.departmentId
            WHERE {$whereSql}
            ORDER BY u.schoolId ASC, u.lastName ASC, u.firstName ASC
            LIMIT :limit OFFSET :offset";

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'records' => array_map('mapManagedUser', $stmt->fetchAll()),
        'total' => $total,
    ];
}

function assertValidRole(string $role): void
{
    if (!in_array($role, USER_ROLES, true)) {
        throw new InvalidArgumentException('Invalid role. Allowed: ' . implode(', ', USER_ROLES));
    }
}

function assertValidUserStatus(string $status): void
{
    if (!in_array($status, [USER_STATUS_ACTIVE, USER_STATUS_INACTIVE], true)) {
        throw new InvalidArgumentException('status must be Active or Inactive.');
    }
}

function assertDepartmentIdOrNull(?string $departmentId): void
{
    if ($departmentId === null || $departmentId === '') {
        return;
    }

    $stmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $departmentId]);
    if (!$stmt->fetchColumn()) {
        throw new InvalidArgumentException('departmentId not found.');
    }
}

/**
 * @param array<string,mixed> $input
 * @return array<string,mixed>
 */
function createManagedUser(array $input): array
{
    $firstName = trim((string) ($input['firstName'] ?? ''));
    $lastName = trim((string) ($input['lastName'] ?? ''));
    $email = strtolower(trim((string) ($input['email'] ?? '')));
    $schoolId = normalizeSchoolId((string) ($input['schoolId'] ?? ''));
    $role = trim((string) ($input['role'] ?? ''));
    $phoneNumber = trim((string) ($input['phoneNumber'] ?? ''));
    $departmentId = trim((string) ($input['departmentId'] ?? ''));
    $password = (string) ($input['password'] ?? '');
    $status = trim((string) ($input['status'] ?? USER_STATUS_ACTIVE));

    if ($firstName === '' || $lastName === '' || $email === '' || $role === '' || $password === '') {
        throw new InvalidArgumentException('firstName, lastName, email, role, and password are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('email is invalid.');
    }
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('password must be at least 8 characters.');
    }

    assertValidRole($role);
    assertValidUserStatus($status === '' ? USER_STATUS_ACTIVE : $status);
    $status = $status === '' ? USER_STATUS_ACTIVE : $status;
    $departmentId = $departmentId === '' ? null : $departmentId;
    assertDepartmentIdOrNull($departmentId);
    assertSchoolIdAvailable($schoolId, $role);

    $exists = db()->prepare('SELECT uid FROM `user` WHERE email = :email LIMIT 1');
    $exists->execute([':email' => $email]);
    if ($exists->fetchColumn()) {
        throw new InvalidArgumentException('A user with this email already exists.');
    }

    $uid = generateUid();
    $stmt = db()->prepare(
        'INSERT INTO `user`
            (uid, departmentId, firstName, lastName, email, schoolId, role, phoneNumber, status, passwordHash, createdAt)
         VALUES
            (:uid, :departmentId, :firstName, :lastName, :email, :schoolId, :role, :phoneNumber, :status, :passwordHash, NOW())'
    );
    $stmt->execute([
        ':uid' => $uid,
        ':departmentId' => $departmentId,
        ':firstName' => $firstName,
        ':lastName' => $lastName,
        ':email' => $email,
        ':schoolId' => $schoolId,
        ':role' => $role,
        ':phoneNumber' => $phoneNumber !== '' ? $phoneNumber : null,
        ':status' => $status,
        ':passwordHash' => password_hash($password, PASSWORD_BCRYPT),
    ]);

    $user = fetchManagedUserById($uid);
    if ($user === null) {
        throw new RuntimeException('Failed to load created user.');
    }

    return $user;
}

/**
 * @param array<string,mixed> $input
 * @return array{user: array<string,mixed>, roleChanged: bool, previousRole: string, statusChanged: bool, previousStatus: string}
 */
function updateManagedUser(string $userId, array $input): array
{
    $existing = fetchManagedUserById($userId);
    if ($existing === null) {
        throw new InvalidArgumentException('User not found.');
    }

    $firstName = array_key_exists('firstName', $input)
        ? trim((string) $input['firstName'])
        : $existing['firstName'];
    $lastName = array_key_exists('lastName', $input)
        ? trim((string) $input['lastName'])
        : $existing['lastName'];
    $email = array_key_exists('email', $input)
        ? strtolower(trim((string) $input['email']))
        : $existing['email'];
    $schoolId = array_key_exists('schoolId', $input)
        ? normalizeSchoolId((string) $input['schoolId'])
        : normalizeSchoolId((string) ($existing['schoolId'] ?? ''));
    $role = array_key_exists('role', $input)
        ? trim((string) $input['role'])
        : $existing['role'];
    $phoneNumber = array_key_exists('phoneNumber', $input)
        ? trim((string) $input['phoneNumber'])
        : (string) ($existing['phoneNumber'] ?? '');
    $departmentId = array_key_exists('departmentId', $input)
        ? trim((string) ($input['departmentId'] ?? ''))
        : (string) ($existing['departmentId'] ?? '');
    $status = array_key_exists('status', $input)
        ? trim((string) $input['status'])
        : $existing['status'];
    $password = array_key_exists('password', $input) ? (string) $input['password'] : '';

    if ($firstName === '' || $lastName === '' || $email === '' || $role === '') {
        throw new InvalidArgumentException('firstName, lastName, email, and role are required.');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('email is invalid.');
    }

    assertValidRole($role);
    assertValidUserStatus($status);
    $departmentId = $departmentId === '' ? null : $departmentId;
    assertDepartmentIdOrNull($departmentId);
    assertSchoolIdAvailable($schoolId, $role, $userId);

    $exists = db()->prepare('SELECT uid FROM `user` WHERE email = :email AND uid <> :uid LIMIT 1');
    $exists->execute([':email' => $email, ':uid' => $userId]);
    if ($exists->fetchColumn()) {
        throw new InvalidArgumentException('A user with this email already exists.');
    }

    if ($password !== '' && strlen($password) < 8) {
        throw new InvalidArgumentException('password must be at least 8 characters.');
    }

    if ($password !== '') {
        $stmt = db()->prepare(
            'UPDATE `user`
             SET departmentId = :departmentId,
                 firstName = :firstName,
                 lastName = :lastName,
                 email = :email,
                 schoolId = :schoolId,
                 role = :role,
                 phoneNumber = :phoneNumber,
                 status = :status,
                 passwordHash = :passwordHash
             WHERE uid = :uid'
        );
        $stmt->execute([
            ':departmentId' => $departmentId,
            ':firstName' => $firstName,
            ':lastName' => $lastName,
            ':email' => $email,
            ':schoolId' => $schoolId,
            ':role' => $role,
            ':phoneNumber' => $phoneNumber !== '' ? $phoneNumber : null,
            ':status' => $status,
            ':passwordHash' => password_hash($password, PASSWORD_BCRYPT),
            ':uid' => $userId,
        ]);
    } else {
        $stmt = db()->prepare(
            'UPDATE `user`
             SET departmentId = :departmentId,
                 firstName = :firstName,
                 lastName = :lastName,
                 email = :email,
                 schoolId = :schoolId,
                 role = :role,
                 phoneNumber = :phoneNumber,
                 status = :status
             WHERE uid = :uid'
        );
        $stmt->execute([
            ':departmentId' => $departmentId,
            ':firstName' => $firstName,
            ':lastName' => $lastName,
            ':email' => $email,
            ':schoolId' => $schoolId,
            ':role' => $role,
            ':phoneNumber' => $phoneNumber !== '' ? $phoneNumber : null,
            ':status' => $status,
            ':uid' => $userId,
        ]);
    }

    // Soft-deactivated users should not keep a live API token.
    if ($status === USER_STATUS_INACTIVE) {
        clearApiToken($userId);
    }

    $user = fetchManagedUserById($userId);
    if ($user === null) {
        throw new RuntimeException('Failed to load updated user.');
    }

    return [
        'user' => $user,
        'roleChanged' => $existing['role'] !== $user['role'],
        'previousRole' => $existing['role'],
        'statusChanged' => $existing['status'] !== $user['status'],
        'previousStatus' => $existing['status'],
    ];
}

/**
 * Soft-delete: set status to Inactive (keeps FK history intact).
 *
 * @return array<string,mixed>
 */
function deactivateManagedUser(string $userId): array
{
    $existing = fetchManagedUserById($userId);
    if ($existing === null) {
        throw new InvalidArgumentException('User not found.');
    }

    if ($existing['status'] === USER_STATUS_INACTIVE) {
        return $existing;
    }

    $stmt = db()->prepare(
        'UPDATE `user` SET status = :status WHERE uid = :uid'
    );
    $stmt->execute([
        ':status' => USER_STATUS_INACTIVE,
        ':uid' => $userId,
    ]);
    clearApiToken($userId);

    $user = fetchManagedUserById($userId);
    if ($user === null) {
        throw new RuntimeException('Failed to load deactivated user.');
    }

    return $user;
}
