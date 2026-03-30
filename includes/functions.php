<?php
/**
 * FSUU Library Booking System
 * Helper & Utility Functions
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/constants.php';
require_once dirname(__DIR__) . '/database/db.php';

/**
 * Return all supported application roles.
 *
 * @return array<int, string>
 */
function supportedRoles(): array {
    return [
        ROLE_ADMIN,
        ROLE_FACULTY,
        ROLE_STUDENT,
        ROLE_ADVISER,
        ROLE_STAFF,
        ROLE_LIBRARY_STAFF,
        ROLE_SUPER_ADMIN,
    ];
}

function dashboardForRole(string $role): string {
    if ($role === ROLE_STUDENT) {
        return APP_URL . '/student_dashboard.php';
    }

    if (in_array($role, [ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN], true)) {
        return APP_URL . '/admin_dashboard.php';
    }

    if (in_array($role, [ROLE_FACULTY, ROLE_ADVISER, ROLE_STAFF], true)) {
        return APP_URL . '/faculty_dashboard.php';
    }

    return APP_URL . '/admin_dashboard.php';
}

/**
 * Sanitize user input.
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to a URL.
 */
function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

/**
 * Flash a one-time session message.
 */
function setFlash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

/**
 * Render and clear the flash message.
 */
function getFlash(): string {
    if (!isset($_SESSION['flash'])) {
        return '';
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $type = sanitize($flash['type']);
    $msg  = sanitize($flash['message']);
    return "<div class=\"alert alert-{$type} alert-dismissible fade show\" role=\"alert\">
                {$msg}
                <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button>
            </div>";
}

/**
 * Check if a user is logged in; redirect to login if not.
 */
function requireLogin(): void {
    if (!isset($_SESSION['user_id'], $_SESSION['user_role'])) {
        setFlash('warning', 'Please log in to continue.');
        redirect(APP_URL . '/modules/auth/login.php');
    }

    $userId = (int) $_SESSION['user_id'];
    $role = (string) ($_SESSION['user_role'] ?? '');
    $allowedRoles = supportedRoles();

    if ($userId <= 0 || !in_array($role, $allowedRoles, true)) {
        $_SESSION = [];
        session_destroy();
        setFlash('warning', 'Your session is invalid. Please log in again.');
        redirect(APP_URL . '/modules/auth/login.php');
    }
}

/**
 * Check if the logged-in user has a required role.
 */
function requireRole(string ...$roles): void {
    requireLogin();

    $allowedRoles = supportedRoles();
    foreach ($roles as $role) {
        if (!in_array($role, $allowedRoles, true)) {
            error_log('Invalid role passed to requireRole: ' . $role);
            setFlash('danger', 'Configuration error.');
            redirect(APP_URL . '/index.php');
        }
    }

    if (!in_array((string) ($_SESSION['user_role'] ?? ''), $roles, true)) {
        setFlash('danger', 'You do not have permission to access that page.');
        redirect(APP_URL . '/index.php');
    }
}

/**
 * Validate uploaded supporting document as PDF/image with strict MIME + extension checks.
 *
 * @return array{ok: bool, errors: array<int, string>, extension: string, mime: string}
 */
function validateSupportingUpload(array $file, int $maxBytes = 5242880): array {
    $result = [
        'ok' => false,
        'errors' => [],
        'extension' => '',
        'mime' => '',
    ];

    $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($errorCode !== UPLOAD_ERR_OK) {
        if ($errorCode === UPLOAD_ERR_NO_FILE) {
            $result['errors'][] = 'Supporting document is required.';
        } else {
            $result['errors'][] = 'Failed to upload the supporting document.';
        }
        return $result;
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        $result['errors'][] = 'Invalid uploaded file source.';
        return $result;
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > $maxBytes) {
        $result['errors'][] = 'Supporting document must not exceed 5 MB.';
    }

    $originalName = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $result['extension'] = $extension;

    $allowedByExtension = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
    ];

    if (!isset($allowedByExtension[$extension])) {
        $result['errors'][] = 'Supporting document must be a PDF or image file.';
        return $result;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = $finfo ? (string) finfo_file($finfo, $tmpName) : '';
    if ($finfo) {
        finfo_close($finfo);
    }
    $result['mime'] = $mimeType;

    if ($mimeType === '' || !in_array($mimeType, $allowedByExtension[$extension], true)) {
        $result['errors'][] = 'Uploaded file type is not allowed.';
    }

    $result['ok'] = empty($result['errors']);
    return $result;
}

/**
 * Format a date string using the application format.
 */
function formatDate(string $date): string {
    return date(DATE_FORMAT, strtotime($date));
}

/**
 * Format a time string using the application format.
 */
function formatTime(string $time): string {
    return date(TIME_FORMAT, strtotime($time));
}

/**
 * Return a Bootstrap badge class for a booking status.
 */
function statusBadge(string $status): string {
    $map = [
        STATUS_PENDING   => 'warning text-dark',
        STATUS_APPROVED  => 'success',
        STATUS_REJECTED  => 'danger',
        STATUS_CANCELLED => 'secondary',
        STATUS_COMPLETED => 'primary',
    ];
    $class = $map[$status] ?? 'light';
    return "<span class=\"badge bg-{$class}\">" . ucfirst($status) . '</span>';
}

/**
 * Generate a CSRF token and store it in the session.
 */
function generateCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate the submitted CSRF token.
 */
function validateCsrfToken(string $token): bool {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Simulate notification email sending by writing to a local log file.
 */
function simulateNotificationEmail(string $recipientEmail, string $message): bool {
    $logPath = ROOT_PATH . '/uploads/notification_emails.log';
    $line = sprintf(
        "[%s] To: %s | Message: %s%s",
        date('Y-m-d H:i:s'),
        $recipientEmail,
        preg_replace('/\s+/', ' ', trim($message)),
        PHP_EOL
    );

    return file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX) !== false;
}

/**
 * Insert an in-app notification and optionally simulate email delivery.
 */
function createNotification(int $userId, string $message, bool $simulateEmail = false): bool {
    try {
        $pdo = getDBConnection();
        $ins = $pdo->prepare('INSERT INTO notifications (user_id, message, status, created_at) VALUES (?, ?, ?, NOW())');
        $ins->execute([$userId, $message, 'unread']);

        if ($simulateEmail) {
            $emailStmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
            $emailStmt->execute([$userId]);
            $email = (string) ($emailStmt->fetchColumn() ?: '');

            if ($email !== '' && !simulateNotificationEmail($email, $message)) {
                error_log('Notification email simulation failed for user_id=' . $userId);
            }
        }

        return true;
    } catch (Throwable $e) {
        error_log('createNotification failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Fetch recent notifications for a user.
 */
function getRecentNotifications(int $userId, int $limit = 5): array {
    $safeLimit = max(1, min($limit, 20));
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT id, message, status, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT {$safeLimit}");
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Count unread notifications for a user.
 */
function getUnreadNotificationCount(int $userId): int {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND status = ?');
    $stmt->execute([$userId, 'unread']);
    return (int) $stmt->fetchColumn();
}

/**
 * Mark one notification as read if it belongs to the user.
 */
function markNotificationAsRead(int $userId, int $notificationId): bool {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('UPDATE notifications SET status = ? WHERE id = ? AND user_id = ?');
    $stmt->execute(['read', $notificationId, $userId]);
    return $stmt->rowCount() > 0;
}

/**
 * Mark all unread notifications as read for a user.
 */
function markAllNotificationsAsRead(int $userId): int {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('UPDATE notifications SET status = ? WHERE user_id = ? AND status = ?');
    $stmt->execute(['read', $userId, 'unread']);
    return $stmt->rowCount();
}

/**
 * Fetch a single notification for a user.
 */
function getNotificationById(int $userId, int $notificationId): ?array {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare('SELECT id, message, status, created_at FROM notifications WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([$notificationId, $userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Persist an admin audit event.
 */
function logAuditEvent(int $actorUserId, string $action): bool {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare('INSERT INTO audit_logs (user_id, action) VALUES (?, ?)');
        $stmt->execute([$actorUserId, $action]);
        return true;
    } catch (Throwable $e) {
        error_log('logAuditEvent failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send one-hour reminder notifications for approved upcoming bookings.
 *
 * Returns the number of reminders newly sent.
 */
function dispatchUpcomingBookingReminders(int $userId): int {
    try {
        $pdo = getDBConnection();

        if (!tableExists($pdo, 'bookings') || !tableExists($pdo, 'notifications')) {
            return 0;
        }

        $dateColumn = null;
        if (columnExists($pdo, 'bookings', 'date')) {
            $dateColumn = 'date';
        } elseif (columnExists($pdo, 'bookings', 'booking_date')) {
            $dateColumn = 'booking_date';
        }

        if ($dateColumn === null || !columnExists($pdo, 'bookings', 'start_time') || !columnExists($pdo, 'bookings', 'status')) {
            return 0;
        }

        $query = sprintf(
            'SELECT b.id, b.%1$s AS booking_date, b.start_time, f.name AS facility_name
             FROM bookings b
             JOIN facilities f ON f.id = b.facility_id
             WHERE b.user_id = ?
               AND b.status = ?
               AND TIMESTAMP(b.%1$s, b.start_time) >= NOW()
               AND TIMESTAMP(b.%1$s, b.start_time) <= DATE_ADD(NOW(), INTERVAL 1 HOUR)
             ORDER BY b.%1$s ASC, b.start_time ASC',
            $dateColumn
        );

        $stmt = $pdo->prepare($query);
        $stmt->execute([$userId, STATUS_APPROVED]);
        $rows = $stmt->fetchAll();

        if (!$rows) {
            return 0;
        }

        $sent = 0;
        $checkStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND message LIKE ?');

        foreach ($rows as $row) {
            $bookingId = (int) ($row['id'] ?? 0);
            if ($bookingId <= 0) {
                continue;
            }

            $likeMessage = 'Reminder: Booking #' . $bookingId . '%';
            $checkStmt->execute([$userId, $likeMessage]);
            if ((int) $checkStmt->fetchColumn() > 0) {
                continue;
            }

            $message = sprintf(
                'Reminder: Booking #%d for %s starts at %s on %s.',
                $bookingId,
                (string) ($row['facility_name'] ?? 'your facility'),
                formatTime((string) $row['start_time']),
                formatDate((string) $row['booking_date'])
            );

            if (createNotification($userId, $message, true)) {
                $sent++;
            }
        }

        return $sent;
    } catch (Throwable $e) {
        error_log('dispatchUpcomingBookingReminders failed: ' . $e->getMessage());
        return 0;
    }
}
