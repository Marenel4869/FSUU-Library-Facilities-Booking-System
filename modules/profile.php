<?php
/**
 * FSUU Library Booking System
 * Profile Management
 */

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/layout.php';

requireLogin();

$pdo = getDBConnection();
$userId = (int) $_SESSION['user_id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $name = trim((string) ($_POST['name'] ?? ''));
        $contactNumber = trim((string) ($_POST['contact_number'] ?? ''));

        if ($name === '') {
            $errors[] = 'Name is required.';
        }

        if ($contactNumber !== '' && !preg_match('/^[0-9+\-\s]{7,20}$/', $contactNumber)) {
            $errors[] = 'Contact number format is invalid.';
        }

        if (empty($errors)) {
            $update = $pdo->prepare('UPDATE users SET name = ?, contact_number = ? WHERE id = ?');
            $update->execute([$name, $contactNumber !== '' ? $contactNumber : null, $userId]);
            $_SESSION['user_name'] = $name;
            setFlash('success', 'Profile updated successfully.');
            redirect(APP_URL . '/modules/profile.php');
        }
    }
}

$userStmt = $pdo->prepare('SELECT id, name, email, role, status, contact_number, created_at FROM users WHERE id = ? LIMIT 1');
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user) {
    setFlash('danger', 'Unable to load your profile.');
    redirect(APP_URL . '/index.php');
}

$csrfToken = generateCsrfToken();
renderPageStart('My Profile');
?>

<main class="container py-4" style="max-width:760px">
    <?= getFlash() ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-maroon mb-0"><i class="bi bi-person-circle me-1"></i> Profile Management</h5>
        <a href="<?= dashboardForRole((string) ($_SESSION['user_role'] ?? '')) ?>" class="btn btn-outline-secondary btn-sm">Back</a>
    </div>

    <div class="card">
        <div class="card-body">
            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $err): ?>
                            <li><?= sanitize($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Full Name</label>
                        <input type="text" name="name" class="form-control" value="<?= sanitize((string) $user['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" placeholder="09xx xxx xxxx" value="<?= sanitize((string) ($user['contact_number'] ?? '')) ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" class="form-control" value="<?= sanitize((string) $user['email']) ?>" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Role</label>
                        <input type="text" class="form-control" value="<?= strtoupper(sanitize((string) $user['role'])) ?>" readonly>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Status</label>
                        <input type="text" class="form-control" value="<?= ucfirst(sanitize((string) $user['status'])) ?>" readonly>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-maroon">
                        <i class="bi bi-check2-circle me-1"></i> Save Profile
                    </button>
                </div>
            </form>
        </div>
    </div>
</main>

<?php renderPageEnd(); ?>
