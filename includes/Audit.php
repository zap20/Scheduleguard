<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/helpers.php';

/**
 * Insert a row into auditLog.
 *
 * Used by write endpoints and by read endpoints that expose another user's data.
 *
 * @param string      $userId           Actor performing the action
 * @param string      $action           e.g. CREATE, UPDATE, DELETE, LOGIN
 * @param string      $module           e.g. auth, schedule, block, enrollment
 * @param string      $message          Human-readable description
 * @param string|null $relatedRecordId  Polymorphic id of the affected record (nullable)
 */
function logAudit(
    string $userId,
    string $action,
    string $module,
    string $message,
    ?string $relatedRecordId = null
): void {
    $stmt = db()->prepare(
        'INSERT INTO auditLog (uid, userId, relatedRecordId, action, module, message, timestamp)
         VALUES (:uid, :userId, :relatedRecordId, :action, :module, :message, NOW())'
    );

    $stmt->execute([
        ':uid' => generateUid(),
        ':userId' => $userId,
        ':relatedRecordId' => $relatedRecordId,
        ':action' => $action,
        ':module' => $module,
        ':message' => $message,
    ]);
}

/**
 * Distinct module names present in auditLog (for filter dropdowns).
 *
 * @return list<string>
 */
function fetchAuditModules(): array
{
    $stmt = db()->query(
        'SELECT DISTINCT module
         FROM auditLog
         ORDER BY module ASC'
    );

    return array_map(static fn(array $row): string => (string) $row['module'], $stmt->fetchAll());
}

/**
 * Paginated audit trail with optional filters.
 *
 * @return array{records: list<array<string,mixed>>, total: int}
 */
function fetchAuditLogs(
    ?string $module,
    ?string $userId,
    ?string $dateFrom,
    ?string $dateTo,
    int $page,
    int $pageSize
): array {
    $where = ['1 = 1'];
    $params = [];

    if ($module !== null && $module !== '') {
        $where[] = 'a.module = :module';
        $params[':module'] = $module;
    }
    if ($userId !== null && $userId !== '') {
        $where[] = 'a.userId = :userId';
        $params[':userId'] = $userId;
    }
    if ($dateFrom !== null && $dateFrom !== '') {
        $where[] = 'DATE(a.timestamp) >= :dateFrom';
        $params[':dateFrom'] = $dateFrom;
    }
    if ($dateTo !== null && $dateTo !== '') {
        $where[] = 'DATE(a.timestamp) <= :dateTo';
        $params[':dateTo'] = $dateTo;
    }

    $whereSql = implode(' AND ', $where);

    $countStmt = db()->prepare("SELECT COUNT(*) FROM auditLog a WHERE {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $offset = max(0, ($page - 1) * $pageSize);
    $sql = "SELECT
                a.uid,
                a.userId,
                a.relatedRecordId,
                a.action,
                a.module,
                a.message,
                a.timestamp,
                u.firstName,
                u.lastName,
                u.email,
                u.role
            FROM auditLog a
            INNER JOIN `user` u ON u.uid = a.userId
            WHERE {$whereSql}
            ORDER BY a.timestamp DESC, a.uid DESC
            LIMIT :limit OFFSET :offset";

    $stmt = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $records = array_map(static function (array $row): array {
        return [
            'uid' => (string) $row['uid'],
            'userId' => (string) $row['userId'],
            'userName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
            'userEmail' => (string) $row['email'],
            'userRole' => (string) $row['role'],
            'action' => (string) $row['action'],
            'module' => (string) $row['module'],
            'message' => (string) $row['message'],
            'relatedRecordId' => $row['relatedRecordId'] !== null ? (string) $row['relatedRecordId'] : null,
            'timestamp' => (string) $row['timestamp'],
        ];
    }, $stmt->fetchAll());

    return [
        'records' => $records,
        'total' => $total,
    ];
}
