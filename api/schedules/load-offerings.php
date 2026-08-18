<?php

declare(strict_types=1);

/**
 * GET /api/schedules/load-offerings.php?facultyId=...&subjectCode=...
 * Returns every TBF offering of the subject that is conflict-free for the faculty.
 * No selection is made — the dean picks from the returned list.
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/FacultyLoad.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('Method not allowed.', 405);
}

$user = requireRoles(['Dean']);
$departmentId = userDepartmentId($user['uid']);
if ($departmentId === null) {
    jsonError('Dean account has no assigned department.', 403);
}

$facultyId = trim((string) ($_GET['facultyId'] ?? ''));
$subjectCode = trim((string) ($_GET['subjectCode'] ?? ''));
$subjectId = trim((string) ($_GET['subjectId'] ?? ''));

if ($facultyId === '') {
    jsonError('facultyId is required.', 422);
}
if ($subjectCode === '' && $subjectId === '') {
    jsonError('subjectCode or subjectId is required.', 422);
}

// Resolve subject.
$subject = null;
if ($subjectId !== '') {
    $subject = fetchSubjectById($subjectId);
} else {
    $subject = findSubjectByCodeForLoad($subjectCode, $departmentId);
}
if ($subject === null) {
    jsonError('Subject not found.', 404);
}

$term = currentTermWindow();
$academicYear = (int) $term['academicYear'];
$semester = (string) $term['semester'];

// Current load for this faculty.
$currentLoad = currentFacultyLoadForTerm($facultyId, $academicYear, $semester);

// All TBF raw offerings for the subject.
$rawOfferings = listTbfSubjectOfferings((string) $subject['uid'], $academicYear, $semester);

// Enrich and filter — only conflict-free for this faculty.
$allOfferings = [];
$conflictCount = 0;
foreach ($rawOfferings as $rawOffering) {
    $enriched = enrichOfferingForLoadPreview($rawOffering, $subject);
    $canAssign = facultyCanTakeOffering($facultyId, $rawOffering, $academicYear, $semester);
    if ($canAssign) {
        $allOfferings[] = $enriched;
    } else {
        $conflictCount++;
    }
}

$loadPer = subjectTeachingLoadFromHours(
    (float) $subject['lectureHours'],
    (float) $subject['labHours']
);

// Suggest (mark only, do NOT pre-select — user decides).
// We rank by schedule density: offerings with more distinct days first so distribution is good.
usort($allOfferings, static function (array $a, array $b): int {
    $dayA = count(array_unique(array_column($a['meetings'] ?? [], 'day')));
    $dayB = count(array_unique(array_column($b['meetings'] ?? [], 'day')));
    if ($dayB !== $dayA) {
        return $dayB - $dayA;
    }
    return strcmp((string) ($a['blockLabel'] ?? ''), (string) ($b['blockLabel'] ?? ''));
});

jsonSuccess([
    'subject' => [
        'uid' => $subject['uid'],
        'code' => $subject['code'],
        'title' => $subject['title'],
        'lectureHours' => (float) $subject['lectureHours'],
        'labHours' => (float) $subject['labHours'],
        'loadPerOffering' => round($loadPer, 4),
        'loadFormula' => sprintf(
            '(%s lec + %s lab) ÷ 3 = %s per offering',
            rtrim(rtrim(number_format((float) $subject['lectureHours'], 2), '0'), '.'),
            rtrim(rtrim(number_format((float) $subject['labHours'], 2), '0'), '.'),
            rtrim(rtrim(number_format($loadPer, 4), '0'), '.')
        ),
    ],
    'faculty' => [
        'uid' => $facultyId,
        'currentLoad' => $currentLoad['load'],
        'currentContactHours' => $currentLoad['contactHours'],
    ],
    'offerings' => $allOfferings,
    'totalAvailable' => count($allOfferings),
    'conflictSkipped' => $conflictCount,
    'term' => $term,
]);
