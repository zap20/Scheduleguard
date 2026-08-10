<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

/** Canonical block statuses used by the web app. */
const BLOCK_STATUS_ACTIVE = 'Active';
const BLOCK_STATUS_CLEARED = 'Cleared';

/**
 * True when the student has no block row with status = Active.
 * Used by enrollment (and other modules) before allowing registration.
 */
function isStudentCleared(string $studentId): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM block
         WHERE studentId = :studentId
           AND LOWER(status) = :active'
    );
    $stmt->execute([
        ':studentId' => $studentId,
        ':active' => 'active',
    ]);

    return (int) $stmt->fetchColumn() === 0;
}

/**
 * Most recent Active block for a student (reason shown when enrollment is denied).
 *
 * @return array{uid:string,reason:string,departmentId:string,status:string,createdAt:string}|null
 */
function getActiveBlock(string $studentId): ?array
{
    $stmt = db()->prepare(
        'SELECT uid, reason, departmentId, status, createdAt
         FROM block
         WHERE studentId = :studentId
           AND LOWER(status) = :active
         ORDER BY createdAt DESC
         LIMIT 1'
    );
    $stmt->execute([
        ':studentId' => $studentId,
        ':active' => 'active',
    ]);
    $row = $stmt->fetch();

    if (!$row) {
        return null;
    }

    return [
        'uid' => (string) $row['uid'],
        'reason' => (string) $row['reason'],
        'departmentId' => (string) $row['departmentId'],
        'status' => (string) $row['status'],
        'createdAt' => (string) $row['createdAt'],
    ];
}

/**
 * All block rows for a student (own self-service status view).
 *
 * @return list<array<string,mixed>>
 */
function fetchStudentBlocks(string $studentId): array
{
    $sql = 'SELECT
                b.uid,
                b.studentId,
                b.departmentId,
                b.issuedBy,
                b.reason,
                b.status,
                b.createdAt,
                d.name AS departmentName,
                i.firstName AS issuerFirstName,
                i.lastName AS issuerLastName
            FROM block b
            INNER JOIN department d ON d.uid = b.departmentId
            INNER JOIN `user` i ON i.uid = b.issuedBy
            WHERE b.studentId = :studentId
            ORDER BY
                CASE WHEN LOWER(b.status) = \'active\' THEN 0 ELSE 1 END,
                b.createdAt DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute([':studentId' => $studentId]);

    return array_map(static function (array $row): array {
        return [
            'uid' => (string) $row['uid'],
            'studentId' => (string) $row['studentId'],
            'departmentId' => (string) $row['departmentId'],
            'departmentName' => (string) $row['departmentName'],
            'issuedBy' => (string) $row['issuedBy'],
            'issuedByName' => trim((string) $row['issuerFirstName'] . ' ' . (string) $row['issuerLastName']),
            'reason' => (string) $row['reason'],
            'status' => (string) $row['status'],
            'createdAt' => (string) $row['createdAt'],
        ];
    }, $stmt->fetchAll());
}

/**
 * Normalize status input to Active or Cleared.
 */
function normalizeBlockStatus(string $status): ?string
{
    $normalized = strtolower(trim($status));
    return match ($normalized) {
        'active' => BLOCK_STATUS_ACTIVE,
        'cleared' => BLOCK_STATUS_CLEARED,
        default => null,
    };
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapBlockRow(array $row): array
{
    return [
        'uid' => (string) $row['uid'],
        'studentId' => (string) $row['studentId'],
        'studentName' => trim((string) $row['studentFirstName'] . ' ' . (string) $row['studentLastName']),
        'studentEmail' => (string) $row['studentEmail'],
        'departmentId' => (string) $row['departmentId'],
        'departmentName' => (string) $row['departmentName'],
        'reason' => (string) $row['reason'],
        'status' => (string) $row['status'],
        'issuedBy' => (string) $row['issuedBy'],
        'issuedByName' => trim((string) $row['issuerFirstName'] . ' ' . (string) $row['issuerLastName']),
        'createdAt' => (string) $row['createdAt'],
    ];
}

/**
 * Paginated, filterable block list.
 *
 * @return array{records: list<array<string,mixed>>, total: int}
 */
function fetchBlocks(
    ?string $departmentId,
    ?string $status,
    int $page,
    int $pageSize
): array {
    $where = ['1 = 1'];
    $params = [];

    if ($departmentId !== null && $departmentId !== '') {
        $where[] = 'b.departmentId = :departmentId';
        $params[':departmentId'] = $departmentId;
    }

    if ($status !== null && $status !== '') {
        $normalized = normalizeBlockStatus($status);
        if ($normalized === null) {
            throw new InvalidArgumentException('status must be Active or Cleared.');
        }
        $where[] = 'LOWER(b.status) = :status';
        $params[':status'] = strtolower($normalized);
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = db()->prepare("SELECT COUNT(*) FROM block b WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = max(0, ($page - 1) * $pageSize);

    $sql = "SELECT
                b.uid,
                b.studentId,
                b.departmentId,
                b.issuedBy,
                b.reason,
                b.status,
                b.createdAt,
                s.firstName AS studentFirstName,
                s.lastName AS studentLastName,
                s.email AS studentEmail,
                d.name AS departmentName,
                i.firstName AS issuerFirstName,
                i.lastName AS issuerLastName
            FROM block b
            INNER JOIN `user` s ON s.uid = b.studentId
            INNER JOIN department d ON d.uid = b.departmentId
            INNER JOIN `user` i ON i.uid = b.issuedBy
            WHERE {$whereSql}
            ORDER BY b.createdAt DESC
            LIMIT :limit OFFSET :offset";

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    return [
        'records' => array_map('mapBlockRow', $stmt->fetchAll()),
        'total' => $total,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function fetchBlockById(string $blockId): ?array
{
    $sql = 'SELECT
                b.uid,
                b.studentId,
                b.departmentId,
                b.issuedBy,
                b.reason,
                b.status,
                b.createdAt,
                s.firstName AS studentFirstName,
                s.lastName AS studentLastName,
                s.email AS studentEmail,
                d.name AS departmentName,
                i.firstName AS issuerFirstName,
                i.lastName AS issuerLastName
            FROM block b
            INNER JOIN `user` s ON s.uid = b.studentId
            INNER JOIN department d ON d.uid = b.departmentId
            INNER JOIN `user` i ON i.uid = b.issuedBy
            WHERE b.uid = :uid
            LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute([':uid' => $blockId]);
    $row = $stmt->fetch();

    return $row ? mapBlockRow($row) : null;
}

function assertStudentExists(string $studentId): void
{
    $stmt = db()->prepare(
        'SELECT uid, role, status FROM `user` WHERE uid = :uid LIMIT 1'
    );
    $stmt->execute([':uid' => $studentId]);
    $student = $stmt->fetch();

    if (!$student) {
        jsonError('Student not found.', 404);
    }
    if (($student['role'] ?? '') !== 'Student') {
        jsonError('Selected user is not a Student.', 422);
    }
    if (($student['status'] ?? '') !== 'Active') {
        jsonError('Student account is not active.', 422);
    }
}

function assertDepartmentExists(string $departmentId): void
{
    $stmt = db()->prepare('SELECT uid FROM department WHERE uid = :uid LIMIT 1');
    $stmt->execute([':uid' => $departmentId]);
    if (!$stmt->fetchColumn()) {
        jsonError('Department not found.', 404);
    }
}
