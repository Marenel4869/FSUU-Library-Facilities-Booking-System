<?php
/**
 * Reporting utilities for admin analytics pages.
 */

/**
 * @return array{from: string, to: string, errors: array<int, string>, hasValidDateRange: bool}
 */
function resolveDateRangeFromQuery(array $query): array {
    $fromDate = isset($query['from']) ? trim((string) $query['from']) : '';
    $toDate = isset($query['to']) ? trim((string) $query['to']) : '';
    $errors = [];

    $isValidYmdDate = static function (string $value): bool {
        if ($value === '') {
            return true;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return $dt !== false && $dt->format('Y-m-d') === $value;
    };

    if (!$isValidYmdDate($fromDate)) {
        $errors[] = 'Invalid From date format. Use YYYY-MM-DD.';
        $fromDate = '';
    }

    if (!$isValidYmdDate($toDate)) {
        $errors[] = 'Invalid To date format. Use YYYY-MM-DD.';
        $toDate = '';
    }

    if ($fromDate !== '' && $toDate !== '' && $fromDate > $toDate) {
        $errors[] = 'From date cannot be later than To date.';
    }

    return [
        'from' => $fromDate,
        'to' => $toDate,
        'errors' => $errors,
        'hasValidDateRange' => empty($errors),
    ];
}

/**
 * @return array<string, array{from: string, to: string}>
 */
function buildQuickDateRangePresets(?DateTimeImmutable $referenceDate = null): array {
    $today = $referenceDate ?? new DateTimeImmutable('today');
    $currentYear = (int) $today->format('Y');
    $currentMonth = (int) $today->format('n');

    $thisMonthFrom = $today->modify('first day of this month')->format('Y-m-d');
    $thisMonthTo = $today->modify('last day of this month')->format('Y-m-d');
    $lastMonthFrom = $today->modify('first day of last month')->format('Y-m-d');
    $lastMonthTo = $today->modify('last day of last month')->format('Y-m-d');

    if ($currentMonth <= 6) {
        $currentSemesterFrom = sprintf('%04d-01-01', $currentYear);
        $currentSemesterTo = sprintf('%04d-06-30', $currentYear);
        $lastSemesterFrom = sprintf('%04d-07-01', $currentYear - 1);
        $lastSemesterTo = sprintf('%04d-12-31', $currentYear - 1);
    } else {
        $currentSemesterFrom = sprintf('%04d-07-01', $currentYear);
        $currentSemesterTo = sprintf('%04d-12-31', $currentYear);
        $lastSemesterFrom = sprintf('%04d-01-01', $currentYear);
        $lastSemesterTo = sprintf('%04d-06-30', $currentYear);
    }

    return [
        'This Month' => ['from' => $thisMonthFrom, 'to' => $thisMonthTo],
        'Last Month' => ['from' => $lastMonthFrom, 'to' => $lastMonthTo],
        'Current Semester' => ['from' => $currentSemesterFrom, 'to' => $currentSemesterTo],
        'Last Semester' => ['from' => $lastSemesterFrom, 'to' => $lastSemesterTo],
    ];
}

function buildDateFilterClause(string $columnName, string $fromDate, string $toDate, bool $hasValidDateRange, array &$params): string {
    if (!$hasValidDateRange) {
        return '';
    }

    $conditions = [];
    if ($fromDate !== '') {
        $conditions[] = $columnName . ' >= :from_date';
        $params[':from_date'] = $fromDate;
    }

    if ($toDate !== '') {
        $conditions[] = $columnName . ' <= :to_date';
        $params[':to_date'] = $toDate;
    }

    return $conditions ? ' AND ' . implode(' AND ', $conditions) : '';
}

/**
 * @return array<int, array<string, mixed>>
 */
function fetchAllAssocPrepared(PDO $pdo, string $sql, array $params): array {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function buildReportsUrl(array $query = []): string {
    $base = APP_URL . '/modules/admin/reports.php';
    return empty($query) ? $base : ($base . '?' . http_build_query($query));
}

function buildReportsExportUrl(string $exportType, string $fromDate, string $toDate): string {
    $query = ['export' => $exportType];

    if ($fromDate !== '') {
        $query['from'] = $fromDate;
    }

    if ($toDate !== '') {
        $query['to'] = $toDate;
    }

    return buildReportsUrl($query);
}
