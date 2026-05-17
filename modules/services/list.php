<?php
// Service Management — Admin only.
// Allows admin to add, edit, and toggle active/inactive status of dental services.
// SAFE: Uses soft-delete (is_active toggle) only. No hard deletes to preserve billing/record history.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Service Management';
$success = '';
$error   = '';

// ─── TOGGLE ACTIVE STATUS ────────────────────────────────────────────────────
if (isset($_GET['toggle']) && isset($_GET['sid'])) {
    $sid = secure_int($_GET['sid'] ?? 0);
    if ($sid > 0) {
        $stmt = $conn->prepare("SELECT is_active, service_name FROM services WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $sid);
        $stmt->execute();
        $svc = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($svc) {
            $new_status = $svc['is_active'] ? 0 : 1;
            $stmt2 = $conn->prepare("UPDATE services SET is_active = ? WHERE id = ?");
            $stmt2->bind_param('ii', $new_status, $sid);
            $stmt2->execute();
            $stmt2->close();
            $label = $new_status ? 'Activated Service' : 'Deactivated Service';
            log_action($conn, $current_user_id, $current_user_name, $label, 'services', $sid, "Service: " . $svc['service_name']);
        }
    }
    header('Location: list.php');
    exit();
}

// ─── ADD NEW SERVICE ─────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $service_name = trim($_POST['service_name'] ?? '');
    $description  = trim($_POST['description']  ?? '');
    $duration     = intval($_POST['duration_minutes'] ?? 30);
    $price        = floatval($_POST['price'] ?? 0);
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    if ($service_name === '') {
        $error = 'Service name is required.';
    } elseif ($duration < 5 || $duration > 480) {
        $error = 'Duration must be between 5 and 480 minutes.';
    } elseif ($price < 0) {
        $error = 'Price cannot be negative.';
    } else {
        // Check for duplicate name
        $chk = $conn->prepare("SELECT id FROM services WHERE service_name = ? LIMIT 1");
        $chk->bind_param('s', $service_name);
        $chk->execute();
        $chk->get_result()->num_rows > 0 ? $dup = true : $dup = false;
        $chk->close();

        if ($dup) {
            $error = "A service named \"" . e($service_name) . "\" already exists.";
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO services (service_name, description, duration_minutes, price, is_active)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('ssidi', $service_name, $description, $duration, $price, $is_active);
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();
            log_action($conn, $current_user_id, $current_user_name, 'Added Service', 'services', $new_id, "Service: $service_name");
            $success = "Service \"" . e($service_name) . "\" added successfully.";
        }
    }
}

// ─── EDIT EXISTING SERVICE ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $sid          = secure_int($_POST['sid'] ?? 0);
    $service_name = trim($_POST['service_name'] ?? '');
    $description  = trim($_POST['description']  ?? '');
    $duration     = intval($_POST['duration_minutes'] ?? 30);
    $price        = floatval($_POST['price'] ?? 0);
    $is_active    = isset($_POST['is_active']) ? 1 : 0;

    if ($sid <= 0) {
        $error = 'Invalid service.';
    } elseif ($service_name === '') {
        $error = 'Service name is required.';
    } elseif ($duration < 5 || $duration > 480) {
        $error = 'Duration must be between 5 and 480 minutes.';
    } elseif ($price < 0) {
        $error = 'Price cannot be negative.';
    } else {
        // Check for duplicate name on OTHER records
        $chk = $conn->prepare("SELECT id FROM services WHERE service_name = ? AND id != ? LIMIT 1");
        $chk->bind_param('si', $service_name, $sid);
        $chk->execute();
        $chk->get_result()->num_rows > 0 ? $dup = true : $dup = false;
        $chk->close();

        if ($dup) {
            $error = "Another service named \"" . e($service_name) . "\" already exists.";
        } else {
            $stmt = $conn->prepare(
                "UPDATE services
                 SET service_name = ?, description = ?, duration_minutes = ?, price = ?, is_active = ?
                 WHERE id = ?"
            );
            $stmt->bind_param('ssidii', $service_name, $description, $duration, $price, $is_active, $sid);
            $stmt->execute();
            $stmt->close();
            log_action($conn, $current_user_id, $current_user_name, 'Updated Service', 'services', $sid, "Service: $service_name");
            $success = "Service \"" . e($service_name) . "\" updated successfully.";
        }
    }
}

