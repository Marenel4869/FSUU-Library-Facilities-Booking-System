<?php
/**
 * FSUU Library Booking System
 * Faculty Dashboard with Rule-Based Booking Form
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireRole(ROLE_FACULTY, ROLE_ADVISER, ROLE_STAFF);

$pdo    = getDBConnection();
$userId = $_SESSION['user_id'];
$errors = [];
$selectedNotification = null;

dispatchUpcomingBookingReminders((int) $userId);

const READING_AREA_PURPOSES = ['Class', 'Research', 'Exam', 'Meeting', 'Training', 'Seminar'];
const FACULTY_AREA_PURPOSES = ['Meeting', 'Teaching Demo', 'Research Defense', 'Training', 'Seminar', 'Others'];

function normalizeFacultyFacilityName(array $facility): string {
    return strtoupper(preg_replace('/\s+/', '', trim((string) ($facility['name'] ?? ''))) ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid notification request.');
        redirect(APP_URL . '/modules/faculty/dashboard.php#notifications');
    }

    if (isset($_POST['mark_all_read'])) {
        $updated = markAllNotificationsAsRead($userId);
        setFlash('success', $updated > 0 ? 'All notifications marked as read.' : 'No unread notifications to update.');
        redirect(APP_URL . '/modules/faculty/dashboard.php#notifications');
    }

    if (isset($_POST['open_notification_id'])) {
        $noteId = (int) $_POST['open_notification_id'];
        markNotificationAsRead($userId, $noteId);
        redirect(APP_URL . '/modules/faculty/dashboard.php?show_note=' . $noteId . '#notifications');
    }

    if (isset($_POST['mark_read_id'])) {
        $noteId = (int) $_POST['mark_read_id'];
        if (markNotificationAsRead($userId, $noteId)) {
            setFlash('success', 'Notification marked as read.');
        } else {
            setFlash('warning', 'Notification not found or already marked as read.');
        }
        redirect(APP_URL . '/modules/faculty/dashboard.php#notifications');
    }
}

/**
 * Rule detector for Reading Area.
 */
function isReadingArea(array $facility): bool {
    return normalizeFacultyFacilityName($facility) === 'READINGAREA';
}

/**
 * Rule detector for Faculty Area.
 */
function isFacultyArea(array $facility): bool {
    return normalizeFacultyFacilityName($facility) === 'FACULTYAREA';
}

function isCl3(array $facility): bool {
    return normalizeFacultyFacilityName($facility) === 'CL3';
}

function facilityLocationLabel(array $facility): string {
    $location = trim((string) ($facility['location'] ?? ''));
    if ($location !== '') {
        return $location;
    }

    if (isReadingArea($facility) || isFacultyArea($facility)) {
        return 'Morelos';
    }

    return 'Main Campus';
}

function purposeOptionsForFacultyFacility(array $facility): array {
    if (isReadingArea($facility)) {
        return READING_AREA_PURPOSES;
    }

    if (isFacultyArea($facility)) {
        return FACULTY_AREA_PURPOSES;
    }

    return [];
}

