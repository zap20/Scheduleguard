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

const FACULTY_EMPLOYMENT_TYPES = ['Regular', 'PartTime'];
const STUDENT_TYPES = ['Regular', 'Irregular'];
const USER_YEAR_LEVELS = ['1st Year', '2nd Year', '3rd Year', '4th Year'];

/** Minimum teaching load (hours÷3) by faculty employment type. */
const FACULTY_MIN_LOAD_REGULAR = 8.0;
const FACULTY_MIN_LOAD_PART_TIME = 3.0;

function facultyMinLoadForEmploymentType(?string $employmentType): float
{
    $type = normalizeFacultyEmploymentType($employmentType, false);
    return $type === 'PartTime' ? FACULTY_MIN_LOAD_PART_TIME : FACULTY_MIN_LOAD_REGULAR;
}

function normalizeFacultyEmploymentType(?string $raw, bool $required = false): ?string
{
    $value = trim((string) ($raw ?? ''));
    if ($value === '') {
        if ($required) {
            throw new InvalidArgumentException('employmentType is required for Faculty (Regular or PartTime).');
        }
        return null;
    }
    // Accept Part-time / Part time aliases
    $norm = str_replace(['-', ' '], '', $value);
    if (strcasecmp($norm, 'PartTime') === 0 || strcasecmp($value, 'Part-time') === 0) {
        return 'PartTime';
    }
    if (strcasecmp($value, 'Regular') === 0) {
        return 'Regular';
    }
    throw new InvalidArgumentException('employmentType must be Regular or PartTime.');
}

function normalizeStudentType(?string $raw, bool $required = false): ?string
{
    $value = trim((string) ($raw ?? ''));
    if ($value === '') {
        if ($required) {
            throw new InvalidArgumentException('studentType is required for Student (Regular or Irregular).');
        }
        return null;
    }
    if (!in_array($value, STUDENT_TYPES, true)) {
        throw new InvalidArgumentException('studentType must be Regular or Irregular.');
    }
    return $value;
}

function normalizeUserYearLevel(?string $raw, bool $required = false): ?string
{
    $value = trim((string) ($raw ?? ''));
    if ($value === '') {
        if ($required) {
            throw new InvalidArgumentException('yearLevel is required for Student.');
        }
        return null;
    }
    if (!in_array($value, USER_YEAR_LEVELS, true)) {
        throw new InvalidArgumentException('yearLevel must be one of: ' . implode(', ', USER_YEAR_LEVELS));
    }
    return $value;
}

/**
 * Role-specific fields for Faculty / Student.
 *
 * @return array{employmentType:?string,yearLevel:?string,studentType:?string}
 */
function normalizeRoleSpecificUserFields(string $role, array $input, ?array $existing = null): array
{
    $employmentType = null;
    $yearLevel = null;
    $studentType = null;

    if ($role === 'Faculty') {
        $raw = array_key_exists('employmentType', $input)
            ? $input['employmentType']
            : ($existing['employmentType'] ?? 'Regular');
        $employmentType = normalizeFacultyEmploymentType(
            $raw === null || $raw === '' ? 'Regular' : (string) $raw,
            true
        );
    } elseif ($role === 'Student') {
        $rawYear = array_key_exists('yearLevel', $input)
            ? $input['yearLevel']
            : ($existing['yearLevel'] ?? null);
        $rawType = array_key_exists('studentType', $input)
            ? $input['studentType']
            : ($existing['studentType'] ?? 'Regular');
        $yearLevel = normalizeUserYearLevel(
            $rawYear === null || $rawYear === '' ? null : (string) $rawYear,
            true
        );
        $studentType = normalizeStudentType(
            $rawType === null || $rawType === '' ? 'Regular' : (string) $rawType,
            true
        );
    }

    return [
        'employmentType' => $employmentType,
        'yearLevel' => $yearLevel,
        'studentType' => $studentType,
    ];
}
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
    $employmentType = $row['employmentType'] ?? null;
    $employmentType = ($employmentType !== null && $employmentType !== '')
        ? (string) $employmentType
        : null;
    $yearLevel = $row['yearLevel'] ?? null;
    $yearLevel = ($yearLevel !== null && $yearLevel !== '') ? (string) $yearLevel : null;
    $studentType = $row['studentType'] ?? null;
    $studentType = ($studentType !== null && $studentType !== '') ? (string) $studentType : null;

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
        'employmentType' => $employmentType,
        'yearLevel' => $yearLevel,
        'studentType' => $studentType,
        'minLoad' => ((string) ($row['role'] ?? '') === 'Faculty')
            ? facultyMinLoadForEmploymentType($employmentType)
            : null,
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
                u.employmentType,
                u.yearLevel,
                u.studentType,
                u.createdAt,
                d.name AS departmentName
            FROM userProfile u
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

    $countStmt = db()->prepare("SELECT COUNT(*) FROM userProfile u WHERE {$whereSql}");
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
                u.employmentType,
                u.yearLevel,
                u.studentType,
                u.createdAt,
                d.name AS departmentName
            FROM userProfile u
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

