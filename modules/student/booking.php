<?php
/**
 * FSUU Library Booking System
 * Student — Book a Facility
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireRole(ROLE_STUDENT);

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];
$errors = [];

/**
 * Whitelist of allowed facility names for student bookings.
 */
const ALLOWED_FACILITIES = ['CL1', 'CL2', 'Museum', 'EIRC'];

/**
 * Determine whether a facility should follow CL room rules (exact match).
 */
function isClRoom(array $facility): bool {
    $name = trim((string) ($facility['name'] ?? ''));
    return in_array($name, ['CL1', 'CL2'], true);
}

function isCl1(array $facility): bool {
    $name = trim((string) ($facility['name'] ?? ''));
    return $name === 'CL1';
}

function isCl2(array $facility): bool {
    $name = trim((string) ($facility['name'] ?? ''));
    return $name === 'CL2';
}

/**
 * Determine whether a facility requires supporting documents (exact match).
 */
function isMuseumOrEirc(array $facility): bool {
    $name = trim((string) ($facility['name'] ?? ''));
    return in_array($name, ['Museum', 'EIRC'], true);
}

// Load available facilities from strict whitelist
$facilityStmt = $pdo->prepare('
        SELECT id, name, type, capacity_min, capacity_max, availability
        FROM facilities
        WHERE availability = 1
            AND name IN (?, ?, ?, ?)
        ORDER BY name
');
$facilityStmt->execute(ALLOWED_FACILITIES);
$facilities = $facilityStmt->fetchAll();
$facilityMap = [];
foreach ($facilities as $facility) {
    $facilityMap[(int) $facility['id']] = $facility;
}

$uploadedRequestPath = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $facilityId  = (int) ($_POST['facility_id']  ?? 0);
        $bookingDate = trim($_POST['booking_date'] ?? '');
        $startTime   = trim($_POST['start_time']   ?? '');
        $endTime     = trim($_POST['end_time']     ?? '');
        $purpose     = trim($_POST['purpose']      ?? '');
        $attendees   = (int) ($_POST['attendees']  ?? 0);

        $selectedFacility = $facilityMap[$facilityId] ?? null;

        if (!$facilityId || !$selectedFacility) {
            $errors[] = 'Please select a valid facility.';
        }
        if (!$bookingDate) {
            $errors[] = 'Booking date is required.';
        }
        if (!$startTime) {
            $errors[] = 'Start time is required.';
        }
        if (!$endTime) {
            $errors[] = 'End time is required.';
        }
        if ($attendees < 1) {
            $errors[] = 'Attendees must be at least 1.';
        }
        if ($endTime !== '' && $startTime !== '' && $endTime <= $startTime) {
            $errors[] = 'End time must be after start time.';
        }
        if ($bookingDate !== '' && $bookingDate < date('Y-m-d')) {
            $errors[] = 'Booking date cannot be in the past.';
        }

        if ($selectedFacility) {
            $isClFacility = isClRoom($selectedFacility);
            $isMuseumFacility = isMuseumOrEirc($selectedFacility);
            $capacityMax = (int) ($selectedFacility['capacity_max'] ?? 0);

            if (isCl1($selectedFacility) && $attendees > 7) {
                $errors[] = 'CL Room 1 allows a maximum of 7 people.';
            }

            if (isCl2($selectedFacility) && $attendees > 8) {
                $errors[] = 'CL Room 2 allows a maximum of 8 people.';
            }

            if ($capacityMax > 0 && $attendees > $capacityMax) {
                $errors[] = 'Selected attendees exceed the maximum capacity for this facility.';
            }

            if ($isClFacility && ($startTime < '08:00' || $endTime > '18:00')) {
                $errors[] = 'CL Rooms can only be booked between 8:00 AM and 6:00 PM.';
            }

            if ($isMuseumFacility) {
                if (!isset($_FILES['supporting_file']) || (int) $_FILES['supporting_file']['error'] === UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Museum/EIRC bookings require a PDF or image upload.';
                }
            }
        }

        if (empty($errors)) {
            $conflict = $pdo->prepare('
                SELECT id FROM bookings
                WHERE facility_id = ?
                  AND date = ?
                  AND status NOT IN (?, ?)
                  AND start_time < ? AND end_time > ?
                LIMIT 1
            ');
            $conflict->execute([$facilityId, $bookingDate, STATUS_REJECTED, STATUS_CANCELLED, $endTime, $startTime]);

            if ($conflict->fetch()) {
                $errors[] = 'That facility is already booked during the selected time. Please choose a different slot.';
            }
        }

        if (empty($errors) && $selectedFacility && isMuseumOrEirc($selectedFacility)) {
            $file = $_FILES['supporting_file'];
            $validation = validateSupportingUpload($file);
            if (!$validation['ok']) {
                $errors = array_merge($errors, $validation['errors']);
            } else {
                $uploadDirectory = UPLOAD_PATH . '/requests';
                $storedName = sprintf('%s.%s', bin2hex(random_bytes(16)), $validation['extension']);
                $destination = $uploadDirectory . '/' . $storedName;

                if (!is_dir($uploadDirectory) || !is_writable($uploadDirectory)) {
                    $errors[] = 'Upload directory is not writable.';
                } elseif (!move_uploaded_file($file['tmp_name'], $destination)) {
                    $errors[] = 'Unable to save the uploaded file.';
                } else {
                    $uploadedRequestPath = 'uploads/requests/' . $storedName;
                }
            }
        }

        if (empty($errors) && $selectedFacility) {
            $isClFacility = isClRoom($selectedFacility);
            $requiresRequest = isMuseumOrEirc($selectedFacility);
            $bookingStatus = $isClFacility ? STATUS_APPROVED : STATUS_PENDING;

            try {
                $pdo->beginTransaction();

                $ins = $pdo->prepare('
                    INSERT INTO bookings (user_id, facility_id, date, start_time, end_time, status, purpose, attendees)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ');
                $ins->execute([
                    $userId,
                    $facilityId,
                    $bookingDate,
                    $startTime,
                    $endTime,
                    $bookingStatus,
                    $purpose !== '' ? $purpose : null,
                    $attendees,
                ]);

                if ($requiresRequest && $uploadedRequestPath !== null) {
                    $requestInsert = $pdo->prepare('
                        INSERT INTO requests (user_id, facility_id, file_path, status)
                        VALUES (?, ?, ?, ?)
                    ');
                    $requestInsert->execute([$userId, $facilityId, $uploadedRequestPath, STATUS_PENDING]);
                }

                $pdo->commit();

                if ($isClFacility) {
                    createNotification($userId, 'Your CL Room booking has been confirmed successfully.', true);
                } else {
                    createNotification($userId, 'Your booking request was submitted and is pending admin approval.', true);
                }

                if ($isClFacility) {
                    setFlash('success', 'CL Room booking confirmed successfully.');
                } else {
                    setFlash('success', 'Booking submitted successfully and is now pending review.');
                }
                redirect(APP_URL . '/modules/student/my_bookings.php');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($uploadedRequestPath !== null) {
                    $uploadedFile = ROOT_PATH . '/' . $uploadedRequestPath;
                    if (is_file($uploadedFile)) {
                        unlink($uploadedFile);
                    }
                }
                error_log('Student booking failed: ' . $e->getMessage());
                $errors[] = 'Unable to save your booking right now. Please try again.';
            }
        }
    }
}

$csrfToken = generateCsrfToken();
renderPageStart('Book a Facility');
?>

<main class="container py-4" style="max-width:680px">
    <?= getFlash() ?>

    <div class="card">
        <div class="card-header">
            <i class="bi bi-calendar-plus me-1"></i> Book a Facility
        </div>
        <div class="card-body">

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" enctype="multipart/form-data" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="alert alert-light border small">
                    <strong>Booking Rules</strong><br>
                    CL Rooms: instant booking, max capacity enforced, 8:00 AM to 6:00 PM only.<br>
                    Museum/EIRC: supporting PDF or image required and booking stays pending for review.
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Facility</label>
                    <select class="form-select" name="facility_id" required>
                        <option value="">-- Select a facility --</option>
                        <?php foreach ($facilities as $f): ?>
                        <option value="<?= $f['id'] ?>"
                            <?= isset($_POST['facility_id']) && (int)$_POST['facility_id'] === $f['id'] ? 'selected' : '' ?>>
                            <?= sanitize($f['name']) ?>
                            (<?= ucwords(str_replace('_', ' ', sanitize($f['type']))) ?>
                            · Capacity: <?= (int) $f['capacity_min'] ?>-<?= (int) $f['capacity_max'] ?>
                            <?= isMuseumOrEirc($f) ? ' · Request Only' : '' ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Booking Date</label>
                    <input type="date" class="form-control" name="booking_date" id="booking_date"
                           value="<?= sanitize($_POST['booking_date'] ?? '') ?>" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Start Time</label>
                        <input type="time" class="form-control" name="start_time" id="start_time"
                               value="<?= sanitize($_POST['start_time'] ?? '') ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">End Time</label>
                        <input type="time" class="form-control" name="end_time" id="end_time"
                               value="<?= sanitize($_POST['end_time'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Number of Attendees</label>
                    <input type="number" min="1" class="form-control" name="attendees"
                           value="<?= sanitize($_POST['attendees'] ?? '1') ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Purpose / Description</label>
                    <textarea class="form-control" name="purpose" rows="3" placeholder="e.g., Group study for midterms"><?= sanitize($_POST['purpose'] ?? '') ?></textarea>
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Supporting File (Museum/EIRC only)</label>
                    <input type="file" class="form-control" name="supporting_file" accept=".pdf,.jpg,.jpeg,.png,image/*,application/pdf">
                    <div class="form-text">Accepted formats: PDF, JPG, JPEG, PNG. Maximum file size: 5 MB.</div>
                </div>

                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-maroon">
                        <i class="bi bi-send me-1"></i> Submit Booking
                    </button>
                    <a href="<?= APP_URL ?>/modules/student/my_bookings.php" class="btn btn-outline-secondary">
                        Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</main>

<?php renderPageEnd(); ?>