$facilities = $pdo->query('
    SELECT id, name, type, location, capacity_min, capacity_max, availability
    FROM facilities
    WHERE availability = 1
    ORDER BY name
')->fetchAll();

$bookableFacilities = array_values(array_filter($facilities, static function (array $facility): bool {
    return isReadingArea($facility) || isFacultyArea($facility) || isCl3($facility);
}));

$facilityMap = [];
foreach ($bookableFacilities as $facility) {
    $facilityMap[(int) $facility['id']] = $facility;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Invalid request. Please try again.';
    } else {
        $facilityId  = (int) ($_POST['facility_id'] ?? 0);
        $bookingDate = trim($_POST['booking_date'] ?? '');
        $startTime   = trim($_POST['start_time'] ?? '');
        $endTime     = trim($_POST['end_time'] ?? '');
        $purposeOption = trim($_POST['purpose_option'] ?? '');
        $purposeOther  = trim($_POST['purpose_other'] ?? '');
        $purposeText   = trim($_POST['purpose_text'] ?? '');
        $attendees   = (int) ($_POST['attendees'] ?? 0);

        $selectedFacility = $facilityMap[$facilityId] ?? null;

        if (!$selectedFacility) {
            $errors[] = 'Please select a valid facility.';
        }
        if ($bookingDate === '') {
            $errors[] = 'Booking date is required.';
        }
        if ($startTime === '' || $endTime === '') {
            $errors[] = 'Start time and end time are required.';
        }
        if ($bookingDate !== '' && $bookingDate < date('Y-m-d')) {
            $errors[] = 'Booking date cannot be in the past.';
        }
        if ($startTime !== '' && $endTime !== '' && $endTime <= $startTime) {
            $errors[] = 'End time must be after start time.';
        }
        if ($attendees < 1) {
            $errors[] = 'Attendees must be at least 1.';
        }

        if ($selectedFacility) {
            $isReading = isReadingArea($selectedFacility);
            $isFaculty = isFacultyArea($selectedFacility);
            $isCl3Facility = isCl3($selectedFacility);
            $capacityMin = (int) ($selectedFacility['capacity_min'] ?? 0);
            $capacityMax = (int) ($selectedFacility['capacity_max'] ?? 0);
            $validPurposeOptions = purposeOptionsForFacultyFacility($selectedFacility);

            if ($isReading || $isFaculty) {
                if ($purposeOption === '') {
                    $errors[] = 'Please select a purpose for this booking.';
                } elseif (!in_array($purposeOption, $validPurposeOptions, true)) {
                    $errors[] = 'Selected purpose is not allowed for this facility.';
                }
            }

            if ($isFaculty && $purposeOption === 'Others' && $purposeOther === '') {
                $errors[] = 'Please specify the purpose when selecting Others.';
            }

            if ($isCl3Facility && $purposeText === '') {
                $errors[] = 'Please provide a purpose or notes for CL3 bookings.';
            }

            if ($capacityMin > 0 && $attendees < $capacityMin) {
                $errors[] = 'Attendees are below the minimum capacity for this facility.';
            }
            if ($capacityMax > 0 && $attendees > $capacityMax) {
                $errors[] = 'Attendees exceed the maximum capacity for this facility.';
            }

            if ($isReading) {
                if ($attendees < 40 || $attendees > 200) {
                    $errors[] = 'Reading Area requires attendees between 40 and 200.';
                }

                $isMorningSlot = ($startTime >= '07:00' && $endTime <= '10:00');
                $isAfternoonSlot = ($startTime >= '13:00' && $endTime <= '17:00');
                if (!$isMorningSlot && !$isAfternoonSlot) {
                    $errors[] = 'Reading Area can only be booked from 7:00-10:00 AM or 1:00-5:00 PM.';
                }

                $dailyCountStmt = $pdo->prepare('
                    SELECT COUNT(*)
                    FROM bookings b
                    WHERE b.facility_id = ?
                      AND b.date = ?
                      AND b.status NOT IN (?, ?)
                ');
                $dailyCountStmt->execute([$facilityId, $bookingDate, STATUS_REJECTED, STATUS_CANCELLED]);
                $dailyCount = (int) $dailyCountStmt->fetchColumn();
                if ($dailyCount >= 4) {
                    $errors[] = 'Reading Area allows a maximum of 4 bookings per day system-wide.';
                }
            }

            if ($isFaculty) {
                $durationMinutes = (int) ((strtotime('1970-01-01 ' . $endTime) - strtotime('1970-01-01 ' . $startTime)) / 60);
                if ($durationMinutes < 10 || $durationMinutes > 30) {
                    $errors[] = 'Faculty Area duration must be between 10 and 30 minutes.';
                }

                $isMorningSlot = ($startTime >= '07:00' && $endTime <= '12:00');
                $isAfternoonSlot = ($startTime >= '13:00' && $endTime <= '17:00');
                if (!$isMorningSlot && !$isAfternoonSlot) {
                    $errors[] = 'Faculty Area can only be booked from 7:00 AM-12:00 PM or 1:00 PM-5:00 PM.';
                }
            }

            if ($isCl3Facility && $attendees > 2) {
                $errors[] = 'CL3 allows a maximum of 2 people for faculty bookings.';
            }

            if (!$isReading && !$isFaculty && !$isCl3Facility) {
                $errors[] = 'Selected facility does not match supported faculty booking rules.';
            }
        }

        if (empty($errors)) {
            $conflictStmt = $pdo->prepare('
                SELECT id
                FROM bookings
                WHERE facility_id = ?
                  AND date = ?
                  AND status NOT IN (?, ?)
                  AND start_time < ?
                  AND end_time > ?
                LIMIT 1
            ');
            $conflictStmt->execute([$facilityId, $bookingDate, STATUS_REJECTED, STATUS_CANCELLED, $endTime, $startTime]);
            if ($conflictStmt->fetch()) {
                $errors[] = 'This facility is already booked for the selected time range.';
            }
        }

        if (empty($errors)) {
            $storedPurpose = null;
            if ($selectedFacility) {
                if (isCl3($selectedFacility)) {
                    $storedPurpose = $purposeText !== '' ? $purposeText : null;
                } elseif (isFacultyArea($selectedFacility) && $purposeOption === 'Others') {
                    $storedPurpose = 'Others: ' . $purposeOther;
                } else {
                    $storedPurpose = $purposeOption !== '' ? $purposeOption : null;
                }
            }

            $insertStmt = $pdo->prepare('
                INSERT INTO bookings (user_id, facility_id, date, start_time, end_time, status, purpose, attendees)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $insertStmt->execute([
                $userId,
                $facilityId,
                $bookingDate,
                $startTime,
                $endTime,
                STATUS_PENDING,
                $storedPurpose,
                $attendees,
            ]);

            createNotification($userId, 'Your faculty booking request has been submitted and is pending admin approval.', true);

            setFlash('success', 'Booking request submitted successfully.');
            redirect(APP_URL . '/modules/faculty/dashboard.php');
        }
    }
}

$stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM bookings WHERE user_id = ? GROUP BY status');
$stmt->execute([$userId]);
$counts = array_column($stmt->fetchAll(), 'total', 'status');

$recent = $pdo->prepare('
    SELECT b.*, f.name AS facility_name
    FROM bookings b
    JOIN facilities f ON f.id = b.facility_id
    WHERE b.user_id = ?
    ORDER BY b.id DESC
    LIMIT 5
');
$recent->execute([$userId]);
$recentBookings = $recent->fetchAll();

$viewableFacilities = $pdo->query('
    SELECT id, name, type, location, capacity_min, capacity_max, availability
    FROM facilities
    WHERE availability = 1
    ORDER BY location ASC, name ASC
')->fetchAll();

$notifications = getRecentNotifications($userId, 6);
if (isset($_GET['show_note']) && ctype_digit((string) $_GET['show_note'])) {
    $selectedNotification = getNotificationById($userId, (int) $_GET['show_note']);
}

$csrfToken = generateCsrfToken();
renderPageStart('Faculty Dashboard');
?>

<main class="container py-4">
    <?= getFlash() ?>

    <h4 class="fw-bold text-maroon mb-4">
        <i class="bi bi-house-door me-1"></i>
        Welcome, <?= sanitize($_SESSION['user_name']) ?>!
    </h4>

    <div class="row g-3 mb-4">
        <?php
        $stats = [
            ['label' => 'Pending',   'key' => STATUS_PENDING,   'icon' => 'clock-history',  'color' => 'warning'],
            ['label' => 'Approved',  'key' => STATUS_APPROVED,  'icon' => 'check-circle',   'color' => 'success'],
            ['label' => 'Completed', 'key' => STATUS_COMPLETED, 'icon' => 'calendar-check', 'color' => 'primary'],
            ['label' => 'Rejected',  'key' => STATUS_REJECTED,  'icon' => 'x-circle',       'color' => 'danger'],
        ];
        foreach ($stats as $s): ?>
        <div class="col-sm-6 col-lg-3">
            <div class="card stat-card h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small"><?= $s['label'] ?> Bookings</div>
                        <div class="stat-number"><?= $counts[$s['key']] ?? 0 ?></div>
                    </div>
                    <i class="bi bi-<?= $s['icon'] ?> fs-2 text-<?= $s['color'] ?>"></i>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-building me-1"></i> Viewable Facilities</span>
            <small class="text-light-emphasis">All available bookable assets, including Morelos facilities</small>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Facility</th>
                            <th>Type</th>
                            <th>Location</th>
                            <th>Capacity</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($viewableFacilities): ?>
                        <?php foreach ($viewableFacilities as $facility): ?>
                            <tr>
                                <td><?= sanitize($facility['name']) ?></td>
                                <td><?= ucwords(str_replace('_', ' ', sanitize($facility['type']))) ?></td>
                                <td><?= sanitize(facilityLocationLabel($facility)) ?></td>
                                <td><?= (int) $facility['capacity_min'] ?>-<?= (int) $facility['capacity_max'] ?></td>
                                <td><span class="badge bg-success">Available</span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No facilities available right now.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card mb-4" id="booking-form">
        <div class="card-header">
            <i class="bi bi-calendar-plus me-1"></i> Faculty Booking Form
        </div>
        <div class="card-body">
            <div class="alert alert-light border small">
                <strong>Rules:</strong><br>
                CL3: maximum of 2 attendees for academic-role bookings.<br>
                Reading Area: max 4 bookings/day system-wide, time slots 7:00-10:00 AM or 1:00-5:00 PM, attendees 40-200.<br>
                Faculty Area: duration must be 10-30 minutes, time slots 7:00 AM-12:00 PM or 1:00-5:00 PM.
            </div>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($errors as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">

                <div class="mb-3">
                    <label class="form-label fw-semibold">Facility</label>
                    <select class="form-select" name="facility_id" required>
                        <option value="">-- Select CL3, Reading Area, or Faculty Area --</option>
                        <?php foreach ($bookableFacilities as $f): ?>
                            <option value="<?= (int) $f['id'] ?>" <?= isset($_POST['facility_id']) && (int) $_POST['facility_id'] === (int) $f['id'] ? 'selected' : '' ?>>
                                <?= sanitize($f['name']) ?> (<?= sanitize(facilityLocationLabel($f)) ?> · Capacity: <?= (int) $f['capacity_min'] ?>-<?= (int) $f['capacity_max'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Booking Date</label>
                    <input type="date" class="form-control" name="booking_date" value="<?= sanitize($_POST['booking_date'] ?? '') ?>" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">Start Time</label>
                        <input type="time" class="form-control" name="start_time" value="<?= sanitize($_POST['start_time'] ?? '') ?>" required>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label fw-semibold">End Time</label>
                        <input type="time" class="form-control" name="end_time" value="<?= sanitize($_POST['end_time'] ?? '') ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Attendees</label>
                    <input type="number" min="1" class="form-control" name="attendees" value="<?= sanitize($_POST['attendees'] ?? '1') ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Purpose</label>
                    <select class="form-select" name="purpose_option" id="purpose_option">
                        <option value="">-- Select purpose when required --</option>
                        <?php
                        $selectedFacilityForForm = isset($_POST['facility_id']) ? ($facilityMap[(int) $_POST['facility_id']] ?? null) : null;
                        $selectedPurposeOptions = $selectedFacilityForForm ? purposeOptionsForFacultyFacility($selectedFacilityForForm) : [];
                        foreach ($selectedPurposeOptions as $purposeOption):
                        ?>
                            <option value="<?= sanitize($purposeOption) ?>" <?= ($_POST['purpose_option'] ?? '') === $purposeOption ? 'selected' : '' ?>>
                                <?= sanitize($purposeOption) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Reading Area and Faculty Area use controlled purpose options.</div>
                </div>

                <div class="mb-3">
                    <label class="form-label fw-semibold">Other Purpose Details</label>
                    <input type="text" class="form-control" name="purpose_other" id="purpose_other" value="<?= sanitize($_POST['purpose_other'] ?? '') ?>" placeholder="Required when Faculty Area purpose is Others">
                </div>

                <div class="mb-4">
                    <label class="form-label fw-semibold">Purpose / Notes for CL3</label>
                    <textarea class="form-control" name="purpose_text" id="purpose_text" rows="3" placeholder="Required for CL3 bookings"><?= sanitize($_POST['purpose_text'] ?? '') ?></textarea>
                </div>

                <button type="submit" class="btn btn-maroon">
                    <i class="bi bi-send me-1"></i> Submit Booking
                </button>
            </form>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var purposeMap = {
                'READINGAREA': <?= json_encode(READING_AREA_PURPOSES) ?>,
                'FACULTYAREA': <?= json_encode(FACULTY_AREA_PURPOSES) ?>
            };
            var facilitySelect = document.querySelector('select[name="facility_id"]');
            var purposeSelect = document.getElementById('purpose_option');
            var otherInput = document.getElementById('purpose_other');
            var cl3Textarea = document.getElementById('purpose_text');

            function normalizeName(name) {
                return String(name || '').replace(/\s+/g, '').toUpperCase();
            }

            function currentFacilityName() {
                if (!facilitySelect) {
                    return '';
                }
                var option = facilitySelect.options[facilitySelect.selectedIndex];
                return option ? normalizeName(option.text.split('(')[0]) : '';
            }

            function syncPurposeFields() {
                var facilityName = currentFacilityName();
                var options = purposeMap[facilityName] || [];
                var selectedValue = purposeSelect.dataset.currentValue || purposeSelect.value || '';

                purposeSelect.innerHTML = '<option value="">-- Select purpose when required --</option>';
                options.forEach(function (value) {
                    var option = document.createElement('option');
                    option.value = value;
                    option.textContent = value;
                    if (value === selectedValue) {
                        option.selected = true;
                    }
                    purposeSelect.appendChild(option);
                });

                var needsDropdown = facilityName === 'READINGAREA' || facilityName === 'FACULTYAREA';
                var needsOther = facilityName === 'FACULTYAREA' && purposeSelect.value === 'Others';
                var needsCl3Notes = facilityName === 'CL3';

                purposeSelect.disabled = !needsDropdown;
                otherInput.disabled = !needsOther;
                cl3Textarea.disabled = !needsCl3Notes;

                purposeSelect.closest('.mb-3').style.display = needsDropdown ? '' : 'none';
                otherInput.closest('.mb-3').style.display = needsOther ? '' : 'none';
                cl3Textarea.closest('.mb-4').style.display = needsCl3Notes ? '' : 'none';
            }

            if (purposeSelect) {
                purposeSelect.dataset.currentValue = purposeSelect.value;
                purposeSelect.addEventListener('change', syncPurposeFields);
            }

            if (facilitySelect) {
                facilitySelect.addEventListener('change', function () {
                    if (purposeSelect) {
                        purposeSelect.dataset.currentValue = '';
                    }
                    syncPurposeFields();
                });
            }

            syncPurposeFields();
        });
    </script>

    <div id="notifications" class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bell me-1"></i> Notifications</span>
            <form method="POST" action="" class="m-0">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="mark_all_read" value="1">
                <button type="submit" class="btn btn-outline-light btn-sm">Mark all as read</button>
            </form>
        </div>
        <div class="card-body">
            <?php if ($notifications): ?>
                <ul class="list-group list-group-flush">
                    <?php foreach ($notifications as $note): ?>
                        <li class="list-group-item px-0 d-flex justify-content-between align-items-start">
                            <div>
                                <div><?= sanitize($note['message']) ?></div>
                                <small class="text-muted"><?= sanitize($note['created_at']) ?></small>
                            </div>
                            <div class="d-flex flex-column align-items-end gap-2">
                                <span class="badge <?= $note['status'] === 'unread' ? 'bg-warning text-dark' : 'bg-secondary' ?>">
                                    <?= ucfirst(sanitize($note['status'])) ?>
                                </span>
                                <form method="POST" action="" class="m-0">
                                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                    <input type="hidden" name="open_notification_id" value="<?= (int) $note['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">View details</button>
                                </form>
                                <?php if ($note['status'] === 'unread'): ?>
                                    <form method="POST" action="" class="m-0">
                                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                                        <input type="hidden" name="mark_read_id" value="<?= (int) $note['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary">Mark as read</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else: ?>
                <p class="text-muted mb-0">No notifications yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($selectedNotification): ?>
    <div class="modal fade" id="notificationDetailModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Notification Detail</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2"><?= sanitize($selectedNotification['message']) ?></p>
                    <small class="text-muted d-block">Date: <?= sanitize($selectedNotification['created_at']) ?></small>
                    <small class="text-muted d-block">Status: <?= ucfirst(sanitize($selectedNotification['status'])) ?></small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var modalElement = document.getElementById('notificationDetailModal');
            if (modalElement && window.bootstrap) {
                var modal = new bootstrap.Modal(modalElement);
                modal.show();
            }
        });
    </script>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-journals me-1"></i> Recent Bookings</span>
            <a href="<?= APP_URL ?>/modules/faculty/my_bookings.php" class="btn btn-sm btn-light">
                <i class="bi bi-journals"></i> My Bookings
            </a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th><th>Facility</th><th>Date</th><th>Time</th><th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($recentBookings): ?>
                        <?php foreach ($recentBookings as $i => $b): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= sanitize($b['facility_name']) ?></td>
                            <td><?= formatDate($b['date']) ?></td>
                            <td><?= formatTime($b['start_time']) ?> – <?= formatTime($b['end_time']) ?></td>
                            <td><?= statusBadge($b['status']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">No bookings yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer text-end">
            <a href="<?= APP_URL ?>/modules/faculty/my_bookings.php" class="btn btn-sm btn-maroon">View All</a>
        </div>
    </div>
</main>

<?php renderPageEnd(); ?>



