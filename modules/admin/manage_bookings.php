<?php
/**
 * FSUU Library Booking System
 * Admin - Booking Approval System
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireRole(ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN);

$pdo = getDBConnection();

function isAllowedRequestFilePath(string $relativePath): bool {
    $normalized = str_replace('\\', '/', ltrim($relativePath, '/'));
    if (!str_starts_with($normalized, 'uploads/requests/')) {
        return false;
    }

    $ext = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
    return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function publicFileUrl(string $relativePath): string {
    if (!isAllowedRequestFilePath($relativePath)) {
        return '#';
    }

    return APP_URL . '/' . ltrim($relativePath, '/');
}

function isImageFile(string $filePath): bool {
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['booking_id'], $_POST['action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        redirect(APP_URL . '/modules/admin/manage_bookings.php');
    }

    $bookingId = (int) $_POST['booking_id'];
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $action = trim((string) ($_POST['action'] ?? ''));
    $rejectReason = trim((string) ($_POST['reject_reason'] ?? ''));

    if (!in_array($action, [STATUS_APPROVED, STATUS_REJECTED], true)) {
        setFlash('danger', 'Invalid action requested.');
        redirect(APP_URL . '/modules/admin/manage_bookings.php');
    }

    if ($action === STATUS_REJECTED && strlen($rejectReason) > 500) {
        setFlash('danger', 'Rejection reason must be 500 characters or fewer.');
        redirect(APP_URL . '/modules/admin/manage_bookings.php');
    }

    try {
        $pdo->beginTransaction();

        $bookingLookup = $pdo->prepare('
            SELECT b.id, b.user_id
            FROM bookings b
            JOIN users u ON u.id = b.user_id
            WHERE b.id = ?
              AND b.status = ?
              AND u.role = ?
            LIMIT 1
        ');
        $bookingLookup->execute([$bookingId, STATUS_PENDING, ROLE_STUDENT]);
        $booking = $bookingLookup->fetch();

        if (!$booking) {
            $pdo->rollBack();
            setFlash('warning', 'Pending student request not found or already processed.');
            redirect(APP_URL . '/modules/admin/manage_bookings.php');
        }

        $updateBooking = $pdo->prepare('UPDATE bookings SET status = ? WHERE id = ? AND status = ?');
        $updateBooking->execute([$action, $bookingId, STATUS_PENDING]);

        if ($requestId > 0) {
            $updateRequest = $pdo->prepare('UPDATE requests SET status = ? WHERE id = ?');
            $updateRequest->execute([$action, $requestId]);
        }

        $signatureFlag = $action === STATUS_APPROVED ? 'E-SIGNATURE: APPLIED (SIMULATED_STAMP)' : 'E-SIGNATURE: NOT_APPLIED';
        $reasonPart = $action === STATUS_REJECTED
            ? (' | REASON: ' . ($rejectReason !== '' ? $rejectReason : 'No reason provided'))
            : '';

        $auditAction = sprintf(
            'BOOKING_%s | Booking #%d | Student #%d | Admin #%d | %s%s',
            strtoupper($action),
            $bookingId,
            (int) $booking['user_id'],
            (int) $_SESSION['user_id'],
            $signatureFlag,
            $reasonPart
        );

        logAuditEvent((int) $_SESSION['user_id'], $auditAction);

        $notificationMessage = $action === STATUS_APPROVED
            ? 'Your booking request has been approved (with simulated e-signature).'
            : 'Your booking request has been rejected.' . ($rejectReason !== '' ? (' Reason: ' . $rejectReason) : '');

        $pdo->commit();

        createNotification((int) $booking['user_id'], $notificationMessage, true);

        if ($action === STATUS_APPROVED) {
            setFlash('success', 'Booking approved and simulated e-signature applied.');
        } else {
            setFlash('success', 'Booking rejected successfully.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Admin booking approval failed: ' . $e->getMessage());
        setFlash('danger', 'Unable to process booking action right now.');
    }

    redirect(APP_URL . '/modules/admin/manage_bookings.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['override_booking_id'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request.');
        redirect(APP_URL . '/modules/admin/manage_bookings.php');
    }

    $bookingId = (int) ($_POST['override_booking_id'] ?? 0);
    $newDate = trim((string) ($_POST['new_date'] ?? ''));
    $newStartTime = trim((string) ($_POST['new_start_time'] ?? ''));
    $newEndTime = trim((string) ($_POST['new_end_time'] ?? ''));
    $newStatus = trim((string) ($_POST['new_status'] ?? ''));
    $overrideReason = trim((string) ($_POST['override_reason'] ?? ''));
    $allowedStatuses = [STATUS_PENDING, STATUS_APPROVED, STATUS_REJECTED, STATUS_CANCELLED, STATUS_COMPLETED];

    if ($bookingId <= 0) {
        setFlash('danger', 'Invalid booking selected.');
        redirect(APP_URL . '/modules/admin/manage_bookings.php');
    }

    if (!in_array($newStatus, $allowedStatuses, true)) {
        setFlash('danger', 'Invalid override status.');
        redirect(APP_URL . '/modules/admin/manage_bookings.php');
    }

    if ($newDate === '' || $newStartTime === '' || $newEndTime === '') {
        setFlash('danger', 'Date and time fields are required for override updates.');
        redirect(APP_URL . '/modules/admin/manage_bookings.php');
    }

    if ($newEndTime <= $newStartTime) {
        setFlash('danger', 'Override end time must be after start time.');
        redirect(APP_URL . '/modules/admin/manage_bookings.php');
    }

    try {
        $bookingStmt = $pdo->prepare('SELECT id, user_id, facility_id, status FROM bookings WHERE id = ? LIMIT 1');
        $bookingStmt->execute([$bookingId]);
        $booking = $bookingStmt->fetch();

        if (!$booking) {
            setFlash('warning', 'Booking not found.');
            redirect(APP_URL . '/modules/admin/manage_bookings.php');
        }

        $conflictStmt = $pdo->prepare('SELECT id FROM bookings WHERE facility_id = ? AND id <> ? AND date = ? AND status NOT IN (?, ?) AND start_time < ? AND end_time > ? LIMIT 1');
        $conflictStmt->execute([
            (int) $booking['facility_id'],
            $bookingId,
            $newDate,
            STATUS_REJECTED,
            STATUS_CANCELLED,
            $newEndTime,
            $newStartTime,
        ]);

        if ($conflictStmt->fetch()) {
            setFlash('danger', 'Cannot apply override because the selected slot conflicts with another booking.');
            redirect(APP_URL . '/modules/admin/manage_bookings.php');
        }

        $updateStmt = $pdo->prepare('UPDATE bookings SET date = ?, start_time = ?, end_time = ?, status = ? WHERE id = ?');
        $updateStmt->execute([$newDate, $newStartTime, $newEndTime, $newStatus, $bookingId]);

        $notifyMessage = 'Your booking #' . $bookingId . ' was updated by library staff/admin. New schedule: '
            . formatDate($newDate) . ' ' . formatTime($newStartTime) . ' - ' . formatTime($newEndTime)
            . '. Status: ' . ucfirst($newStatus) . '.'
            . ($overrideReason !== '' ? ' Reason: ' . $overrideReason : '');
        createNotification((int) $booking['user_id'], $notifyMessage, true);

        $auditMessage = sprintf(
            'BOOKING_OVERRIDE | Booking #%d | Admin #%d | Status: %s | Date: %s | Time: %s-%s%s',
            $bookingId,
            (int) $_SESSION['user_id'],
            strtoupper($newStatus),
            $newDate,
            $newStartTime,
            $newEndTime,
            $overrideReason !== '' ? (' | Reason: ' . $overrideReason) : ''
        );
        logAuditEvent((int) $_SESSION['user_id'], $auditMessage);

        setFlash('success', 'Booking override applied successfully.');
    } catch (Throwable $e) {
        error_log('Admin booking override failed: ' . $e->getMessage());
        setFlash('danger', 'Unable to apply booking override right now.');
    }

    redirect(APP_URL . '/modules/admin/manage_bookings.php');
}

$pendingRequestsStmt = $pdo->prepare('
    SELECT
        b.id AS booking_id,
        b.user_id,
        b.facility_id,
        b.date,
        b.start_time,
        b.end_time,
        b.purpose,
        b.attendees,
        b.status,
        u.name AS student_name,
        u.email AS student_email,
        f.name AS facility_name,
        r.id AS request_id,
        r.file_path,
        r.status AS request_status
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN facilities f ON f.id = b.facility_id
    LEFT JOIN requests r ON r.id = (
        SELECT r2.id
        FROM requests r2
        WHERE r2.user_id = b.user_id
          AND r2.facility_id = b.facility_id
          AND r2.status = "pending"
        ORDER BY r2.id DESC
        LIMIT 1
    )
    WHERE b.status = "pending"
      AND u.role = "student"
    ORDER BY b.date ASC, b.start_time ASC
');
$pendingRequestsStmt->execute();
$pendingRequests = $pendingRequestsStmt->fetchAll();

$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$allowedStatusFilters = ['all', STATUS_PENDING, STATUS_APPROVED, STATUS_REJECTED, STATUS_CANCELLED, STATUS_COMPLETED];
if (!in_array($statusFilter, $allowedStatusFilters, true)) {
    $statusFilter = 'all';
}

$allBookingsSql = '
    SELECT
        b.id,
        b.user_id,
        b.facility_id,
        b.date,
        b.start_time,
        b.end_time,
        b.status,
        b.purpose,
        b.attendees,
        u.name AS requester_name,
        u.email AS requester_email,
        u.role AS requester_role,
        f.name AS facility_name
    FROM bookings b
    JOIN users u ON u.id = b.user_id
    JOIN facilities f ON f.id = b.facility_id
    WHERE 1 = 1
';
$allBookingsParams = [];
if ($statusFilter !== 'all') {
    $allBookingsSql .= ' AND b.status = ?';
    $allBookingsParams[] = $statusFilter;
}
$allBookingsSql .= ' ORDER BY b.date DESC, b.start_time DESC, b.id DESC LIMIT 200';

$allBookingsStmt = $pdo->prepare($allBookingsSql);
$allBookingsStmt->execute($allBookingsParams);
$allBookings = $allBookingsStmt->fetchAll();

$csrfToken = generateCsrfToken();
renderPageStart('Booking Approval');
?>

<main class="container py-4">
    <?= getFlash() ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-maroon mb-0"><i class="bi bi-clipboard-check me-1"></i> Pending Student Requests</h5>
        <a href="<?= APP_URL ?>/modules/admin/dashboard.php" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Student</th>
                            <th>Facility</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Details</th>
                            <th>Uploaded File</th>
                            <th style="min-width:320px">Admin Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($pendingRequests): ?>
                        <?php foreach ($pendingRequests as $i => $row): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td>
                                    <strong><?= sanitize($row['student_name']) ?></strong>
                                    <div class="small text-muted"><?= sanitize($row['student_email']) ?></div>
                                </td>
                                <td><?= sanitize($row['facility_name']) ?></td>
                                <td><?= formatDate($row['date']) ?></td>
                                <td><?= formatTime($row['start_time']) ?> - <?= formatTime($row['end_time']) ?></td>
                                <td>
                                    <div class="small text-muted mb-1">Attendees: <?= (int) $row['attendees'] ?></div>
                                    <div class="small text-muted"><?= sanitize($row['purpose'] ?: 'No purpose provided') ?></div>
                                </td>
                                <td>
                                    <?php if (!empty($row['file_path']) && isAllowedRequestFilePath((string) $row['file_path'])): ?>
                                        <?php $safeFileUrl = publicFileUrl((string) $row['file_path']); ?>
                                        <?php if (isImageFile((string) $row['file_path'])): ?>
                                            <a href="<?= $safeFileUrl ?>" target="_blank" rel="noopener" class="d-inline-block mb-2">
                                                <img src="<?= $safeFileUrl ?>" alt="Uploaded request" style="width:84px;height:84px;object-fit:cover;border-radius:8px;border:1px solid #ddd;">
                                            </a>
                                        <?php endif; ?>
                                        <a href="<?= $safeFileUrl ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary d-block mb-1">Preview File</a>
                                        <a href="<?= $safeFileUrl ?>" download class="btn btn-sm btn-outline-secondary d-block">Download File</a>
                                    <?php else: ?>
                                        <span class="text-muted small">No uploaded file</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-2">
                                        <form method="POST" class="d-flex gap-2 flex-wrap">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="booking_id" value="<?= (int) $row['booking_id'] ?>">
                                            <input type="hidden" name="request_id" value="<?= (int) ($row['request_id'] ?? 0) ?>">
                                            <input type="hidden" name="action" value="<?= STATUS_APPROVED ?>">
                                            <button class="btn btn-sm btn-success" type="submit">
                                                <i class="bi bi-check2-circle me-1"></i> Approve + E-Sign
                                            </button>
                                        </form>

                                        <form method="POST" class="d-flex gap-2 flex-wrap">
                                            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                            <input type="hidden" name="booking_id" value="<?= (int) $row['booking_id'] ?>">
                                            <input type="hidden" name="request_id" value="<?= (int) ($row['request_id'] ?? 0) ?>">
                                            <input type="hidden" name="action" value="<?= STATUS_REJECTED ?>">
                                            <input type="text" name="reject_reason" class="form-control form-control-sm" placeholder="Optional rejection reason" maxlength="500" style="max-width:220px;">
                                            <button class="btn btn-sm btn-danger" type="submit">
                                                <i class="bi bi-x-circle me-1"></i> Reject
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No pending student requests found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mt-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span><i class="bi bi-kanban me-1"></i> Booking Management (All Statuses)</span>
            <form method="GET" action="" class="d-flex align-items-center gap-2">
                <label for="status" class="small text-muted">Status:</label>
                <select id="status" name="status" class="form-select form-select-sm" style="min-width: 180px;">
                    <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>All Statuses</option>
                    <option value="<?= STATUS_PENDING ?>" <?= $statusFilter === STATUS_PENDING ? 'selected' : '' ?>>Pending</option>
                    <option value="<?= STATUS_APPROVED ?>" <?= $statusFilter === STATUS_APPROVED ? 'selected' : '' ?>>Approved</option>
                    <option value="<?= STATUS_REJECTED ?>" <?= $statusFilter === STATUS_REJECTED ? 'selected' : '' ?>>Rejected</option>
                    <option value="<?= STATUS_CANCELLED ?>" <?= $statusFilter === STATUS_CANCELLED ? 'selected' : '' ?>>Cancelled</option>
                    <option value="<?= STATUS_COMPLETED ?>" <?= $statusFilter === STATUS_COMPLETED ? 'selected' : '' ?>>Completed</option>
                </select>
                <button type="submit" class="btn btn-sm btn-outline-secondary">Apply</button>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Requester</th>
                            <th>Facility</th>
                            <th>Schedule</th>
                            <th>Status</th>
                            <th>Details</th>
                            <th style="min-width: 420px;">Override</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($allBookings): ?>
                        <?php foreach ($allBookings as $idx => $booking): ?>
                            <tr>
                                <td><?= $idx + 1 ?></td>
                                <td>
                                    <strong><?= sanitize($booking['requester_name']) ?></strong>
                                    <div class="small text-muted"><?= sanitize($booking['requester_email']) ?></div>
                                    <div class="small"><span class="badge bg-dark"><?= strtoupper(sanitize($booking['requester_role'])) ?></span></div>
                                </td>
                                <td><?= sanitize($booking['facility_name']) ?></td>
                                <td>
                                    <div><?= formatDate($booking['date']) ?></div>
                                    <div class="small text-muted"><?= formatTime($booking['start_time']) ?> - <?= formatTime($booking['end_time']) ?></div>
                                </td>
                                <td><?= statusBadge($booking['status']) ?></td>
                                <td>
                                    <div class="small text-muted">Attendees: <?= (int) $booking['attendees'] ?></div>
                                    <div class="small text-muted"><?= sanitize((string) ($booking['purpose'] ?: 'No purpose provided')) ?></div>
                                </td>
                                <td>
                                    <form method="POST" class="row g-2">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="override_booking_id" value="<?= (int) $booking['id'] ?>">
                                        <div class="col-sm-4">
                                            <input type="date" class="form-control form-control-sm" name="new_date" value="<?= sanitize($booking['date']) ?>" required>
                                        </div>
                                        <div class="col-sm-4">
                                            <input type="time" class="form-control form-control-sm" name="new_start_time" value="<?= sanitize($booking['start_time']) ?>" required>
                                        </div>
                                        <div class="col-sm-4">
                                            <input type="time" class="form-control form-control-sm" name="new_end_time" value="<?= sanitize($booking['end_time']) ?>" required>
                                        </div>
                                        <div class="col-sm-4">
                                            <select name="new_status" class="form-select form-select-sm" required>
                                                <option value="<?= STATUS_PENDING ?>" <?= $booking['status'] === STATUS_PENDING ? 'selected' : '' ?>>Pending</option>
                                                <option value="<?= STATUS_APPROVED ?>" <?= $booking['status'] === STATUS_APPROVED ? 'selected' : '' ?>>Approved</option>
                                                <option value="<?= STATUS_REJECTED ?>" <?= $booking['status'] === STATUS_REJECTED ? 'selected' : '' ?>>Rejected</option>
                                                <option value="<?= STATUS_CANCELLED ?>" <?= $booking['status'] === STATUS_CANCELLED ? 'selected' : '' ?>>Cancelled</option>
                                                <option value="<?= STATUS_COMPLETED ?>" <?= $booking['status'] === STATUS_COMPLETED ? 'selected' : '' ?>>Completed</option>
                                            </select>
                                        </div>
                                        <div class="col-sm-8">
                                            <input type="text" class="form-control form-control-sm" name="override_reason" maxlength="500" placeholder="Optional override note">
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-shield-check me-1"></i> Apply Override
                                            </button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No bookings found for this filter.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php renderPageEnd(); ?>



