<?php
/**
 * FSUU Library Booking System
 * Admin — Manage Facilities
 */

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once dirname(__DIR__, 2) . '/includes/layout.php';

requireRole(ROLE_ADMIN, ROLE_LIBRARY_STAFF, ROLE_SUPER_ADMIN);

$pdo = getDBConnection();

function facilitiesColumnExists(PDO $pdo, string $columnName): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
    $stmt->execute([DB_NAME, 'facilities', $columnName]);
    return (int) $stmt->fetchColumn() > 0;
}

function hasActiveBookingsForFacility(PDO $pdo, int $facilityId): bool {
    if ($facilityId <= 0 || !tableExists($pdo, 'bookings')) {
        return false;
    }

    $dateColumn = null;
    if (columnExists($pdo, 'bookings', 'date')) {
        $dateColumn = 'date';
    } elseif (columnExists($pdo, 'bookings', 'booking_date')) {
        $dateColumn = 'booking_date';
    }

    $hasEndTime = columnExists($pdo, 'bookings', 'end_time');
    $hasStatus = columnExists($pdo, 'bookings', 'status');

    $whereParts = ['facility_id = ?'];
    $params = [$facilityId];

    if ($hasStatus) {
        $whereParts[] = 'status IN (?, ?)';
        $params[] = STATUS_PENDING;
        $params[] = STATUS_APPROVED;
    }

    if ($dateColumn !== null && $hasEndTime) {
        $whereParts[] = sprintf('((%s > CURDATE()) OR (%s = CURDATE() AND end_time >= CURTIME()))', $dateColumn, $dateColumn);
    } elseif ($dateColumn !== null) {
        $whereParts[] = sprintf('%s >= CURDATE()', $dateColumn);
    }

    $sql = 'SELECT COUNT(*) FROM bookings WHERE ' . implode(' AND ', $whereParts);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (int) $stmt->fetchColumn() > 0;
}

function buildArchivedFacilityName(string $currentName, int $facilityId): string {
    $trimmed = trim($currentName);
    if (preg_match('/\[ARCHIVED(?:\s+#\d+)?\]$/i', $trimmed) === 1) {
        return $trimmed;
    }

    return sprintf('%s [ARCHIVED #%d]', $trimmed, $facilityId);
}

$hasLocation = facilitiesColumnExists($pdo, 'location');
$hasCapacityMin = facilitiesColumnExists($pdo, 'capacity_min');
$hasCapacityMax = facilitiesColumnExists($pdo, 'capacity_max');
$hasAvailability = facilitiesColumnExists($pdo, 'availability');
$hasIsAvailable = facilitiesColumnExists($pdo, 'is_available');
$supportsRangeCapacity = $hasCapacityMin && $hasCapacityMax;

$action = strtolower(trim((string) ($_GET['action'] ?? '')));
if (!in_array($action, ['create', 'edit'], true)) {
    $action = '';
}

$editId = (int) ($_GET['id'] ?? 0);
$editing = $action === 'edit' && $editId > 0;

$form = [
    'name' => '',
    'type' => '',
    'location' => '',
    'capacity_min' => '1',
    'capacity_max' => '1',
    'availability' => '1',
];

$formErrors = [];
$editRow = null;

