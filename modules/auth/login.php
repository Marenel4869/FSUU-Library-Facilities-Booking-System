<?php
/**
 * FSUU Library Booking System
 * Login Page
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

// Redirect already-authenticated users
if (isset($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request. Please try again.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $error = 'Email/ID and password are required.';
        } else {
            $pdo  = getDBConnection();
            $idValue = ctype_digit($identifier) ? (int) $identifier : 0;

            $stmt = $pdo->prepare('SELECT id, name, email, password, role, status FROM users WHERE email = :identifier OR id = :id LIMIT 1');
            $stmt->execute([
                ':identifier' => $identifier,
                ':id'         => $idValue,
            ]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    $error = 'Your account is not active. Please contact the administrator.';
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id']   = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_role'] = $user['role'];
                    $_SESSION['user_email'] = $user['email'];

                    // Keep password hashes current with PHP defaults after successful login.
                    if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                        $rehash = $pdo->prepare('UPDATE users SET password = :password WHERE id = :id');
                        $rehash->execute([
                            ':password' => password_hash($password, PASSWORD_DEFAULT),
                            ':id'       => $user['id'],
                        ]);
                    }

                    redirect(dashboardForRole((string) $user['role']));
                }
            } else {
                $error = 'Invalid Email/ID or password.';
            }
        }
    }
}

$csrfToken = generateCsrfToken();
renderPageStart('Login', false);
?>

<div class="auth-wrapper">
    <div class="auth-card">
        <h4 class="text-center fw-bold text-maroon mb-1">
            <i class="bi bi-book-half me-1"></i><?= APP_NAME ?>
        </h4>
        <p class="text-center text-muted mb-4 small">Sign in to your account</p>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?= sanitize($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="mb-3">
                <label for="identifier" class="form-label fw-semibold">Email or User ID</label>
                <input type="text" class="form-control" id="identifier" name="identifier"
                       placeholder="you@fsuu.edu.ph or 1001" required autofocus
                       value="<?= sanitize($_POST['identifier'] ?? '') ?>">
            </div>

            <div class="mb-4">
                <label for="password" class="form-label fw-semibold">Password</label>
                <input type="password" class="form-control" id="password" name="password"
                       placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-maroon w-100">
                <i class="bi bi-box-arrow-in-right me-1"></i> Login
            </button>
        </form>

        <p class="text-center mt-3 mb-1 small">
            <a href="<?= APP_URL ?>/modules/auth/forgot_password.php" class="text-maroon fw-semibold">Forgot Password?</a>
        </p>

        <p class="text-center mt-3 mb-0 small">
            Don't have an account?
            <a href="<?= APP_URL ?>/modules/auth/register.php" class="text-maroon fw-semibold">Register here</a>
        </p>
    </div>
</div>

<?php renderPageEnd(); ?>



