<?php
// List all dental records across all patients.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Dental Records';

$search     = trim($_GET['search'] ?? '');
$patient_id = intval($_GET['patient_id'] ?? 0);
$per_page   = 20;
$page       = max(1, intval($_GET['page'] ?? 1));

$where = "WHERE 1=1";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%' OR p.patient_code LIKE '%$s%' OR dr.treatment_done LIKE '%$s%')";
}
if ($patient_id) {
    $where .= " AND dr.patient_id = $patient_id";
}

// Load filtered patient name for page heading
$filter_patient_name = '';
if ($patient_id) {
    $fp = $conn->query("SELECT CONCAT(first_name,' ',last_name) as n, patient_code FROM patients WHERE id=$patient_id LIMIT 1")->fetch_assoc();
    if ($fp) $filter_patient_name = $fp['n'] . ' (' . $fp['patient_code'] . ')';
}

$total_count = $conn->query("SELECT COUNT(*) as c FROM dental_records dr LEFT JOIN patients p ON dr.patient_id = p.id $where")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;
$filter_qs  = '';
if ($search)     $filter_qs .= 'search=' . urlencode($search) . '&';
if ($patient_id) $filter_qs .= 'patient_id=' . $patient_id . '&';

$records = $conn->query("
    SELECT dr.*, s.service_name,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code,
           CONCAT(u.full_name) as recorded_by_name
    FROM dental_records dr
    LEFT JOIN patients p ON dr.patient_id = p.id
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN users u ON dr.recorded_by = u.id
    $where
    ORDER BY p.last_name ASC, p.first_name ASC, dr.visit_date DESC
    LIMIT $per_page OFFSET $offset
")->fetch_all(MYSQLI_ASSOC);
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
                <h5>Dental / Treatment Records</h5>
                <?php if ($filter_patient_name): ?>
                <p style="font-size:0.82rem;color:var(--blue-500);margin:2px 0 0;">
                    <i class="bi bi-funnel-fill"></i> Filtered: <?php echo htmlspecialchars($filter_patient_name); ?>
                    <a href="list.php" style="color:var(--gray-400);margin-left:8px;font-size:0.75rem;">✕ Clear filter</a>
                </p>
                <?php endif; ?>
            </div>
            <div class="page-header-actions">
                <?php if ($patient_id): ?>
                <a href="../patients/view.php?id=<?php echo $patient_id; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-person"></i> Patient Profile
                </a>
                <?php endif; ?>
                <a href="add.php<?php echo $patient_id ? '?patient_id='.$patient_id : ''; ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-plus"></i> Add Record
                </a>
            </div>
        </div>

        <form method="GET" class="mb-3">
            <?php if ($patient_id): ?>
            <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
            <?php endif; ?>
            <div class="input-group" style="max-width:400px;">
                <input type="text" name="search" class="form-control" placeholder="Search patient name, code, or treatment..." value="<?php echo htmlspecialchars($search); ?>">
                <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
                <?php if ($search || $patient_id): ?>
                    <a href="list.php" class="btn btn-outline-danger">Clear</a>
                <?php endif; ?>
            </div>
        </form>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
<table class="table table-hover mb-0 mobile-card-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Visit Date</th>
                            <th>Service</th>
                            <th>Tooth</th>
                            <th>Treatment Done</th>
                            <th>Recorded By</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-3">No dental records found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $r): ?>
                            <tr>
                                <td data-label="Patient">
                                    <a href="list.php?patient_id=<?php echo $r['patient_id']; ?>"
                                       style="font-weight:600;color:var(--gray-800);text-decoration:none;"
                                       title="Filter records by this patient">
                                        <?php echo htmlspecialchars($r['patient_name']); ?>
                                    </a>
                                    <br><small class="text-muted"><?php echo htmlspecialchars($r['patient_code']); ?></small>
                                </td>
                                <td data-label="Visit Date"><?php echo date('M d, Y', strtotime($r['visit_date'])); ?></td>
                                <td data-label="Service"><?php echo htmlspecialchars($r['service_name'] ?? '—'); ?></td>
                                <td data-label="Tooth"><?php echo htmlspecialchars($r['tooth_number'] ?? '—'); ?></td>
                                <td data-label="Treatment" style="max-width:250px;">
                                    <span title="<?php echo htmlspecialchars($r['treatment_done']); ?>">
                                        <?php echo htmlspecialchars(strlen($r['treatment_done']) > 60 ? substr($r['treatment_done'], 0, 60) . '...' : $r['treatment_done']); ?>
                                    </span>
                                </td>
                                <td data-label="Recorded By"><?php echo htmlspecialchars($r['recorded_by_name'] ?? '—'); ?></td>
                                <td data-label="Actions">
                                    <div style="display:flex;gap:4px;flex-wrap:wrap;">
                                        <a href="view.php?id=<?php echo $r['id']; ?>" class="btn btn-sm btn-primary" title="View full dental record">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                        <a href="../print/prescription.php?id=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print Prescription"><i class="bi bi-capsule"></i> RX</a>
                                        <a href="../print/medical_certificate.php?id=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Medical Certificate"><i class="bi bi-file-earmark-medical"></i> Cert</a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($total_pages > 1): ?>
        <div class="pagination-bar">
            <div class="pagination-info">
                Showing <?php echo number_format($offset+1); ?>–<?php echo number_format(min($offset+$per_page,$total_count)); ?> of <?php echo number_format($total_count); ?> records
            </div>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page-1; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-chevron-left"></i> Prev</a>
                <?php endif; ?>
                <?php for ($pg = max(1,$page-2); $pg <= min($total_pages,$page+2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>"
                   class="btn btn-sm <?php echo $pg===$page ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page+1; ?>" class="btn btn-sm btn-outline-secondary">Next <i class="bi bi-chevron-right"></i></a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