if ($editing) {
    $editStmt = $pdo->prepare('SELECT * FROM facilities WHERE id = ? LIMIT 1');
    $editStmt->execute([$editId]);
    $editRow = $editStmt->fetch();

    if (!$editRow) {
        setFlash('danger', 'Facility not found.');
        redirect(APP_URL . '/modules/admin/manage_facilities.php');
    }

    $form = [
        'name' => (string) ($editRow['name'] ?? ''),
        'type' => (string) ($editRow['type'] ?? ''),
        'location' => (string) ($editRow['location'] ?? ''),
        'capacity_min' => (string) ($editRow['capacity_min'] ?? ($editRow['capacity'] ?? 1)),
        'capacity_max' => (string) ($editRow['capacity_max'] ?? ($editRow['capacity'] ?? 1)),
        'availability' => (string) (($editRow['availability'] ?? $editRow['is_available'] ?? 1) ? '1' : '0'),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_availability'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request. Please try again.');
        redirect(APP_URL . '/modules/admin/manage_facilities.php');
    }

    $facilityId = (int) ($_POST['facility_id'] ?? 0);
    $rowStmt = $pdo->prepare('SELECT * FROM facilities WHERE id = ? LIMIT 1');
    $rowStmt->execute([$facilityId]);
    $row = $rowStmt->fetch();

    if (!$row) {
        setFlash('danger', 'Facility not found.');
        redirect(APP_URL . '/modules/admin/manage_facilities.php');
    }

    $currentAvailability = (int) ($row['availability'] ?? $row['is_available'] ?? 1);
    $nextAvailability = $currentAvailability === 1 ? 0 : 1;

    if ($nextAvailability === 0 && hasActiveBookingsForFacility($pdo, $facilityId)) {
        setFlash('warning', 'Cannot set facility as unavailable while it has active upcoming bookings.');
        redirect(APP_URL . '/modules/admin/manage_facilities.php');
    }

    try {
        if ($hasAvailability) {
            $updStmt = $pdo->prepare('UPDATE facilities SET availability = ? WHERE id = ?');
            $updStmt->execute([$nextAvailability, $facilityId]);
        } elseif ($hasIsAvailable) {
            $updStmt = $pdo->prepare('UPDATE facilities SET is_available = ? WHERE id = ?');
            $updStmt->execute([$nextAvailability, $facilityId]);
        } else {
            setFlash('danger', 'Availability column is missing in facilities table.');
            redirect(APP_URL . '/modules/admin/manage_facilities.php');
        }

        logAuditEvent((int) $_SESSION['user_id'], 'FACILITY_AVAILABILITY_TOGGLED | Facility #' . $facilityId . ' | New value: ' . $nextAvailability);
        setFlash('success', $nextAvailability === 1 ? 'Facility marked as available.' : 'Facility marked as unavailable.');
    } catch (Throwable $e) {
        error_log('Facility availability toggle failed: ' . $e->getMessage());
        setFlash('danger', 'Unable to update facility availability right now.');
    }

    redirect(APP_URL . '/modules/admin/manage_facilities.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_facility'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        setFlash('danger', 'Invalid request. Please try again.');
        redirect(APP_URL . '/modules/admin/manage_facilities.php');
    }

    $facilityId = (int) ($_POST['facility_id'] ?? 0);
    $rowStmt = $pdo->prepare('SELECT id, name FROM facilities WHERE id = ? LIMIT 1');
    $rowStmt->execute([$facilityId]);
    $row = $rowStmt->fetch();

    if (!$row) {
        setFlash('danger', 'Facility not found.');
        redirect(APP_URL . '/modules/admin/manage_facilities.php');
    }

    if (hasActiveBookingsForFacility($pdo, $facilityId)) {
        setFlash('warning', 'Cannot delete facility because it has active upcoming bookings.');
        redirect(APP_URL . '/modules/admin/manage_facilities.php');
    }

    try {
        $delStmt = $pdo->prepare('DELETE FROM facilities WHERE id = ?');
        $delStmt->execute([$facilityId]);
        logAuditEvent((int) $_SESSION['user_id'], 'FACILITY_DELETED | Facility #' . $facilityId . ' | Name: ' . (string) ($row['name'] ?? ''));
        setFlash('success', 'Facility deleted successfully.');
    } catch (Throwable $e) {
        error_log('Facility delete failed, attempting soft delete: ' . $e->getMessage());

        try {
            $archivedName = buildArchivedFacilityName((string) ($row['name'] ?? ''), $facilityId);
            $sets = ['name = ?'];
            $params = [$archivedName];

            if ($hasAvailability) {
                $sets[] = 'availability = ?';
                $params[] = 0;
            } elseif ($hasIsAvailable) {
                $sets[] = 'is_available = ?';
                $params[] = 0;
            }

            $params[] = $facilityId;
            $archiveSql = 'UPDATE facilities SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $archiveStmt = $pdo->prepare($archiveSql);
            $archiveStmt->execute($params);

            logAuditEvent((int) $_SESSION['user_id'], 'FACILITY_SOFT_DELETED | Facility #' . $facilityId . ' | Archived Name: ' . $archivedName);
            setFlash('warning', 'Hard delete was blocked by historical references. Facility was archived and marked unavailable instead.');
        } catch (Throwable $archiveError) {
            error_log('Facility soft delete fallback failed: ' . $archiveError->getMessage());
            setFlash('danger', 'Unable to delete or archive this facility right now.');
        }
    }

    redirect(APP_URL . '/modules/admin/manage_facilities.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_facility'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $formErrors[] = 'Invalid request. Please try again.';
    }

    $postedId = (int) ($_POST['facility_id'] ?? 0);
    $isEditPost = $postedId > 0;

    $form['name'] = trim((string) ($_POST['name'] ?? ''));
    $form['type'] = trim((string) ($_POST['type'] ?? ''));
    $form['location'] = trim((string) ($_POST['location'] ?? ''));
    $form['capacity_min'] = trim((string) ($_POST['capacity_min'] ?? ''));
    $form['capacity_max'] = trim((string) ($_POST['capacity_max'] ?? ''));
    $form['availability'] = (string) ($_POST['availability'] ?? '1');

    if ($form['name'] === '') {
        $formErrors[] = 'Facility name is required.';
    }

    if ($form['type'] === '') {
        $formErrors[] = 'Facility type is required.';
    }

    if ($hasLocation && $form['location'] === '') {
        $formErrors[] = 'Location is required.';
    }

    if (!in_array($form['availability'], ['0', '1'], true)) {
        $formErrors[] = 'Availability must be either Available or Unavailable.';
    }

    $capacityMin = filter_var($form['capacity_min'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $capacityMax = filter_var($form['capacity_max'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    if ($capacityMin === false) {
        $formErrors[] = 'Minimum capacity must be a whole number greater than 0.';
    }

    if ($capacityMax === false) {
        $formErrors[] = 'Maximum capacity must be a whole number greater than 0.';
    }

    if ($capacityMin !== false && $capacityMax !== false && $capacityMin > $capacityMax) {
        $formErrors[] = 'Minimum capacity cannot be greater than maximum capacity.';
    }

    if (empty($formErrors)) {
        $dupSql = 'SELECT id FROM facilities WHERE LOWER(name) = LOWER(?)';
        $dupParams = [$form['name']];
        if ($isEditPost) {
            $dupSql .= ' AND id <> ?';
            $dupParams[] = $postedId;
        }
        $dupSql .= ' LIMIT 1';

        $dupStmt = $pdo->prepare($dupSql);
        $dupStmt->execute($dupParams);
        if ($dupStmt->fetch()) {
            $formErrors[] = 'A facility with that name already exists.';
        }
    }

    if (empty($formErrors)) {
        $normalizedType = strtolower(str_replace(' ', '_', $form['type']));
        $availabilityValue = (int) $form['availability'];

        try {
            if ($isEditPost) {
                $sets = ['name = ?', 'type = ?'];
                $params = [$form['name'], $normalizedType];

                if ($supportsRangeCapacity) {
                    $sets[] = 'capacity_min = ?';
                    $sets[] = 'capacity_max = ?';
                    $params[] = (int) $capacityMin;
                    $params[] = (int) $capacityMax;
                } elseif (facilitiesColumnExists($pdo, 'capacity')) {
                    $sets[] = 'capacity = ?';
                    $params[] = (int) $capacityMax;
                }

                if ($hasLocation) {
                    $sets[] = 'location = ?';
                    $params[] = $form['location'];
                }

                if ($hasAvailability) {
                    $sets[] = 'availability = ?';
                    $params[] = $availabilityValue;
                } elseif ($hasIsAvailable) {
                    $sets[] = 'is_available = ?';
                    $params[] = $availabilityValue;
                }

                $params[] = $postedId;
                $sql = 'UPDATE facilities SET ' . implode(', ', $sets) . ' WHERE id = ?';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                logAuditEvent((int) $_SESSION['user_id'], 'FACILITY_UPDATED | Facility #' . $postedId . ' | Name: ' . $form['name']);
                setFlash('success', 'Facility updated successfully.');
            } else {
                $columns = ['name', 'type'];
                $placeholders = ['?', '?'];
                $params = [$form['name'], $normalizedType];

                if ($supportsRangeCapacity) {
                    $columns[] = 'capacity_min';
                    $columns[] = 'capacity_max';
                    $placeholders[] = '?';
                    $placeholders[] = '?';
                    $params[] = (int) $capacityMin;
                    $params[] = (int) $capacityMax;
                } elseif (facilitiesColumnExists($pdo, 'capacity')) {
                    $columns[] = 'capacity';
                    $placeholders[] = '?';
                    $params[] = (int) $capacityMax;
                }

                if ($hasLocation) {
                    $columns[] = 'location';
                    $placeholders[] = '?';
                    $params[] = $form['location'];
                }

                if ($hasAvailability) {
                    $columns[] = 'availability';
                    $placeholders[] = '?';
                    $params[] = $availabilityValue;
                } elseif ($hasIsAvailable) {
                    $columns[] = 'is_available';
                    $placeholders[] = '?';
                    $params[] = $availabilityValue;
                }

                $sql = 'INSERT INTO facilities (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                logAuditEvent((int) $_SESSION['user_id'], 'FACILITY_CREATED | Facility #' . (int) $pdo->lastInsertId() . ' | Name: ' . $form['name']);
                setFlash('success', 'Facility created successfully.');
            }

            redirect(APP_URL . '/modules/admin/manage_facilities.php');
        } catch (Throwable $e) {
            error_log('Facility save failed: ' . $e->getMessage());
            $formErrors[] = 'Unable to save facility right now. Please try again.';
        }
    }

    $action = $isEditPost ? 'edit' : 'create';
    $editing = $isEditPost;
    $editId = $postedId;
}

$facilities = $pdo->query('SELECT * FROM facilities ORDER BY name')->fetchAll();

$csrfToken = generateCsrfToken();

renderPageStart('Manage Facilities');
?>

<main class="container py-4">
    <?= getFlash() ?>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="fw-bold text-maroon mb-0"><i class="bi bi-building me-1"></i> Manage Facilities</h5>
        <a href="<?= APP_URL ?>/modules/admin/manage_facilities.php?action=create" class="btn btn-maroon btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Add Facility
        </a>
    </div>

    <?php if ($action === 'create' || $editing): ?>
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-pencil-square me-1"></i>
            <?= $editing ? 'Edit Facility' : 'Create Facility' ?>
        </div>
        <div class="card-body">
            <?php if ($formErrors): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0 ps-3">
                        <?php foreach ($formErrors as $err): ?>
                            <li><?= sanitize($err) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                <input type="hidden" name="save_facility" value="1">
                <?php if ($editing): ?>
                    <input type="hidden" name="facility_id" value="<?= (int) $editId ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Facility Name</label>
                        <input type="text" class="form-control" name="name" maxlength="150" value="<?= sanitize($form['name']) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Type</label>
                        <input type="text" class="form-control" name="type" maxlength="80" placeholder="e.g., reading_area" value="<?= sanitize($form['type']) ?>" required>
                    </div>

                    <?php if ($hasLocation): ?>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Location</label>
                        <input type="text" class="form-control" name="location" maxlength="120" placeholder="e.g., Morelos" value="<?= sanitize($form['location']) ?>" required>
                    </div>
                    <?php endif; ?>

                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Capacity Min</label>
                        <input type="number" min="1" class="form-control" name="capacity_min" value="<?= sanitize($form['capacity_min']) ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Capacity Max</label>
                        <input type="number" min="1" class="form-control" name="capacity_max" value="<?= sanitize($form['capacity_max']) ?>" required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Availability</label>
                        <select class="form-select" name="availability" required>
                            <option value="1" <?= $form['availability'] === '1' ? 'selected' : '' ?>>Available</option>
                            <option value="0" <?= $form['availability'] === '0' ? 'selected' : '' ?>>Unavailable</option>
                        </select>
                    </div>
                </div>

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-maroon">
                        <i class="bi bi-save me-1"></i> <?= $editing ? 'Update Facility' : 'Create Facility' ?>
                    </button>
                    <a href="<?= APP_URL ?>/modules/admin/manage_facilities.php" class="btn btn-outline-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Capacity</th>
                            <th>Location</th>
                            <th>Availability</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($facilities): ?>
                        <?php foreach ($facilities as $i => $f): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td><?= sanitize($f['name']) ?></td>
                                <td><?= ucwords(str_replace('_', ' ', sanitize($f['type']))) ?></td>
                                <td>
                                    <?php if (isset($f['capacity_min'], $f['capacity_max'])): ?>
                                        <?= (int) $f['capacity_min'] ?>-<?= (int) $f['capacity_max'] ?>
                                    <?php else: ?>
                                        <?= (int) ($f['capacity'] ?? 0) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= sanitize($f['location'] ?? '—') ?></td>
                                <td>
                                    <?php if ((int) ($f['availability'] ?? $f['is_available'] ?? 0) === 1): ?>
                                        <span class="badge bg-success">Available</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Unavailable</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?= APP_URL ?>/modules/admin/manage_facilities.php?action=edit&id=<?= (int) $f['id'] ?>" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i>Edit
                                    </a>
                                    <form method="POST" action="" class="d-inline ms-1">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="facility_id" value="<?= (int) $f['id'] ?>">
                                        <input type="hidden" name="toggle_availability" value="1">
                                        <button type="submit" class="btn btn-sm <?= ((int) ($f['availability'] ?? $f['is_available'] ?? 0) === 1) ? 'btn-outline-warning' : 'btn-outline-success' ?>">
                                            <i class="bi <?= ((int) ($f['availability'] ?? $f['is_available'] ?? 0) === 1) ? 'bi-toggle-off' : 'bi-toggle-on' ?> me-1"></i>
                                            <?= ((int) ($f['availability'] ?? $f['is_available'] ?? 0) === 1) ? 'Set Unavailable' : 'Set Available' ?>
                                        </button>
                                    </form>
                                    <form method="POST" action="" class="d-inline ms-1" onsubmit="return confirm('Delete this facility? This cannot be undone.')">
                                        <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
                                        <input type="hidden" name="facility_id" value="<?= (int) $f['id'] ?>">
                                        <input type="hidden" name="delete_facility" value="1">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash me-1"></i>Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No facilities found.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<?php renderPageEnd(); ?>



