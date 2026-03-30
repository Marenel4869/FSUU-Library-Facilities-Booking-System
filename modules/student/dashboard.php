<?php
/**
 * FSUU Library Booking System
 * Student Dashboard
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireRole(ROLE_STUDENT);

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];

dispatchUpcomingBookingReminders((int) $userId);

$counts = [];
$facilities = [];
$requests = [];
$bookingHistory = [];
$notifications = [];
$selectedNotification = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid notification request.');
        redirect(APP_URL . '/modules/student/dashboard.php#notifications');
    }

    if (isset($_POST['mark_all_read'])) {
        $updated = markAllNotificationsAsRead($userId);
        setFlash('success', $updated > 0 ? 'All notifications marked as read.' : 'No unread notifications to update.');
        redirect(APP_URL . '/modules/student/dashboard.php#notifications');
    }

    if (isset($_POST['open_notification_id'])) {
        $noteId = (int) $_POST['open_notification_id'];
        markNotificationAsRead($userId, $noteId);
        redirect(APP_URL . '/modules/student/dashboard.php?show_note=' . $noteId . '#notifications');
    }

    if (isset($_POST['mark_read_id'])) {
        $noteId = (int) $_POST['mark_read_id'];
        if (markNotificationAsRead($userId, $noteId)) {
            setFlash('success', 'Notification marked as read.');
        } else {
            setFlash('warning', 'Notification not found or already marked as read.');
        }
        redirect(APP_URL . '/modules/student/dashboard.php#notifications');
    }
}

try {
    $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM bookings WHERE user_id = ? GROUP BY status');
    $stmt->execute([$userId]);
    $counts = array_column($stmt->fetchAll(), 'total', 'status');

    $facilityStmt = $pdo->query('
        SELECT id, name, type, capacity_min, capacity_max, availability
        FROM facilities
        ORDER BY availability DESC, name ASC
        LIMIT 6
    ');
    $facilities = $facilityStmt->fetchAll();

    $requestStmt = $pdo->prepare('
        SELECT r.id, r.status, r.file_path, f.name AS facility_name
        FROM requests r
        JOIN facilities f ON f.id = r.facility_id
        WHERE r.user_id = ?
        ORDER BY r.id DESC
        LIMIT 6
    ');
    $requestStmt->execute([$userId]);
    $requests = $requestStmt->fetchAll();

    $historyStmt = $pdo->prepare('
        SELECT b.id, b.date, b.start_time, b.end_time, b.status, b.purpose, b.attendees, f.name AS facility_name
        FROM bookings b
        JOIN facilities f ON f.id = b.facility_id
        WHERE b.user_id = ?
        ORDER BY b.date DESC, b.start_time DESC
        LIMIT 8
    ');
    $historyStmt->execute([$userId]);
    $bookingHistory = $historyStmt->fetchAll();

    $notifications = getRecentNotifications($userId, 6);

    if (isset($_GET['show_note']) && ctype_digit((string) $_GET['show_note'])) {
        $selectedNotification = getNotificationById($userId, (int) $_GET['show_note']);
    }
} catch (Throwable $e) {
    error_log('Student dashboard load failed: ' . $e->getMessage());
}

renderPageStart('My Dashboard');
?>

<main class="student-shell container-fluid px-0">
    <?= getFlash() ?>

    <div class="student-dashboard">
        <aside class="student-sidebar">
            <div class="student-sidebar__brand">
                <span class="student-sidebar__eyebrow">Student Portal</span>
                <h1>Library Booking</h1>
                <p>Access facilities, track requests, and review your reservation history in one place.</p>
            </div>

            <nav class="student-sidebar__nav" aria-label="Student dashboard sections">
                <a href="#overview" class="student-nav-link is-active">
                    <i class="bi bi-grid-1x2-fill"></i>
                    <span>Overview</span>
                </a>
                <a href="#facilities" class="student-nav-link">
                    <i class="bi bi-building"></i>
                    <span>View Facilities</span>
                </a>
                <a href="#requests" class="student-nav-link">
                    <i class="bi bi-envelope-paper"></i>
                    <span>My Requests</span>
                </a>
                <a href="#history" class="student-nav-link">
                    <i class="bi bi-clock-history"></i>
                    <span>Booking History</span>
                </a>
            </nav>

            <div class="student-sidebar__cta">
                <a href="<?= APP_URL ?>/modules/student/booking.php" class="btn btn-maroon w-100">
                    <i class="bi bi-plus-circle me-1"></i> New Booking
                </a>
            </div>
        </aside>

        <section class="student-content">
            <section id="overview" class="student-hero card">
                <div class="student-hero__copy">
                    <span class="student-hero__label">Welcome back</span>
                    <h2><?= sanitize($_SESSION['user_name']) ?></h2>
                    <p>Manage your facility access from a single dashboard built for quick decisions and clear status tracking.</p>
                </div>
                <div class="student-hero__stats">
                    <?php
                    $stats = [
                        ['label' => 'Pending', 'key' => STATUS_PENDING, 'icon' => 'clock-history'],
                        ['label' => 'Approved', 'key' => STATUS_APPROVED, 'icon' => 'check2-circle'],
                        ['label' => 'Completed', 'key' => STATUS_COMPLETED, 'icon' => 'calendar2-check'],
                        ['label' => 'Rejected', 'key' => STATUS_REJECTED, 'icon' => 'slash-circle'],
                    ];
                    foreach ($stats as $s): ?>
                    <article class="student-metric">
                        <div>
                            <span><?= $s['label'] ?></span>
                            <strong><?= (int) ($counts[$s['key']] ?? 0) ?></strong>
                        </div>
                        <i class="bi bi-<?= $s['icon'] ?>"></i>
                    </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section id="facilities" class="student-panel card">
                <div class="student-panel__header">
                    <div>
                        <span class="student-panel__eyebrow">View Facilities</span>
                        <h3>Available spaces for study and collaboration</h3>
                    </div>
                    <a href="<?= APP_URL ?>/modules/student/booking.php" class="btn btn-outline-secondary btn-sm">Book Now</a>
                </div>

                <div class="student-facility-grid">
                    <?php if ($facilities): ?>
                        <?php foreach ($facilities as $facility): ?>
                        <article class="student-facility-card">
                            <span class="student-chip <?= (int) $facility['availability'] === 1 ? 'is-open' : 'is-closed' ?>">
                                <?= (int) $facility['availability'] === 1 ? 'Open for booking' : 'Currently unavailable' ?>
                            </span>
                            <h4><?= sanitize($facility['name']) ?></h4>
                            <p><?= ucwords(str_replace('_', ' ', sanitize($facility['type']))) ?></p>
                            <dl>
                                <div>
                                    <dt>Capacity</dt>
                                    <dd><?= (int) $facility['capacity_min'] ?> - <?= (int) $facility['capacity_max'] ?> students</dd>
                                </div>
                                <div>
                                    <dt>Access</dt>
                                    <dd><?= (int) $facility['availability'] === 1 ? 'Ready to reserve' : 'Request unavailable' ?></dd>
                                </div>
                            </dl>
                        </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="student-empty-state">
                            <i class="bi bi-building"></i>
                            <p>No facilities are available to display right now.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section id="requests" class="student-panel card">
                <div class="student-panel__header">
                    <div>
                        <span class="student-panel__eyebrow">My Requests</span>
                        <h3>Track submitted documents and approvals</h3>
                    </div>
                </div>

                <div class="student-request-list">
                    <?php if ($requests): ?>
                        <?php foreach ($requests as $request): ?>
                        <article class="student-request-item">
                            <div>
                                <h4><?= sanitize($request['facility_name']) ?></h4>
                                <p><?= sanitize(basename($request['file_path'])) ?></p>
                            </div>
                            <span class="student-chip status-<?= sanitize($request['status']) ?>"><?= ucfirst(sanitize($request['status'])) ?></span>
                        </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="student-empty-state">
                            <i class="bi bi-inboxes"></i>
                            <p>You have not submitted any requests yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section id="history" class="student-panel card">
                <div class="student-panel__header">
                    <div>
                        <span class="student-panel__eyebrow">Booking History</span>
                        <h3>Recent reservations and attendance details</h3>
                    </div>
                    <a href="<?= APP_URL ?>/modules/student/my_bookings.php" class="btn btn-outline-secondary btn-sm">View All</a>
                </div>

                <div class="table-responsive student-history-table">
                    <table class="table mb-0 align-middle">
                        <thead>
                            <tr>
                                <th>Facility</th>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Attendees</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ($bookingHistory): ?>
                            <?php foreach ($bookingHistory as $booking): ?>
                            <tr>
                                <td>
                                    <strong><?= sanitize($booking['facility_name']) ?></strong>
                                    <div class="small text-muted"><?= sanitize($booking['purpose'] ?: 'No purpose provided') ?></div>
                                </td>
                                <td><?= formatDate($booking['date']) ?></td>
                                <td><?= formatTime($booking['start_time']) ?> - <?= formatTime($booking['end_time']) ?></td>
                                <td><?= (int) $booking['attendees'] ?></td>
                                <td><?= statusBadge($booking['status']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted py-4">No booking history available.</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="notifications" class="student-panel card">
                <div class="student-panel__header">
                    <div>
                        <span class="student-panel__eyebrow">Notifications</span>
                        <h3>Latest booking updates</h3>
                    </div>
                    <form method="POST" action="" class="m-0">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="mark_all_read" value="1">
                        <button type="submit" class="btn btn-outline-secondary btn-sm">Mark all as read</button>
                    </form>
                </div>

                <div class="student-request-list">
                    <?php if ($notifications): ?>
                        <?php foreach ($notifications as $note): ?>
                        <article class="student-request-item">
                            <div>
                                <h4><?= sanitize($note['message']) ?></h4>
                                <p><?= sanitize($note['created_at']) ?></p>
                            </div>
                            <div class="d-flex flex-column align-items-end gap-2">
                                <span class="student-chip status-<?= sanitize($note['status']) ?>"><?= ucfirst(sanitize($note['status'])) ?></span>
                                <form method="POST" action="" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="open_notification_id" value="<?= (int) $note['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">View details</button>
                                </form>
                                <?php if ($note['status'] === 'unread'): ?>
                                    <form method="POST" action="" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="mark_read_id" value="<?= (int) $note['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Mark as read</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="student-empty-state">
                            <i class="bi bi-bell"></i>
                            <p>No notifications yet.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <?php if ($selectedNotification): ?>
            <div class="modal fade" id="notificationDetailModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Notification Detail</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2"><?= sanitize($selectedNotification['message']) ?></p>
                            <small class="text-muted d-block">Date: <?= sanitize($selectedNotification['created_at']) ?></small>
                            <small class="text-muted d-block">Status: <?= ucfirst(sanitize($selectedNotification['status'])) ?></small>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var modalElement = document.getElementById('notificationDetailModal');
                    if (modalElement && window.bootstrap) {
                        var modal = new bootstrap.Modal(modalElement);
                        modal.show();
                    }
                });
            </script>
            <?php endif; ?>
        </section>
    </div>
</main>

<?php renderPageEnd(); ?>