// ─── FETCH ALL SERVICES ───────────────────────────────────────────────────────
$services = $conn->query(
    "SELECT * FROM services ORDER BY is_active DESC, service_name ASC"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <div class="page-header">
            <div>
                <h5>Service Management</h5>
                <p class="text-muted small mb-0">Add or manage the dental services offered by the clinic.</p>
            </div>
            <div class="page-header-actions">
                <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addServiceModal">
                    <i class="bi bi-plus-circle"></i> Add New Service
                </button>
            </div>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i><?php echo $success; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Stats summary row -->
        <?php
            $total  = count($services);
            $active = count(array_filter($services, fn($s) => $s['is_active']));
        ?>
        <div class="row mb-3 g-3">
            <div class="col-md-3 col-6">
                <div class="card text-center py-3">
                    <div class="fs-3 fw-bold text-primary"><?php echo $total; ?></div>
                    <div class="small text-muted">Total Services</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card text-center py-3">
                    <div class="fs-3 fw-bold text-success"><?php echo $active; ?></div>
                    <div class="small text-muted">Active</div>
                </div>
            </div>
            <div class="col-md-3 col-6">
                <div class="card text-center py-3">
                    <div class="fs-3 fw-bold text-secondary"><?php echo $total - $active; ?></div>
                    <div class="small text-muted">Inactive</div>
                </div>
            </div>
        </div>

        <!-- Services Table -->
        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
<table class="table table-hover mb-0" id="servicesTable">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Service Name</th>
                            <th>Description</th>
                            <th>Duration</th>
                            <th>Price (₱)</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($services)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-4 d-block mb-2"></i>No services found. Click "+ Add New Service" to get started.
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($services as $i => $s): ?>
                        <tr class="<?php echo $s['is_active'] ? '' : 'table-secondary text-muted'; ?>">
                            <td class="text-muted small"><?php echo $i + 1; ?></td>
                            <td>
                                <strong><?php echo e($s['service_name']); ?></strong>
                            </td>
                            <td class="text-muted small" style="max-width:220px;">
                                <?php echo $s['description'] ? e($s['description']) : '<em class="text-muted">—</em>'; ?>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border">
                                    <i class="bi bi-clock"></i> <?php echo (int)$s['duration_minutes']; ?> min
                                </span>
                            </td>
                            <td>
                                <strong>₱<?php echo number_format($s['price'], 2); ?></strong>
                            </td>
                            <td>
                                <?php if ($s['is_active']): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary me-1"
                                    title="Edit Service"
                                    onclick="openEditModal(<?php echo htmlspecialchars(json_encode($s), ENT_QUOTES); ?>)">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                                <a href="list.php?toggle=1&sid=<?php echo (int)$s['id']; ?>"
                                   class="btn btn-sm <?php echo $s['is_active'] ? 'btn-outline-warning' : 'btn-outline-success'; ?>"
                                   title="<?php echo $s['is_active'] ? 'Deactivate' : 'Activate'; ?>"
                                   onclick="return confirm('<?php echo $s['is_active'] ? 'Deactivate' : 'Activate'; ?> this service?')">
                                    <i class="bi bi-<?php echo $s['is_active'] ? 'toggle-on' : 'toggle-off'; ?>"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total > 0): ?>
            <div class="card-footer text-muted small">
                Showing <?php echo $total; ?> service<?php echo $total !== 1 ? 's' : ''; ?>.
                <span class="ms-2 text-info"><i class="bi bi-info-circle"></i> Services are never hard-deleted — deactivating hides them from bookings while preserving all history.</span>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /.page-content -->
</div><!-- /.main-content -->

<!-- ─── ADD SERVICE MODAL ──────────────────────────────────────────────────── -->
<div class="modal fade" id="addServiceModal" tabindex="-1" aria-labelledby="addServiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="list.php" id="addServiceForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title" id="addServiceModalLabel">
                        <i class="bi bi-plus-circle-fill text-primary me-2"></i>Add New Service
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Name <span class="text-danger">*</span></label>
                        <input type="text" name="service_name" class="form-control" maxlength="100"
                               placeholder="e.g. Teeth Whitening" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="3" maxlength="500"
                                  placeholder="Brief description of this service (optional)"></textarea>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Duration (minutes) <span class="text-danger">*</span></label>
                            <select name="duration_minutes" class="form-select" required>
                                <?php foreach ([10,15,20,30,45,60,75,90,120,150,180,240] as $m): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m === 30 ? 'selected' : ''; ?>>
                                    <?php echo $m; ?> min
                                </option>
                                <?php endforeach; ?>
                                <option value="custom">Custom…</option>
                            </select>
                            <input type="number" name="duration_custom" id="addCustomDuration"
                                   class="form-control mt-2 d-none" min="5" max="480" placeholder="Enter minutes">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Standard Price (₱) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="price" class="form-control" min="0" step="0.01"
                                       value="0.00" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="addIsActive" checked>
                        <label class="form-check-label" for="addIsActive">Active (visible for booking)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save2"></i> Save Service
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ─── EDIT SERVICE MODAL ─────────────────────────────────────────────────── -->
<div class="modal fade" id="editServiceModal" tabindex="-1" aria-labelledby="editServiceModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="list.php" id="editServiceForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="sid" id="editSid">
                <div class="modal-header">
                    <h5 class="modal-title" id="editServiceModalLabel">
                        <i class="bi bi-pencil-fill text-warning me-2"></i>Edit Service
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Name <span class="text-danger">*</span></label>
                        <input type="text" name="service_name" id="editServiceName" class="form-control"
                               maxlength="100" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" id="editDescription" class="form-control" rows="3" maxlength="500"></textarea>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Duration (minutes) <span class="text-danger">*</span></label>
                            <select name="duration_minutes" id="editDuration" class="form-select" required>
                                <?php foreach ([10,15,20,30,45,60,75,90,120,150,180,240] as $m): ?>
                                <option value="<?php echo $m; ?>"><?php echo $m; ?> min</option>
                                <?php endforeach; ?>
                                <option value="custom">Custom…</option>
                            </select>
                            <input type="number" name="duration_custom" id="editCustomDuration"
                                   class="form-control mt-2 d-none" min="5" max="480" placeholder="Enter minutes">
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Standard Price (₱) <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text">₱</span>
                                <input type="number" name="price" id="editPrice" class="form-control"
                                       min="0" step="0.01" required>
                            </div>
                        </div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" id="editIsActive">
                        <label class="form-check-label" for="editIsActive">Active (visible for booking)</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="bi bi-save2"></i> Update Service
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>

