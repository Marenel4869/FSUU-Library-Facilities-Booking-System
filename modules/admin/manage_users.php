<?php
/**
 * FSUU Library Booking System
 * Admin — Manage Users
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireRole(ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN);

$pdo = getDBConnection();

function usersColumnExists(PDO $pdo, string $columnName): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->execute([DB_NAME, 'users', $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}

$hasIsActiveColumn = usersColumnExists($pdo, 'is_active');
$hasCreatedAtColumn = usersColumnExists($pdo, 'created_at');
$protectedRoles = [ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_id'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
    } else {
        $userId = (int) $_POST['user_id'];
        if ($hasIsActiveColumn) {
            $upd = $pdo->prepare('UPDATE users SET is_active = IF(is_active = 1, 0, 1) WHERE id = ? AND role NOT IN (?, ?, ?)');
            $upd->execute([$userId, $protectedRoles[0], $protectedRoles[1], $protectedRoles[2]]);
        } else {
            $upd = $pdo->prepare('UPDATE users SET status = IF(status = "active", "inactive", "active") WHERE id = ? AND role NOT IN (?, ?, ?)');
            $upd->execute([$userId, $protectedRoles[0], $protectedRoles[1], $protectedRoles[2]]);
        }

        if ($upd->rowCount() > 0) {
            logAuditEvent((int) $_SESSION['user_id'], 'USER_STATUS_TOGGLED | Target User #' . $userId . ' | Admin #' . (int) $_SESSION['user_id']);
        }
        setFlash('success', 'User status updated.');
    }
    redirect(APP_URL . '/modules/admin/manage_users.php');
}

$orderBy = $hasCreatedAtColumn ? 'created_at DESC' : 'id DESC';
$users = $pdo->query(
    'SELECT id, name, email, role, status, ' .
    ($hasIsActiveColumn ? 'is_active' : 'IF(status = "active", 1, 0) AS is_active') .
    ' FROM users ORDER BY ' . $orderBy
)->fetchAll();

$historyUserId = (int) ($_GET['history_user_id'] ?? 0);
$historyUser = null;
$historyBookings = [];
if ($historyUserId > 0) {
    $historyUserStmt = $pdo->prepare('SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1');
    $historyUserStmt->execute([$historyUserId]);
    $historyUser = $historyUserStmt->fetch();

    if ($historyUser) {
        $historyStmt = $pdo->prepare('SELECT b.id, b.date, b.start_time, b.end_time, b.status, b.purpose, b.attendees, f.name AS facility_name FROM bookings b JOIN facilities f ON f.id = b.facility_id WHERE b.user_id = ? ORDER BY b.date DESC, b.start_time DESC LIMIT 20');
        $historyStmt->execute([$historyUserId]);
        $historyBookings = $historyStmt->fetchAll();
    }
}

$csrfToken = generateCsrfToken();
renderPageStart('Manage Users');
?>

<main class="container py-4">
    <?= getFlash() ?>

    <h5 class="fw-bold text-maroon mb-3"><i class="bi bi-people me-1"></i> Manage Users</h5>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($users): ?>
                            <?php foreach ($users as $i => $u): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= sanitize((string) ($u['name'] ?? 'Unknown')) ?></td>
                                    <td><?= sanitize($u['email']) ?></td>
                                    <td><span class="badge bg-dark"><?= strtoupper(sanitize($u['role'])) ?></span></td>
                                    <td>
                                        <?php if ((int) $u['is_active'] === 1): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!in_array((string) $u['role'], $protectedRoles, true)): ?>
                                            <form method="POST" class="d-inline me-1">
                                                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button class="btn btn-sm btn-outline-primary" type="submit">
                                                    Toggle Status
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted small me-1">Protected</span>
                                        <?php endif; ?>
                                        <a href="<?= APP_URL ?>/modules/admin/manage_users.php?history_user_id=<?= (int) $u['id'] ?>" class="btn btn-sm btn-outline-secondary">View History</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No users found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($historyUser): ?>
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history me-1"></i> Booking History — <?= sanitize((string) $historyUser['name']) ?></span>
            <a href="<?= APP_URL ?>/modules/admin/manage_users.php" class="btn btn-sm btn-outline-secondary">Close</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Facility</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Attendees</th>
                            <th>Status</th>
                            <th>Purpose</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($historyBookings): ?>
                        <?php foreach ($historyBookings as $i => $booking): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= sanitize($booking['facility_name']) ?></td>
                                <td><?= formatDate($booking['date']) ?></td>
                                <td><?= formatTime($booking['start_time']) ?> - <?= formatTime($booking['end_time']) ?></td>
                                <td><?= (int) $booking['attendees'] ?></td>
                                <td><?= statusBadge($booking['status']) ?></td>
                                <td><?= sanitize((string) ($booking['purpose'] ?: 'No purpose provided')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No booking history found for this user.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php renderPageEnd(); ?>



