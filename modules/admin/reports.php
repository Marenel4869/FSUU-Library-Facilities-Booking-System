<?php
/**
 * FSUU Library Booking System
 * Admin - Reports Module
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';
require_once dirname(__DIR__, 2) . '/includes/report_helpers.php';

requireRole(ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN);

$pdo = getDBConnection();

$dateRange = resolveDateRangeFromQuery($_GET);
$fromDate = $dateRange['from'];
$toDate = $dateRange['to'];
$filterErrors = $dateRange['errors'];
$hasValidDateRange = $dateRange['hasValidDateRange'];

$quickPresets = buildQuickDateRangePresets();

// SQL: Most booked facilities
$mostBookedSql = '
    SELECT f.name, COUNT(b.id) AS total
    FROM facilities f
    LEFT JOIN bookings b ON b.facility_id = f.id
      AND b.status NOT IN ("rejected", "cancelled")
            {{DATE_FILTER}}
    GROUP BY f.id, f.name
    ORDER BY total DESC, f.name ASC
    LIMIT 10
';

// SQL: Peak booking days
$peakDaysSql = '
    SELECT DAYNAME(date) AS day_name, COUNT(*) AS total
    FROM bookings
    WHERE status NOT IN ("rejected", "cancelled")
    {{DATE_FILTER}}
    GROUP BY DAYOFWEEK(date), DAYNAME(date)
    ORDER BY total DESC
';

// SQL: Peak booking months
$peakMonthsSql = '
    SELECT DATE_FORMAT(date, "%Y-%m") AS month_label, COUNT(*) AS total
    FROM bookings
    WHERE status NOT IN ("rejected", "cancelled")
    {{DATE_FILTER}}
    GROUP BY DATE_FORMAT(date, "%Y-%m")
    ORDER BY month_label ASC
';

// SQL: User type usage
$userTypeSql = '
    SELECT u.role, COUNT(b.id) AS total
    FROM users u
    LEFT JOIN bookings b ON b.user_id = u.id
      AND b.status NOT IN ("rejected", "cancelled")
            {{DATE_FILTER}}
    GROUP BY u.role
    ORDER BY total DESC
';

// SQL: Most popular time slots
$timeSlotsSql = '
    SELECT DATE_FORMAT(start_time, "%H:00") AS slot_label, COUNT(*) AS total
    FROM bookings
    WHERE status NOT IN ("rejected", "cancelled")
    {{DATE_FILTER}}
    GROUP BY DATE_FORMAT(start_time, "%H:00")
    ORDER BY total DESC, slot_label ASC
';

$mostBookedParams = [];
$peakDaysParams = [];
$peakMonthsParams = [];
$userTypeParams = [];
$timeSlotsParams = [];

$mostBookedQuerySql = str_replace('{{DATE_FILTER}}', buildDateFilterClause('b.date', $fromDate, $toDate, $hasValidDateRange, $mostBookedParams), $mostBookedSql);
$peakDaysQuerySql = str_replace('{{DATE_FILTER}}', buildDateFilterClause('date', $fromDate, $toDate, $hasValidDateRange, $peakDaysParams), $peakDaysSql);
$peakMonthsQuerySql = str_replace('{{DATE_FILTER}}', buildDateFilterClause('date', $fromDate, $toDate, $hasValidDateRange, $peakMonthsParams), $peakMonthsSql);
$userTypeQuerySql = str_replace('{{DATE_FILTER}}', buildDateFilterClause('b.date', $fromDate, $toDate, $hasValidDateRange, $userTypeParams), $userTypeSql);
$timeSlotsQuerySql = str_replace('{{DATE_FILTER}}', buildDateFilterClause('date', $fromDate, $toDate, $hasValidDateRange, $timeSlotsParams), $timeSlotsSql);

$mostBookedFacilities = fetchAllAssocPrepared($pdo, $mostBookedQuerySql, $mostBookedParams);
$peakBookingDays = fetchAllAssocPrepared($pdo, $peakDaysQuerySql, $peakDaysParams);
$peakBookingMonths = fetchAllAssocPrepared($pdo, $peakMonthsQuerySql, $peakMonthsParams);
$userTypeUsage = fetchAllAssocPrepared($pdo, $userTypeQuerySql, $userTypeParams);
$popularTimeSlots = fetchAllAssocPrepared($pdo, $timeSlotsQuerySql, $timeSlotsParams);

if (isset($_GET['export']) && (string) $_GET['export'] === 'pdf') {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: inline; filename="report_' . date('Ymd_His') . '.html"');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Report Export (PDF Ready)</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 24px; color: #03045E; }
            h1 { margin-bottom: 4px; }
            .muted { color: #0077B6; margin-bottom: 16px; }
            h2 { margin-top: 28px; margin-bottom: 8px; font-size: 18px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
            th, td { border: 1px solid #90E0EF; padding: 8px; text-align: left; }
            th { background: #CAF0F8; }
            .note { margin-top: 20px; font-size: 12px; color: #0077B6; }
            @media print { .note { display: none; } }
        </style>
    </head>
    <body>
        <h1>FSUU Library Booking Report</h1>
        <div class="muted">Generated: <?= sanitize(date('Y-m-d H:i:s')) ?><?= ($fromDate !== '' || $toDate !== '') ? (' | Range: ' . sanitize($fromDate !== '' ? $fromDate : 'start') . ' to ' . sanitize($toDate !== '' ? $toDate : 'end')) : '' ?></div>

        <h2>Most Booked Facilities</h2>
        <table><thead><tr><th>Facility</th><th>Bookings</th></tr></thead><tbody>
        <?php foreach ($mostBookedFacilities as $row): ?><tr><td><?= sanitize($row['name']) ?></td><td><?= (int) $row['total'] ?></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2>Peak Booking Days</h2>
        <table><thead><tr><th>Day</th><th>Bookings</th></tr></thead><tbody>
        <?php foreach ($peakBookingDays as $row): ?><tr><td><?= sanitize($row['day_name']) ?></td><td><?= (int) $row['total'] ?></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2>Peak Booking Months</h2>
        <table><thead><tr><th>Month</th><th>Bookings</th></tr></thead><tbody>
        <?php foreach ($peakBookingMonths as $row): ?><tr><td><?= sanitize($row['month_label']) ?></td><td><?= (int) $row['total'] ?></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2>User Type Usage</h2>
        <table><thead><tr><th>Role</th><th>Bookings</th></tr></thead><tbody>
        <?php foreach ($userTypeUsage as $row): ?><tr><td><?= strtoupper(sanitize((string) $row['role'])) ?></td><td><?= (int) $row['total'] ?></td></tr><?php endforeach; ?>
        </tbody></table>

        <h2>Most Popular Time Slots</h2>
        <table><thead><tr><th>Start Hour</th><th>Bookings</th></tr></thead><tbody>
        <?php foreach ($popularTimeSlots as $row): ?><tr><td><?= sanitize((string) $row['slot_label']) ?></td><td><?= (int) $row['total'] ?></td></tr><?php endforeach; ?>
        </tbody></table>

        <div class="note">Use your browser Print dialog and choose "Save as PDF" for PDF export.</div>
        <script>window.print();</script>
    </body>
    </html>
    <?php
    exit;
}

$allowedExports = [
    'facilities' => ['rows' => $mostBookedFacilities, 'headers' => ['facility', 'bookings']],
    'days'       => ['rows' => $peakBookingDays, 'headers' => ['day', 'bookings']],
    'months'     => ['rows' => $peakBookingMonths, 'headers' => ['month', 'bookings']],
    'users'      => ['rows' => $userTypeUsage, 'headers' => ['role', 'bookings']],
    'timeslots'  => ['rows' => $popularTimeSlots, 'headers' => ['start_hour', 'bookings']],
];

if (isset($_GET['export'])) {
    $key = (string) $_GET['export'];
    if (!isset($allowedExports[$key])) {
        http_response_code(400);
        exit('Invalid export type.');
    }

    $rangeLabel = '';
    if ($fromDate !== '' || $toDate !== '') {
        $rangeLabel = '_' . ($fromDate !== '' ? $fromDate : 'start') . '_to_' . ($toDate !== '' ? $toDate : 'end');
    }

    $filename = 'report_' . $key . $rangeLabel . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');
    if ($output === false) {
        http_response_code(500);
        exit('Unable to open output stream.');
    }

    fputcsv($output, $allowedExports[$key]['headers']);

    if ($fromDate !== '' || $toDate !== '') {
        fputcsv($output, ['from', $fromDate !== '' ? $fromDate : 'N/A']);
        fputcsv($output, ['to', $toDate !== '' ? $toDate : 'N/A']);
        fputcsv($output, []);
    }

    foreach ($allowedExports[$key]['rows'] as $row) {
        if ($key === 'facilities') {
            fputcsv($output, [$row['name'], (int) $row['total']]);
        } elseif ($key === 'days') {
            fputcsv($output, [$row['day_name'], (int) $row['total']]);
        } elseif ($key === 'months') {
            fputcsv($output, [$row['month_label'], (int) $row['total']]);
        } elseif ($key === 'users') {
            fputcsv($output, [strtoupper((string) $row['role']), (int) $row['total']]);
        } elseif ($key === 'timeslots') {
            fputcsv($output, [$row['slot_label'], (int) $row['total']]);
        }
    }

    fclose($output);
    exit;
}

renderPageStart('Reports');
?>

<main class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-maroon mb-0"><i class="bi bi-bar-chart-line me-1"></i> Reporting Module</h5>
        <div class="d-flex gap-2">
            <a href="<?= sanitize(buildReportsExportUrl('pdf', $fromDate, $toDate)) ?>" class="btn btn-outline-dark btn-sm">Export PDF</a>
            <a href="<?= APP_URL ?>/modules/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">Back</a>
        </div>
    </div>

    <?php if (!empty($filterErrors)): ?>
        <div class="alert alert-warning" role="alert">
            <?= sanitize(implode(' ', $filterErrors)) ?>
        </div>
    <?php endif; ?>

    <div class="card mb-4">
        <div class="card-body">
            <form method="get" action="<?= buildReportsUrl() ?>" class="row g-3 align-items-end">
                <div class="col-sm-6 col-lg-4">
                    <label for="from" class="form-label">From</label>
                    <input type="date" id="from" name="from" class="form-control" value="<?= sanitize($fromDate) ?>">
                </div>
                <div class="col-sm-6 col-lg-4">
                    <label for="to" class="form-label">To</label>
                    <input type="date" id="to" name="to" class="form-control" value="<?= sanitize($toDate) ?>">
                </div>
                <div class="col-lg-4 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                    <a href="<?= buildReportsUrl() ?>" class="btn btn-outline-secondary">Reset</a>
                </div>
                <div class="col-12">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="small text-muted me-1">Quick presets:</span>
                        <?php foreach ($quickPresets as $label => $range): ?>
                            <a
                                href="<?= sanitize(buildReportsUrl(['from' => $range['from'], 'to' => $range['to']])) ?>"
                                class="btn btn-sm btn-outline-dark"
                            >
                                <?= sanitize($label) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Most Booked Facilities</span>
                    <a href="<?= sanitize(buildReportsExportUrl('facilities', $fromDate, $toDate)) ?>" class="btn btn-sm btn-light">Export CSV</a>
                </div>
                <div class="card-body" style="height: 300px;">
                    <canvas id="facilitiesChart"></canvas>
                </div>
                <div class="card-body p-0 border-top">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Facility</th><th class="text-end">Bookings</th></tr></thead>
                            <tbody>
                            <?php if ($mostBookedFacilities): ?>
                                <?php foreach ($mostBookedFacilities as $row): ?>
                                    <tr>
                                        <td><?= sanitize($row['name']) ?></td>
                                        <td class="text-end"><?= (int) $row['total'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" class="text-center text-muted py-3">No booking data yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Peak Booking Days</span>
                    <a href="<?= sanitize(buildReportsExportUrl('days', $fromDate, $toDate)) ?>" class="btn btn-sm btn-light">Export CSV</a>
                </div>
                <div class="card-body" style="height: 300px;">
                    <canvas id="daysChart"></canvas>
                </div>
                <div class="card-body p-0 border-top">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Day</th><th class="text-end">Bookings</th></tr></thead>
                            <tbody>
                            <?php if ($peakBookingDays): ?>
                                <?php foreach ($peakBookingDays as $row): ?>
                                    <tr>
                                        <td><?= sanitize($row['day_name']) ?></td>
                                        <td class="text-end"><?= (int) $row['total'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" class="text-center text-muted py-3">No day usage data yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Most Popular Time Slots</span>
            <a href="<?= sanitize(buildReportsExportUrl('timeslots', $fromDate, $toDate)) ?>" class="btn btn-sm btn-light">Export CSV</a>
        </div>
        <div class="card-body" style="height: 300px;">
            <canvas id="timeSlotsChart"></canvas>
        </div>
        <div class="card-body p-0 border-top">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Start Hour</th><th class="text-end">Bookings</th></tr></thead>
                    <tbody>
                    <?php if ($popularTimeSlots): ?>
                        <?php foreach ($popularTimeSlots as $row): ?>
                            <tr>
                                <td><?= sanitize((string) $row['slot_label']) ?></td>
                                <td class="text-end"><?= (int) $row['total'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="2" class="text-center text-muted py-3">No time slot usage data yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Peak Booking Months</span>
                    <a href="<?= sanitize(buildReportsExportUrl('months', $fromDate, $toDate)) ?>" class="btn btn-sm btn-light">Export CSV</a>
                </div>
                <div class="card-body" style="height: 300px;">
                    <canvas id="monthsChart"></canvas>
                </div>
                <div class="card-body p-0 border-top">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Month</th><th class="text-end">Bookings</th></tr></thead>
                            <tbody>
                            <?php if ($peakBookingMonths): ?>
                                <?php foreach ($peakBookingMonths as $row): ?>
                                    <tr>
                                        <td><?= sanitize($row['month_label']) ?></td>
                                        <td class="text-end"><?= (int) $row['total'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" class="text-center text-muted py-3">No monthly data yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>User Type Usage</span>
                    <a href="<?= sanitize(buildReportsExportUrl('users', $fromDate, $toDate)) ?>" class="btn btn-sm btn-light">Export CSV</a>
                </div>
                <div class="card-body" style="height: 300px;">
                    <canvas id="userTypeChart"></canvas>
                </div>
                <div class="card-body p-0 border-top">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Role</th><th class="text-end">Bookings</th></tr></thead>
                            <tbody>
                            <?php if ($userTypeUsage): ?>
                                <?php foreach ($userTypeUsage as $row): ?>
                                    <tr>
                                        <td><?= strtoupper(sanitize($row['role'])) ?></td>
                                        <td class="text-end"><?= (int) $row['total'] ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="2" class="text-center text-muted py-3">No user usage data yet.</td></tr>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">SQL Queries Used</div>
        <div class="card-body">
            <div class="mb-3">
                <div class="fw-semibold mb-1">Most booked facilities</div>
                <pre class="bg-light border rounded p-2 small mb-0"><code><?= sanitize($mostBookedQuerySql) ?></code></pre>
            </div>
            <div class="mb-3">
                <div class="fw-semibold mb-1">Peak booking days</div>
                <pre class="bg-light border rounded p-2 small mb-0"><code><?= sanitize($peakDaysQuerySql) ?></code></pre>
            </div>
            <div class="mb-3">
                <div class="fw-semibold mb-1">Peak booking months</div>
                <pre class="bg-light border rounded p-2 small mb-0"><code><?= sanitize($peakMonthsQuerySql) ?></code></pre>
            </div>
            <div>
                <div class="fw-semibold mb-1">User type usage</div>
                <pre class="bg-light border rounded p-2 small mb-0"><code><?= sanitize($userTypeQuerySql) ?></code></pre>
            </div>
            <div class="mt-3">
                <div class="fw-semibold mb-1">Popular time slots</div>
                <pre class="bg-light border rounded p-2 small mb-0"><code><?= sanitize($timeSlotsQuerySql) ?></code></pre>
            </div>
        </div>
    </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
    const facilitiesLabels = <?= json_encode(array_map(static fn($r) => $r['name'], $mostBookedFacilities), JSON_UNESCAPED_UNICODE) ?>;
    const facilitiesData = <?= json_encode(array_map(static fn($r) => (int) $r['total'], $mostBookedFacilities), JSON_UNESCAPED_UNICODE) ?>;

    const daysLabels = <?= json_encode(array_map(static fn($r) => $r['day_name'], $peakBookingDays), JSON_UNESCAPED_UNICODE) ?>;
    const daysData = <?= json_encode(array_map(static fn($r) => (int) $r['total'], $peakBookingDays), JSON_UNESCAPED_UNICODE) ?>;

    const monthsLabels = <?= json_encode(array_map(static fn($r) => $r['month_label'], $peakBookingMonths), JSON_UNESCAPED_UNICODE) ?>;
    const monthsData = <?= json_encode(array_map(static fn($r) => (int) $r['total'], $peakBookingMonths), JSON_UNESCAPED_UNICODE) ?>;

    const userTypeLabels = <?= json_encode(array_map(static fn($r) => strtoupper((string) $r['role']), $userTypeUsage), JSON_UNESCAPED_UNICODE) ?>;
    const userTypeData = <?= json_encode(array_map(static fn($r) => (int) $r['total'], $userTypeUsage), JSON_UNESCAPED_UNICODE) ?>;

    const timeSlotLabels = <?= json_encode(array_map(static fn($r) => (string) $r['slot_label'], $popularTimeSlots), JSON_UNESCAPED_UNICODE) ?>;
    const timeSlotData = <?= json_encode(array_map(static fn($r) => (int) $r['total'], $popularTimeSlots), JSON_UNESCAPED_UNICODE) ?>;

    const commonBarOptions = {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: { precision: 0 }
            }
        }
    };

    new Chart(document.getElementById('facilitiesChart'), {
        type: 'bar',
        data: {
            labels: facilitiesLabels,
            datasets: [{
                label: 'Bookings',
                data: facilitiesData,
                backgroundColor: '#03045E'
            }]
        },
        options: commonBarOptions
    });

    new Chart(document.getElementById('daysChart'), {
        type: 'bar',
        data: {
            labels: daysLabels,
            datasets: [{
                label: 'Bookings',
                data: daysData,
                backgroundColor: '#0077B6'
            }]
        },
        options: commonBarOptions
    });

    new Chart(document.getElementById('monthsChart'), {
        type: 'line',
        data: {
            labels: monthsLabels,
            datasets: [{
                label: 'Bookings',
                data: monthsData,
                borderColor: '#00B4D8',
                backgroundColor: 'rgba(144,224,239,0.35)',
                tension: 0.35,
                fill: true
            }]
        },
        options: commonBarOptions
    });

    new Chart(document.getElementById('userTypeChart'), {
        type: 'doughnut',
        data: {
            labels: userTypeLabels,
            datasets: [{
                data: userTypeData,
                backgroundColor: ['#03045E', '#0077B6', '#00B4D8', '#90E0EF', '#CAF0F8']
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });

    new Chart(document.getElementById('timeSlotsChart'), {
        type: 'bar',
        data: {
            labels: timeSlotLabels,
            datasets: [{
                label: 'Bookings',
                data: timeSlotData,
                backgroundColor: '#0077B6'
            }]
        },
        options: commonBarOptions
    });
</script>

<?php renderPageEnd(); ?>

