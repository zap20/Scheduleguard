<?php

declare(strict_types=1);

require_once __DIR__ . '/Blocking.php';

const ENROLLMENT_EVAL_PENDING = 'Pending';
const ENROLLMENT_EVAL_APPROVED = 'Approved';
const ENROLLMENT_EVAL_REJECTED = 'Rejected';

/**
 * @return array<string,mixed>|null
 */
function fetchStudentEvaluationRow(string $studentId): ?array
{
    $stmt = db()->prepare(
        'SELECT u.uid, u.firstName, u.lastName, u.email, u.schoolId, u.departmentId,
                u.yearLevel, u.studentType, u.status, u.role,
                u.enrollmentEvalStatus, u.enrollmentEvalBy, u.enrollmentEvalAt, u.enrollmentEvalNotes,
                e.firstName AS evalFirstName, e.lastName AS evalLastName
         FROM userProfile u
         LEFT JOIN `user` e ON e.uid = u.enrollmentEvalBy
         WHERE u.uid = :uid
         LIMIT 1'
    );
    $stmt->execute([':uid' => $studentId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    return mapStudentEvaluationRow($row);
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function mapStudentEvaluationRow(array $row): array
{
    $status = trim((string) ($row['enrollmentEvalStatus'] ?? ''));
    if ($status === '') {
        $status = ENROLLMENT_EVAL_PENDING;
    }
    $evalBy = isset($row['enrollmentEvalBy']) && $row['enrollmentEvalBy'] !== null
        ? (string) $row['enrollmentEvalBy']
        : '';
    $evalName = trim(
        (string) ($row['evalFirstName'] ?? '') . ' ' . (string) ($row['evalLastName'] ?? '')
    );

    return [
        'uid' => (string) $row['uid'],
        'firstName' => (string) $row['firstName'],
        'lastName' => (string) $row['lastName'],
        'fullName' => trim((string) $row['firstName'] . ' ' . (string) $row['lastName']),
        'email' => (string) $row['email'],
        'schoolId' => (string) ($row['schoolId'] ?? ''),
        'departmentId' => (string) ($row['departmentId'] ?? ''),
        'yearLevel' => (string) ($row['yearLevel'] ?? ''),
        'studentType' => (string) ($row['studentType'] ?? ''),
        'status' => (string) ($row['status'] ?? ''),
        'cleared' => isStudentCleared((string) $row['uid']),
        'enrollmentEvalStatus' => $status,
        'enrollmentEvalBy' => $evalBy,
        'enrollmentEvalByName' => $evalName,
        'enrollmentEvalAt' => isset($row['enrollmentEvalAt']) && $row['enrollmentEvalAt'] !== null
            ? (string) $row['enrollmentEvalAt']
            : null,
        'enrollmentEvalNotes' => (string) ($row['enrollmentEvalNotes'] ?? ''),
    ];
}

function isStudentEnrollmentApproved(string $studentId): bool
{
    $row = fetchStudentEvaluationRow($studentId);
    if ($row === null) {
        return false;
    }

    return strcasecmp((string) $row['enrollmentEvalStatus'], ENROLLMENT_EVAL_APPROVED) === 0;
}

/**
 * Cleared students in department waiting for / already evaluated (optional filters).
 *
 * @return list<array<string,mixed>>
 */
function fetchStudentEvaluationQueue(
    string $departmentId,
    ?string $yearLevel = null,
    ?string $studentType = null,
    ?string $evalStatus = null
): array {
    $sql = 'SELECT u.uid, u.firstName, u.lastName, u.email, u.schoolId, u.departmentId,
                   u.yearLevel, u.studentType, u.status, u.role,
                   u.enrollmentEvalStatus, u.enrollmentEvalBy, u.enrollmentEvalAt, u.enrollmentEvalNotes,
                   e.firstName AS evalFirstName, e.lastName AS evalLastName
            FROM userProfile u
            LEFT JOIN `user` e ON e.uid = u.enrollmentEvalBy
            WHERE u.role = \'Student\'
              AND u.status = \'Active\'
              AND u.departmentId = :departmentId';
    $params = [':departmentId' => $departmentId];

    if ($yearLevel !== null && $yearLevel !== '') {
        $sql .= ' AND u.yearLevel = :yearLevel';
        $params[':yearLevel'] = $yearLevel;
    }
    if ($studentType !== null && $studentType !== '') {
        $norm = strcasecmp($studentType, 'irregular') === 0 ? 'Irregular' : 'Regular';
        $sql .= ' AND u.studentType = :studentType';
        $params[':studentType'] = $norm;
    }
    if ($evalStatus !== null && $evalStatus !== '') {
        $sql .= ' AND COALESCE(NULLIF(u.enrollmentEvalStatus, \'\'), \'Pending\') = :evalStatus';
        $params[':evalStatus'] = $evalStatus;
    }

    $sql .= ' ORDER BY
                FIELD(COALESCE(NULLIF(u.enrollmentEvalStatus, \'\'), \'Pending\'), \'Pending\', \'Rejected\', \'Approved\'),
                u.lastName ASC, u.firstName ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $mapped = mapStudentEvaluationRow($row);
        if (!$mapped['cleared']) {
            continue;
        }
        $out[] = $mapped;
    }

    return $out;
}

/**
 * @return array<string,mixed>
 */
function evaluateStudentForEnrollment(
    string $studentId,
    string $evaluatedBy,
    string $status = ENROLLMENT_EVAL_APPROVED,
    string $notes = ''
): array {
    // Evaluation is approve-only (no Reject path in the Program Head UI).
    $status = ENROLLMENT_EVAL_APPROVED;
    $notes = trim($notes);
    if (strlen($notes) > 500) {
        throw new InvalidArgumentException('Notes must be at most 500 characters.');
    }

    $student = fetchStudentEvaluationRow($studentId);
    if ($student === null || ($student['status'] ?? '') !== 'Active') {
        throw new InvalidArgumentException('Student not found.');
    }
    if (!$student['cleared']) {
        throw new DomainException('Student is not cleared (active blocking hold). Clear blocking first.');
    }

    $stmt = db()->prepare(
        'UPDATE student
         SET enrollmentEvalStatus = :status,
             enrollmentEvalBy = :by,
             enrollmentEvalAt = NOW(),
             enrollmentEvalNotes = :notes
         WHERE userId = :uid'
    );
    $stmt->execute([
        ':status' => $status,
        ':by' => $evaluatedBy,
        ':notes' => $notes !== '' ? $notes : null,
        ':uid' => $studentId,
    ]);

    $updated = fetchStudentEvaluationRow($studentId);
    if ($updated === null) {
        throw new RuntimeException('Failed to load evaluation after save.');
    }

    return $updated;
}
