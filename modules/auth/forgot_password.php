<?php
/**
 * FSUU Library Booking System
 * Forgot Password
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

if (isset($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }

        if (empty($errors)) {
            $pdo = getDBConnection();
            $ip = substr($_SERVER['REMOTE_ADDR'] ?? 'unknown', 0, 45);
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'unknown', 0, 255);

            // Remove old reset records to limit token retention.
            $cleanup = $pdo->prepare('DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < (NOW() - INTERVAL 2 DAY)');
            $cleanup->execute();

            // Rate-limit reset requests by IP in a short window.
            $rateStmt = $pdo->prepare('SELECT COUNT(*) FROM password_resets WHERE requested_ip = ? AND created_at >= (NOW() - INTERVAL 15 MINUTE)');
            $rateStmt->execute([$ip]);
            $ipCount = (int) $rateStmt->fetchColumn();

            if ($ipCount >= 20) {
                $done = true;
            } else {
                $stmt = $pdo->prepare('SELECT id, email, status FROM users WHERE email = ? LIMIT 1');
                $stmt->execute([$email]);
                $user = $stmt->fetch();

                if ($user && $user['status'] === 'active') {
                    // Invalidate older unused tokens for this user.
                    $invalidate = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL');
                    $invalidate->execute([$user['id']]);

                    $rawToken = bin2hex(random_bytes(32));
                    $tokenHash = hash('sha256', $rawToken);

                    $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, requested_ip, user_agent) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE), ?, ?)');
                    $ins->execute([$user['id'], $tokenHash, $ip, $ua]);

                    $resetLink = APP_URL . '/modules/auth/reset_password.php?token=' . urlencode($rawToken);

                    // Simulate email delivery by logging reset links to a local file.
                    $logPath = ROOT_PATH . '/uploads/password_reset_emails.log';
                    $line = sprintf("[%s] To: %s | Reset link: %s%s", date('Y-m-d H:i:s'), $user['email'], $resetLink, PHP_EOL);
                    $logged = @file_put_contents($logPath, $line, FILE_APPEND | LOCK_EX);
                    if ($logged === false) {
                        error_log('Password reset simulated email log failed for: ' . $user['email']);
                    }
                }

                $done = true;
            }
        }
    }
}

$csrfToken = generateCsrfToken();
renderPageStart('Forgot Password', false);
?>

<div class="auth-wrapper py-5">
    <div class="auth-card" style="max-width:520px">
        <h4 class="text-center fw-bold text-maroon mb-1">
            <i class="bi bi-key me-1"></i> Forgot Password
        </h4>
        <p class="text-center text-muted mb-4 small">Enter your account email to receive a password reset link.</p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($done): ?>
            <div class="alert alert-success">
                If that email exists in our system, a password reset link has been sent.
                For this development setup, check the simulated email log at <strong>uploads/password_reset_emails.log</strong>.
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="mb-3">
                <label for="email" class="form-label fw-semibold">Email Address</label>
                <input type="email" class="form-control" id="email" name="email"
                       placeholder="you@fsuu.edu.ph" required autofocus
                       value="<?= sanitize($_POST['email'] ?? '') ?>">
            </div>

            <button type="submit" class="btn btn-maroon w-100">
                <i class="bi bi-send me-1"></i> Send Reset Link
            </button>
        </form>

        <p class="text-center mt-3 mb-0 small">
            Remembered your password?
            <a href="<?= APP_URL ?>/modules/auth/login.php" class="text-maroon fw-semibold">Back to Login</a>
        </p>
    </div>
</div>

<?php renderPageEnd(); ?>



