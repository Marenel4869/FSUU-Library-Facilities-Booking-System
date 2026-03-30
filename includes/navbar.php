<?php
/**
 * Role-aware navigation bar.
 * Expects $_SESSION['user_id'], $_SESSION['user_role'], $_SESSION['user_name'].
 */
$role = $_SESSION['user_role'] ?? null;
$notificationCount = 0;

$isStudentRole = $role === ROLE_STUDENT;
$isAcademicRole = in_array($role, [ROLE_FACULTY, ROLE_ADVISER, ROLE_STAFF], true);
$isManagementRole = in_array($role, [ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN], true);

if (isset($_SESSION['user_id'])) {
    $notificationCount = getUnreadNotificationCount((int) $_SESSION['user_id']);
}

$notificationLinkMap = [
    ROLE_STUDENT => APP_URL . '/modules/student/dashboard.php#notifications',
    ROLE_FACULTY => APP_URL . '/modules/faculty/dashboard.php#notifications',
    ROLE_ADVISER => APP_URL . '/modules/faculty/dashboard.php#notifications',
    ROLE_STAFF   => APP_URL . '/modules/faculty/dashboard.php#notifications',
    ROLE_ADMIN   => APP_URL . '/modules/admin/manage_bookings.php',
    ROLE_LIBRARY_STAFF => APP_URL . '/modules/admin/manage_bookings.php',
    ROLE_SUPER_ADMIN   => APP_URL . '/modules/admin/manage_bookings.php',
];
$notificationLink = $notificationLinkMap[$role] ?? APP_URL . '/index.php';
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-maroon shadow-sm">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="<?= APP_URL ?>">
            <i class="bi bi-book-half me-1"></i><?= APP_NAME ?>
        </a>
        <button class="navbar-toggler" type="button"
                data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav ms-auto align-items-lg-center gap-1">

                <?php if ($isStudentRole): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/student/dashboard.php">
                            <i class="bi bi-house-door"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/student/booking.php">
                            <i class="bi bi-calendar-plus"></i> Book a Facility
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/student/my_bookings.php">
                            <i class="bi bi-journals"></i> My Bookings
                        </a>
                    </li>

                <?php elseif ($isAcademicRole): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/faculty/dashboard.php">
                            <i class="bi bi-house-door"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/faculty/dashboard.php#booking-form">
                            <i class="bi bi-calendar-plus"></i> Book a Facility
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/faculty/my_bookings.php">
                            <i class="bi bi-journals"></i> My Bookings
                        </a>
                    </li>

                <?php elseif ($isManagementRole): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/admin/dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/admin/manage_bookings.php">
                            <i class="bi bi-calendar-check"></i> Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/admin/manage_facilities.php">
                            <i class="bi bi-building"></i> Facilities
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/admin/manage_users.php">
                            <i class="bi bi-people"></i> Users
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/admin/reports.php">
                            <i class="bi bi-bar-chart-line"></i> Reports
                        </a>
                    </li>
                <?php endif; ?>

                <?php if ($role): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= APP_URL ?>/modules/profile.php">
                            <i class="bi bi-person-circle"></i> Profile
                        </a>
                    </li>

                    <li class="nav-item">
                        <a class="nav-link position-relative" href="<?= $notificationLink ?>" title="Notifications">
                            <i class="bi bi-bell"></i> Notifications
                            <?php if ($notificationCount > 0): ?>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                                    <?= $notificationCount > 99 ? '99+' : $notificationCount ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                <?php endif; ?>

                <?php if ($role): ?>
                    <li class="nav-item ms-lg-2">
                        <a class="btn btn-outline-light btn-sm"
                           href="<?= APP_URL ?>/modules/auth/logout.php">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="btn btn-light btn-sm"
                           href="<?= APP_URL ?>/modules/auth/login.php">
                            <i class="bi bi-box-arrow-in-right"></i> Login
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
