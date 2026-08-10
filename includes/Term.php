<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/env.php';

const SCHEDULE_SEMESTERS = ['1', '2', 'Summer'];

/**
 * Active academic term (year + semester). Schedules and enrollment are scoped to this.
 *
 * @return array{
 *   label:string,
 *   academicYear:int,
 *   semester:string,
 *   start:string,
 *   end:string
 * }
 */
function currentTermWindow(): array
{
    $semester = trim((string) env('CURRENT_SEMESTER', '1'));
    if (!in_array($semester, SCHEDULE_SEMESTERS, true)) {
        $semester = '1';
    }

    $year = (int) env('CURRENT_ACADEMIC_YEAR', (string) date('Y'));
    if ($year < 2000 || $year > 2100) {
        $year = (int) date('Y');
    }

    $label = trim((string) env('CURRENT_TERM', ''));
    if ($label === '') {
        $label = sprintf('AY%d-%d Sem %s', $year, $year + 1, $semester);
    }

    return [
        'label' => $label,
        'academicYear' => $year,
        'semester' => $semester,
        'start' => (string) env('CURRENT_TERM_START', '2000-01-01'),
        'end' => (string) env('CURRENT_TERM_END', '2100-12-31'),
    ];
}

/**
 * @return array{academicYear:int,semester:string,label:string}
 */
function normalizeTermFields(?int $academicYear, ?string $semester): array
{
    $current = currentTermWindow();
    $year = $academicYear ?? $current['academicYear'];
    $sem = $semester !== null && $semester !== '' ? trim($semester) : $current['semester'];

    if ($year < 2000 || $year > 2100) {
        throw new InvalidArgumentException('academicYear must be between 2000 and 2100.');
    }
    if (!in_array($sem, SCHEDULE_SEMESTERS, true)) {
        throw new InvalidArgumentException('semester must be 1, 2, or Summer.');
    }

    return [
        'academicYear' => $year,
        'semester' => $sem,
        'label' => sprintf('AY%d-%d · Sem %s', $year, $year + 1, $sem),
    ];
}

function formatTermLabel(int $academicYear, string $semester): string
{
    return sprintf('AY%d-%d · Sem %s', $academicYear, $academicYear + 1, $semester);
}
