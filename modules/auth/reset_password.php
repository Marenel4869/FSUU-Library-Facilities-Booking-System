<?php
/**
 * FSUU Library Booking System
 * Reset Password
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

if (isset($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
$success = false;

$token = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$isTokenFormatValid = (strlen($token) === 64) && ctype_xdigit($token);
$tokenHash = $isTokenFormatValid ? hash('sha256', $token) : '';

$pdo = getDBConnection();

$lookupReset = static function (PDO $pdo, string $tokenHash) {
    $stmt = $pdo->prepare('
        SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at, u.status
        FROM password_resets pr
        JOIN users u ON u.id = pr.user_id
        WHERE pr.token_hash = ?
        LIMIT 1
    ');
    $stmt->execute([$tokenHash]);
    return $stmt->fetch();
};

$resetRow = $tokenHash !== '' ? $lookupReset($pdo, $tokenHash) : false;

$isTokenValid = false;
if ($resetRow) {
    $isUnused = empty($resetRow['used_at']);
    $isNotExpired = strtotime($resetRow['expires_at']) >= time();
    $isActiveUser = ($resetRow['status'] === 'active');
    $isTokenValid = $isUnused && $isNotExpired && $isActiveUser;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $password = $_POST['password'] ?? '';
        $password2 = $_POST['password2'] ?? '';

        if (!$isTokenFormatValid || !$isTokenValid) {
            $errors[] = 'This reset link is invalid or has expired.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($password !== $password2) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $newHash = password_hash($password, PASSWORD_DEFAULT);

                $updateUser = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $updateUser->execute([$newHash, $resetRow['user_id']]);

                $consumeCurrent = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
                $consumeCurrent->execute([$resetRow['id']]);

                // Invalidate any other active tokens for the same user.
                $consumeOthers = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
                $consumeOthers->execute([$resetRow['user_id']]);

                // Housekeeping for long-expired records.
                $cleanup = $pdo->prepare('DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < (NOW() - INTERVAL 2 DAY)');
                $cleanup->execute();

                $pdo->commit();
                $success = true;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Password reset failed: ' . $e->getMessage());
                $errors[] = 'Unable to reset password right now. Please try again.';
            }
        }
    }
}

$csrfToken = generateCsrfToken();
renderPageStart('Reset Password', false);
?>

<div class="auth-wrapper py-5">
    <div class="auth-card" style="max-width:520px">
        <h4 class="text-center fw-bold text-maroon mb-1">
            <i class="bi bi-shield-lock me-1"></i> Reset Password
        </h4>
        <p class="text-center text-muted mb-4 small">Set a new password for your account.</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                Your password has been reset successfully. You may now log in with your new password.
            </div>
            <a href="<?= APP_URL ?>/modules/auth/login.php" class="btn btn-maroon w-100">
                Go to Login
            </a>
        <?php elseif (!$isTokenValid): ?>
            <div class="alert alert-warning">
                This reset link is invalid or has expired. Please request a new link.
            </div>
            <a href="<?= APP_URL ?>/modules/auth/forgot_password.php" class="btn btn-outline-secondary w-100">
                Request New Reset Link
            </a>
        <?php else: ?>
            <form method="POST" action="" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="token" value="<?= sanitize($token) ?>">

                <div class="mb-3">
                    <label for="password" class="form-label fw-semibold">New Password</label>
                    <input type="password" class="form-control" id="password" name="password" minlength="8" required>
                </div>

                <div class="mb-4">
                    <label for="password2" class="form-label fw-semibold">Confirm New Password</label>
                    <input type="password" class="form-control" id="password2" name="password2" minlength="8" required>
                </div>

                <button type="submit" class="btn btn-maroon w-100">
                    <i class="bi bi-check2-circle me-1"></i> Update Password
                </button>
            </form>
        <?php endif; ?>

        <p class="text-center mt-3 mb-0 small">
            <a href="<?= APP_URL ?>/modules/auth/login.php" class="text-maroon fw-semibold">Back to Login</a>
        </p>
    </div>
</div>

<?php renderPageEnd(); ?>



