<?php
/**
 * FSUU Library Booking System
 * Faculty — My Bookings
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireRole(ROLE_FACULTY, ROLE_ADVISER, ROLE_STAFF);

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_id'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
    } else {
        $cancelId = (int) $_POST['cancel_id'];
        $upd = $pdo->prepare('
            UPDATE bookings SET status = ?
            WHERE id = ?
              AND user_id = ?
              AND status IN (?, ?)
              AND (date > CURDATE() OR (date = CURDATE() AND end_time >= CURTIME()))
        ');
        $upd->execute([STATUS_CANCELLED, $cancelId, $userId, STATUS_PENDING, STATUS_APPROVED]);
        setFlash('success', 'Booking cancelled successfully.');
    }
    redirect(APP_URL . '/modules/faculty/my_bookings.php');
}

$stmt = $pdo->prepare('
    SELECT b.*, b.date AS booking_date, f.name AS facility_name
    FROM bookings b
    JOIN facilities f ON f.id = b.facility_id
    WHERE b.user_id = ?
      AND (b.date > CURDATE() OR (b.date = CURDATE() AND b.end_time >= CURTIME()))
    ORDER BY b.date ASC, b.start_time ASC
');
$stmt->execute([$userId]);
$bookings = $stmt->fetchAll();

$csrfToken = generateCsrfToken();
renderPageStart('My Bookings');
?>

<main class="container py-4">
    <?= getFlash() ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-maroon mb-0"><i class="bi bi-journals me-1"></i> My Bookings</h5>
        <a href="<?= APP_URL ?>/modules/faculty/dashboard.php#booking-form" class="btn btn-maroon btn-sm">
            <i class="bi bi-plus-lg me-1"></i> New Booking
        </a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Facility</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Purpose</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($bookings): ?>
                        <?php foreach ($bookings as $i => $b): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= sanitize($b['facility_name']) ?></td>
                            <td><?= formatDate($b['booking_date']) ?></td>
                            <td><?= formatTime($b['start_time']) ?> – <?= formatTime($b['end_time']) ?></td>
                            <td><?= sanitize($b['purpose'] ?? '—') ?></td>
                            <td><?= statusBadge($b['status']) ?></td>
                            <td>
                                <?php if (in_array($b['status'], [STATUS_PENDING, STATUS_APPROVED], true)): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Cancel this booking?')">
                                    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                    <input type="hidden" name="cancel_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x-lg"></i> Cancel
                                    </button>
                                </form>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">You have no upcoming bookings.</td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php renderPageEnd(); ?>



