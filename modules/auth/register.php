<?php
/**
 * FSUU Library Booking System
 * Student / Faculty Registration Page
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

if (isset($_SESSION['user_id'])) {
    redirect(APP_URL . '/index.php');
}

$errors = [];
$values = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $values = [
            'name'  => trim($_POST['name']  ?? ''),
            'email' => trim($_POST['email'] ?? ''),
            'role'  => trim($_POST['role']  ?? ''),
        ];
        $password  = $_POST['password']  ?? '';
        $password2 = $_POST['password2'] ?? '';

        if ($values['name'] === '')          $errors[] = 'Name is required.';
        if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!in_array($values['role'], [ROLE_STUDENT, ROLE_FACULTY], true)) $errors[] = 'Select a valid role.';
        if (strlen($password) < 8)           $errors[] = 'Password must be at least 8 characters.';
        if ($password !== $password2)        $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            $pdo = getDBConnection();

            // Check for duplicate email
            $chk = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $chk->execute([$values['email']]);
            if ($chk->fetch()) {
                $errors[] = 'That email address is already registered.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $ins  = $pdo->prepare('
                    INSERT INTO users (name, email, password, role, status)
                    VALUES (?, ?, ?, ?, ?)
                ');
                $ins->execute([
                    $values['name'],
                    $values['email'],
                    $hash,
                    $values['role'],
                    'active',
                ]);

                setFlash('success', 'Account created! You can now log in.');
                redirect(APP_URL . '/modules/auth/login.php');
            }
        }
    }
}

$csrfToken = generateCsrfToken();
renderPageStart('Register', false);
?>

<div class="auth-wrapper py-5">
    <div class="auth-card" style="max-width:520px">
        <h4 class="text-center fw-bold text-maroon mb-1">
            <i class="bi bi-person-plus me-1"></i> Create an Account
        </h4>
        <p class="text-center text-muted mb-4 small"><?= APP_NAME ?></p>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <ul class="mb-0 ps-3">
                    <?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label fw-semibold">Full Name</label>
                    <input type="text" class="form-control" name="name"
                           value="<?= sanitize($values['name'] ?? '') ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold">Email Address</label>
                    <input type="email" class="form-control" name="email"
                           value="<?= sanitize($values['email'] ?? '') ?>" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Role</label>
                    <select class="form-select" name="role" required>
                        <option value="">-- Select --</option>
                        <option value="student"  <?= ($values['role'] ?? '') === 'student'  ? 'selected' : '' ?>>Student</option>
                        <option value="faculty"  <?= ($values['role'] ?? '') === 'faculty'  ? 'selected' : '' ?>>Faculty</option>
                    </select>
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Password</label>
                    <input type="password" class="form-control" name="password" minlength="8" required>
                </div>
                <div class="col-sm-6">
                    <label class="form-label fw-semibold">Confirm Password</label>
                    <input type="password" class="form-control" name="password2" minlength="8" required>
                </div>
            </div>

            <button type="submit" class="btn btn-maroon w-100 mt-4">
                <i class="bi bi-person-check me-1"></i> Register
            </button>
        </form>

        <p class="text-center mt-3 mb-0 small">
            Already have an account?
            <a href="<?= APP_URL ?>/modules/auth/login.php" class="text-maroon fw-semibold">Login here</a>
        </p>
    </div>
</div>

<?php renderPageEnd(); ?>



