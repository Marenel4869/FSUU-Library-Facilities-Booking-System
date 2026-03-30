<?php
/**
 * FSUU Library Booking System
 * Application Entry Point
 */

require_once __DIR__ . '/includes/bootstrap.php';

if (isset($_SESSION['user_id'], $_SESSION['user_role'])) {
    redirect(dashboardForRole((string) $_SESSION['user_role']));
}

$pageTitle = 'Welcome';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/navbar.php';
?>

<main class="container py-5" style="max-width: 820px;">
    <?= getFlash() ?>

    <div class="card border-0 shadow-sm">
        <div class="card-body p-5 text-center">
            <h1 class="display-6 fw-bold text-maroon mb-3">FSUU Library Booking System</h1>
            <p class="lead text-muted mb-4">
                Reserve discussion rooms, labs, and study spaces quickly with role-based workflows for students, faculty, and administrators.
            </p>
            <div class="d-flex justify-content-center gap-2 flex-wrap">
                <a href="<?= APP_URL ?>/modules/auth/login.php" class="btn btn-maroon px-4">
                    <i class="bi bi-box-arrow-in-right me-1"></i> Login
                </a>
                <a href="<?= APP_URL ?>/modules/auth/register.php" class="btn btn-outline-secondary px-4">
                    <i class="bi bi-person-plus me-1"></i> Register
                </a>
            </div>
        </div>
    </div>
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>