function upsertFacultyProfile(string $userId, string $employmentType): void
{
    $stmt = db()->prepare(
        'INSERT INTO faculty (userId, employmentType, createdAt)
         VALUES (:uid, :employmentType, NOW())
         ON DUPLICATE KEY UPDATE employmentType = VALUES(employmentType)'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':employmentType' => $employmentType,
    ]);
}

function upsertStudentProfile(string $userId, array $fields): void
{
    $exists = db()->prepare('SELECT userId FROM student WHERE userId = :uid LIMIT 1');
    $exists->execute([':uid' => $userId]);
    if ($exists->fetchColumn()) {
        $stmt = db()->prepare(
            'UPDATE student
             SET yearLevel = :yearLevel, studentType = :studentType
             WHERE userId = :uid'
        );
        $stmt->execute([
            ':yearLevel' => $fields['yearLevel'],
            ':studentType' => $fields['studentType'],
            ':uid' => $userId,
        ]);
        return;
    }

    $stmt = db()->prepare(
        'INSERT INTO student (userId, yearLevel, studentType, enrollmentEvalStatus, createdAt)
         VALUES (:uid, :yearLevel, :studentType, \'Pending\', NOW())'
    );
    $stmt->execute([
        ':uid' => $userId,
        ':yearLevel' => $fields['yearLevel'],
        ':studentType' => $fields['studentType'],
    ]);
}

function syncUserSideProfiles(string $userId, string $role, array $roleFields): void
{
    if ($role === 'Faculty') {
        db()->prepare('DELETE FROM student WHERE userId = :uid')->execute([':uid' => $userId]);
        upsertFacultyProfile($userId, (string) $roleFields['employmentType']);
        return;
    }

    if ($role === 'Student') {
        db()->prepare('DELETE FROM faculty WHERE userId = :uid')->execute([':uid' => $userId]);
        upsertStudentProfile($userId, [
            'yearLevel' => (string) $roleFields['yearLevel'],
            'studentType' => (string) $roleFields['studentType'],
        ]);
        return;
    }

    db()->prepare('DELETE FROM faculty WHERE userId = :uid')->execute([':uid' => $userId]);
    db()->prepare('DELETE FROM student WHERE userId = :uid')->execute([':uid' => $userId]);
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
    $roleFields = normalizeRoleSpecificUserFields($role, $input, null);

    $exists = db()->prepare('SELECT uid FROM `user` WHERE email = :email LIMIT 1');
    $exists->execute([':email' => $email]);
    if ($exists->fetchColumn()) {
        throw new InvalidArgumentException('A user with this email already exists.');
    }

    $uid = generateUid();
    $stmt = db()->prepare(
        'INSERT INTO `user`
            (uid, firstName, lastName, email, schoolId, role, phoneNumber, status, passwordHash, createdAt)
         VALUES
            (:uid, :firstName, :lastName, :email, :schoolId, :role, :phoneNumber, :status, :passwordHash, NOW())'
    );
    $stmt->execute([
        ':uid' => $uid,
        ':firstName' => $firstName,
        ':lastName' => $lastName,
        ':email' => $email,
        ':schoolId' => $schoolId,
        ':role' => $role,
        ':phoneNumber' => $phoneNumber !== '' ? $phoneNumber : null,
        ':status' => $status,
        ':passwordHash' => password_hash($password, PASSWORD_BCRYPT),
    ]);
    setUserDepartment($uid, $departmentId);
    syncUserSideProfiles($uid, $role, $roleFields);

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
    $roleFields = normalizeRoleSpecificUserFields($role, $input, $existing);

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
             SET firstName = :firstName,
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
             SET firstName = :firstName,
                 lastName = :lastName,
                 email = :email,
                 schoolId = :schoolId,
                 role = :role,
                 phoneNumber = :phoneNumber,
                 status = :status
             WHERE uid = :uid'
        );
        $stmt->execute([
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
    setUserDepartment($userId, $departmentId);
    syncUserSideProfiles($userId, $role, $roleFields);

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
