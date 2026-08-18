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

/**
 * Attendance oversight period windows. Default is monthly.
 *
 * @return array{
 *   period:string,
 *   label:string,
 *   dateFrom:string,
 *   dateTo:string,
 *   academicYear:?int,
 *   semester:?string
 * }
 */
function resolveAttendancePeriod(string $period = 'monthly'): array
{
    $period = strtolower(trim($period));
    if (!in_array($period, ['daily', 'weekly', 'monthly', 'semester', 'year'], true)) {
        $period = 'monthly';
    }

    $today = new DateTimeImmutable('today');
    $term = currentTermWindow();

    if ($period === 'daily') {
        $from = $today;
        $to = $today;
        $label = 'Daily · ' . $today->format('M j, Y');
    } elseif ($period === 'weekly') {
        // ISO week: Monday–Sunday containing today.
        $from = $today->modify('monday this week');
        $to = $today->modify('sunday this week');
        $label = 'Weekly · ' . $from->format('M j') . ' – ' . $to->format('M j, Y');
    } elseif ($period === 'year') {
        $from = $today->setDate((int) $today->format('Y'), 1, 1);
        $to = $today->setDate((int) $today->format('Y'), 12, 31);
        $label = 'Year · ' . $today->format('Y');
    } elseif ($period === 'semester') {
        try {
            $from = new DateTimeImmutable($term['start']);
        } catch (Exception) {
            $from = $today->modify('first day of January this year');
        }
        try {
            $to = new DateTimeImmutable($term['end']);
        } catch (Exception) {
            $to = $today->modify('last day of December this year');
        }
        $label = 'Semester · ' . formatTermLabel($term['academicYear'], $term['semester']);
    } else {
        // monthly (default)
        $from = $today->modify('first day of this month');
        $to = $today->modify('last day of this month');
        $label = 'Monthly · ' . $today->format('F Y');
        $period = 'monthly';
    }

    return [
        'period' => $period,
        'label' => $label,
        'dateFrom' => $from->format('Y-m-d'),
        'dateTo' => $to->format('Y-m-d'),
        'academicYear' => $period === 'semester' ? $term['academicYear'] : null,
        'semester' => $period === 'semester' ? $term['semester'] : null,
    ];
}
