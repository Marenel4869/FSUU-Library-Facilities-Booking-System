<?php
/**
 * FSUU Library Booking System
 * Admin Dashboard UI
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireRole(ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN);

$pdo = getDBConnection();

$stats = [];
$facilityRows = [];
$bookingRows = [];
$userRows = [];
$statusReport = [];
$facilityReport = [];
$auditRows = [];

try {
    $stats = [
        'users'      => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
        'facilities' => (int) $pdo->query('SELECT COUNT(*) FROM facilities')->fetchColumn(),
        'bookings'   => (int) $pdo->query('SELECT COUNT(*) FROM bookings')->fetchColumn(),
        'pending'    => (int) $pdo->query("SELECT COUNT(*) FROM bookings WHERE status = 'pending'")->fetchColumn(),
    ];

    $facilityRows = $pdo->query('
        SELECT id, name, type, location, capacity_min, capacity_max, availability
        FROM facilities
        WHERE availability = 1
        ORDER BY location ASC, name ASC
    ')->fetchAll();

    $bookingRows = $pdo->query('
        SELECT b.id, b.date, b.start_time, b.end_time, b.status, f.name AS facility_name, u.name AS requester_name
        FROM bookings b
        JOIN facilities f ON f.id = b.facility_id
        JOIN users u ON u.id = b.user_id
        ORDER BY b.id DESC
        LIMIT 8
    ')->fetchAll();

    $userRows = $pdo->query('
        SELECT id, name, email, role, status
        FROM users
        ORDER BY id DESC
        LIMIT 8
    ')->fetchAll();

    $statusReport = $pdo->query('
        SELECT status, COUNT(*) AS total
        FROM bookings
        GROUP BY status
        ORDER BY total DESC
    ')->fetchAll();

    $facilityReport = $pdo->query('
        SELECT f.name, COUNT(b.id) AS total
        FROM facilities f
        LEFT JOIN bookings b ON b.facility_id = f.id
        GROUP BY f.id, f.name
        ORDER BY total DESC, f.name ASC
        LIMIT 5
    ')->fetchAll();

    $auditRows = $pdo->query('
        SELECT a.id, a.action, a.timestamp, u.name AS actor_name
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        ORDER BY a.timestamp DESC
        LIMIT 8
    ')->fetchAll();
} catch (Throwable $e) {
    error_log('Admin dashboard load failed: ' . $e->getMessage());
    setFlash('danger', 'Some dashboard data could not be loaded.');
}

renderPageStart('Admin Dashboard');
?>

<main class="container py-4 admin-dashboard-shell">
    <?= getFlash() ?>

    <h4 class="fw-bold text-maroon mb-4">
        <i class="bi bi-speedometer2 me-1"></i> Admin Control Center
    </h4>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-lg-3"><div class="card stat-card h-100"><div class="text-muted small">Total Users</div><div class="stat-number"><?= (int) ($stats['users'] ?? 0) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card stat-card h-100"><div class="text-muted small">Total Facilities</div><div class="stat-number"><?= (int) ($stats['facilities'] ?? 0) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card stat-card h-100"><div class="text-muted small">All Bookings</div><div class="stat-number"><?= (int) ($stats['bookings'] ?? 0) ?></div></div></div>
        <div class="col-sm-6 col-lg-3"><div class="card stat-card h-100"><div class="text-muted small">Pending Review</div><div class="stat-number"><?= (int) ($stats['pending'] ?? 0) ?></div></div></div>
    </div>

    <section class="card admin-section mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-building me-1"></i> Viewable Facilities</span>
            <div class="d-flex gap-2">
                <a href="<?= APP_URL ?>/modules/admin/manage_facilities.php" class="btn btn-sm btn-light">View All</a>
                <a href="<?= APP_URL ?>/modules/admin/manage_facilities.php?action=create" class="btn btn-sm btn-gold">Add Facility</a>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($facilityRows): ?>
                            <?php foreach ($facilityRows as $f): ?>
                                <tr>
                                    <td><?= sanitize($f['name']) ?></td>
                                    <td><?= ucwords(str_replace('_', ' ', sanitize($f['type']))) ?></td>
                                    <td><?= sanitize($f['location'] ?? 'Main Campus') ?></td>
                                    <td><?= (int) $f['capacity_min'] ?>-<?= (int) $f['capacity_max'] ?></td>
                                    <td>
                                        <?php if ((int) $f['availability'] === 1): ?>
                                            <span class="badge bg-success">Available</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Unavailable</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/modules/admin/manage_facilities.php?action=edit&id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                                        <a href="<?= APP_URL ?>/modules/admin/manage_facilities.php?action=delete&id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-danger">Delete</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No facilities found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card admin-section mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-calendar-check me-1"></i> Manage Bookings</span>
            <a href="<?= APP_URL ?>/modules/admin/manage_bookings.php" class="btn btn-sm btn-light">Open Booking Manager</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Requester</th>
                            <th>Facility</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($bookingRows): ?>
                            <?php foreach ($bookingRows as $b): ?>
                                <tr>
                                    <td><?= sanitize($b['requester_name']) ?></td>
                                    <td><?= sanitize($b['facility_name']) ?></td>
                                    <td><?= formatDate($b['date']) ?></td>
                                    <td><?= formatTime($b['start_time']) ?> - <?= formatTime($b['end_time']) ?></td>
                                    <td><?= statusBadge($b['status']) ?></td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/modules/admin/manage_bookings.php#booking-<?= (int) $b['id'] ?>" class="btn btn-sm btn-outline-primary">Review</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No bookings available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="card admin-section mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-people me-1"></i> User Management</span>
            <a href="<?= APP_URL ?>/modules/admin/manage_users.php" class="btn btn-sm btn-light">Open User Manager</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($userRows): ?>
                            <?php foreach ($userRows as $u): ?>
                                <tr>
                                    <td><?= sanitize($u['name']) ?></td>
                                    <td><?= sanitize($u['email']) ?></td>
                                    <td><span class="badge bg-dark"><?= strtoupper(sanitize($u['role'])) ?></span></td>
                                    <td>
                                        <?php if (sanitize($u['status']) === 'active'): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary"><?= ucfirst(sanitize($u['status'])) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/modules/admin/manage_users.php#user-<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline-primary">Manage</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No users available.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <section class="card admin-section h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-bar-chart-line me-1"></i> Reports</span>
                    <a href="<?= APP_URL ?>/modules/admin/reports.php" class="btn btn-sm btn-light">Full Reports</a>
                </div>
                <div class="card-body">
                    <h6 class="text-maroon fw-semibold">Bookings by Status</h6>
                    <div class="table-responsive mb-3">
                        <table class="table table-sm table-striped align-middle">
                            <thead><tr><th>Status</th><th class="text-end">Count</th></tr></thead>
                            <tbody>
                                <?php if ($statusReport): ?>
                                    <?php foreach ($statusReport as $row): ?>
                                        <tr>
                                            <td><?= ucfirst(sanitize($row['status'])) ?></td>
                                            <td class="text-end"><?= (int) $row['total'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="2" class="text-center text-muted py-3">No report data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h6 class="text-maroon fw-semibold">Top Facilities</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle mb-0">
                            <thead><tr><th>Facility</th><th class="text-end">Bookings</th></tr></thead>
                            <tbody>
                                <?php if ($facilityReport): ?>
                                    <?php foreach ($facilityReport as $row): ?>
                                        <tr>
                                            <td><?= sanitize($row['name']) ?></td>
                                            <td class="text-end"><?= (int) $row['total'] ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="2" class="text-center text-muted py-3">No facility report data.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>

        <div class="col-lg-6">
            <section class="card admin-section h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clipboard-data me-1"></i> Audit Logs</span>
                    <a href="<?= APP_URL ?>/modules/admin/reports.php#audit" class="btn btn-sm btn-light">View Archive</a>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th>Actor</th>
                                    <th>Action</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($auditRows): ?>
                                    <?php foreach ($auditRows as $row): ?>
                                        <tr>
                                            <td><?= sanitize($row['actor_name'] ?? 'System') ?></td>
                                            <td><?= sanitize($row['action']) ?></td>
                                            <td><?= sanitize($row['timestamp']) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="3" class="text-center text-muted py-4">No audit logs found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </div>
    </div>
</main>

<?php renderPageEnd(); ?>