<script>
// Standard duration values in the dropdowns
const STANDARD_DURATIONS = [10,15,20,30,45,60,75,90,120,150,180,240];

// ─── Add modal: custom duration toggle ───────────────────────────────────────
document.querySelector('[name="duration_minutes"]', document.getElementById('addServiceForm'))
    ?.addEventListener('change', function () {
        const custom = document.getElementById('addCustomDuration');
        if (this.value === 'custom') {
            custom.classList.remove('d-none');
            custom.required = true;
        } else {
            custom.classList.add('d-none');
            custom.required = false;
        }
    });

// Intercept add form submit — swap custom value into select before sending
document.getElementById('addServiceForm').addEventListener('submit', function () {
    const sel    = this.querySelector('[name="duration_minutes"]');
    const custom = document.getElementById('addCustomDuration');
    if (sel.value === 'custom' && custom.value) {
        sel.value = custom.value;
    }
});

// ─── Open Edit Modal ─────────────────────────────────────────────────────────
function openEditModal(svc) {
    document.getElementById('editSid').value         = svc.id;
    document.getElementById('editServiceName').value = svc.service_name;
    document.getElementById('editDescription').value = svc.description || '';
    document.getElementById('editPrice').value       = parseFloat(svc.price).toFixed(2);
    document.getElementById('editIsActive').checked  = svc.is_active == 1;

    const dur    = parseInt(svc.duration_minutes);
    const sel    = document.getElementById('editDuration');
    const custom = document.getElementById('editCustomDuration');

    if (STANDARD_DURATIONS.includes(dur)) {
        sel.value = dur;
        custom.classList.add('d-none');
        custom.required = false;
    } else {
        sel.value = 'custom';
        custom.classList.remove('d-none');
        custom.value   = dur;
        custom.required = true;
    }

    const modal = new bootstrap.Modal(document.getElementById('editServiceModal'));
    modal.show();
}

// Edit modal: custom duration toggle
document.getElementById('editDuration').addEventListener('change', function () {
    const custom = document.getElementById('editCustomDuration');
    if (this.value === 'custom') {
        custom.classList.remove('d-none');
        custom.required = true;
    } else {
        custom.classList.add('d-none');
        custom.required = false;
    }
});

// Intercept edit form submit — swap custom value
document.getElementById('editServiceForm').addEventListener('submit', function () {
    const sel    = document.getElementById('editDuration');
    const custom = document.getElementById('editCustomDuration');
    if (sel.value === 'custom' && custom.value) {
        sel.value = custom.value;
    }
});

// Auto-open edit modal if there was a POST error on an edit
<?php if ($error && isset($_POST['action']) && $_POST['action'] === 'edit'): ?>
(function() {
    const svc = <?php echo json_encode([
        'id'               => $_POST['sid'] ?? 0,
        'service_name'     => $_POST['service_name'] ?? '',
        'description'      => $_POST['description'] ?? '',
        'duration_minutes' => $_POST['duration_minutes'] ?? 30,
        'price'            => $_POST['price'] ?? 0,
        'is_active'        => isset($_POST['is_active']) ? 1 : 0,
    ]); ?>;
    openEditModal(svc);
})();
<?php endif; ?>

// Auto-open add modal if there was a POST error on an add
<?php if ($error && isset($_POST['action']) && $_POST['action'] === 'add'): ?>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('addServiceModal')).show();
});
<?php endif; ?>
</script>
</body>
</html>
