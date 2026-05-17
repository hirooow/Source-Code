<?php
// List appointments with filters. Confirm, check-in, update status, print, or delete.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Appointments';

// Pre-load services for the walk-in drawer
$walkin_services = $conn->query("SELECT id, service_name, price FROM services WHERE is_active = 1 ORDER BY service_name")->fetch_all(MYSQLI_ASSOC);

// Auto-open walk-in drawer if ?walkin=1 is in the URL
$auto_open_walkin = isset($_GET['walkin']) && $_GET['walkin'] === '1';

$status_filter = $_GET['status'] ?? '';
$date_filter   = $_GET['date'] ?? '';
$doctor_filter = intval($_GET['doctor_id'] ?? 0);
$search        = trim($_GET['search'] ?? '');

// Pre-load doctors for filter dropdown
$all_doctors = $conn->query("SELECT id, full_name FROM doctors WHERE is_active = 1 ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);

// Whitelist status values — never interpolate raw user input into SQL
$allowed_statuses = ['pending', 'confirmed', 'completed', 'cancelled', 'no-show'];
if (!in_array($status_filter, $allowed_statuses)) $status_filter = '';
// Validate date format (YYYY-MM-DD) — reject anything that doesn't match
if ($date_filter && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)) $date_filter = '';

$where = "WHERE 1=1";
if ($status_filter) $where .= " AND a.status = '" . $conn->real_escape_string($status_filter) . "'";
if ($date_filter)   $where .= " AND a.appointment_date = '" . $conn->real_escape_string($date_filter) . "'";
if ($doctor_filter) $where .= " AND a.doctor_id = $doctor_filter";
if ($search) {
    $s = $conn->real_escape_string($search);
    $where .= " AND (p.first_name LIKE '%$s%' OR p.last_name LIKE '%$s%' OR a.appointment_code LIKE '%$s%')";
}

$per_page    = 20;
$page        = max(1, intval($_GET['page'] ?? 1));
$total_count = (int)$conn->query("
    SELECT COUNT(*) as c FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    $where
")->fetch_assoc()['c'];
$total_pages = max(1, ceil($total_count / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$filter_parts = [];
if ($status_filter) $filter_parts[] = 'status=' . urlencode($status_filter);
if ($date_filter)   $filter_parts[] = 'date='   . urlencode($date_filter);
if ($doctor_filter) $filter_parts[] = 'doctor_id=' . $doctor_filter;
if ($search)        $filter_parts[] = 'search=' . urlencode($search);
$filter_qs = $filter_parts ? implode('&', $filter_parts) . '&' : '';

$appointments = $conn->query("
    SELECT a.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           s.service_name, d.full_name as doctor_name, d.id as doctor_id,
           b.id as bill_id, b.status as bill_status
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.id
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors  d ON a.doctor_id  = d.id
    LEFT JOIN bills    b ON b.appointment_id = a.id
    $where
    ORDER BY a.appointment_date DESC, a.appointment_time ASC
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

        <!-- ── Page Header ─────────────────────────────────────── -->
        <div class="page-header-bar" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:20px;background:var(--white);border:var(--border);border-radius:14px;padding:12px 18px;box-shadow:0 1px 6px rgba(0,0,0,0.05);">
            <div style="display:flex;align-items:center;gap:10px;">
                <div style="width:40px;height:40px;background:linear-gradient(135deg,#2563eb,#60a5fa);border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                    <i class="bi bi-calendar2-check" style="color:var(--white);font-size:1.1rem;"></i>
                </div>
                <div>
                    <div style="font-size:1rem;font-weight:800;color:var(--gray-900);line-height:1.1;">Appointments</div>
                    <div style="font-size:0.72rem;color:var(--gray-400);font-weight:500;"><?php echo number_format($total_count); ?> total record<?php echo $total_count !== 1 ? 's' : ''; ?></div>
                </div>
            </div>
            <div style="margin-left:auto;">
                <button onclick="openWalkinDrawer()" style="display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:10px;background:linear-gradient(135deg,#16a34a,#22c55e);color:var(--white);border:none;font-size:0.84rem;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(22,163,74,0.3);transition:all 0.15s;" onmouseover="this.style.boxShadow='0 4px 14px rgba(22,163,74,0.45)'" onmouseout="this.style.boxShadow='0 2px 8px rgba(22,163,74,0.3)'">
                    <i class="bi bi-plus-circle-fill" style="font-size:0.95rem;"></i> New Appointment
                </button>
            </div>
        </div>

        <!-- ── Quick Status Tabs ────────────────────────────────── -->
        <div class="mobile-tab-bar" style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
            <?php
            $tab_statuses = [
                '' => ['label'=>'All', 'icon'=>'bi-grid-3x3-gap', 'color'=>'#2563eb', 'bg'=>'#eff6ff', 'border'=>'#bfdbfe'],
                'pending'   => ['label'=>'Pending',   'icon'=>'bi-clock', 'color'=>'#d97706', 'bg'=>'#fffbeb', 'border'=>'#fde68a'],
                'confirmed' => ['label'=>'Confirmed', 'icon'=>'bi-check-circle', 'color'=>'#2563eb', 'bg'=>'#eff6ff', 'border'=>'#bfdbfe'],
                'completed' => ['label'=>'Completed', 'icon'=>'bi-check2-all', 'color'=>'#16a34a', 'bg'=>'#f0fdf4', 'border'=>'#bbf7d0'],
                'cancelled' => ['label'=>'Cancelled', 'icon'=>'bi-x-circle', 'color'=>'#dc2626', 'bg'=>'#fff1f2', 'border'=>'#fecdd3'],
                'no-show'   => ['label'=>'No-show',   'icon'=>'bi-person-dash', 'color'=>'#64748b', 'bg'=>'var(--gray-50)', 'border'=>'#e2e8f0'],
            ];
            foreach ($tab_statuses as $val => $tab):
                $isActive = ($status_filter === $val);
                $href = 'list.php?' . ($val ? 'status=' . $val . '&' : '') . ($search ? 'search=' . urlencode($search) . '&' : '') . ($doctor_filter ? 'doctor_id=' . $doctor_filter . '&' : '');
            ?>
            <a href="<?php echo $href; ?>" style="display:inline-flex;align-items:center;gap:5px;padding:6px 14px;border-radius:20px;font-size:0.76rem;font-weight:700;text-decoration:none;transition:all 0.15s;
                border:1.5px solid <?php echo $isActive ? $tab['color'] : '#e2e8f0'; ?>;
                background:<?php echo $isActive ? $tab['bg'] : 'var(--white)'; ?>;
                color:<?php echo $isActive ? $tab['color'] : '#64748b'; ?>;
                box-shadow:<?php echo $isActive ? '0 2px 8px rgba(0,0,0,0.08)' : 'none'; ?>;">
                <i class="bi <?php echo $tab['icon']; ?>"></i>
                <?php echo $tab['label']; ?>
            </a>
            <?php endforeach; ?>
        </div>

        <!-- ── Filter Bar ───────────────────────────────────────── -->
        <form method="GET" style="background:var(--white);border:var(--border);border-radius:12px;padding:12px 16px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">
            <?php if ($status_filter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status_filter); ?>"><?php endif; ?>
            <div class="mobile-filter-bar" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
                <div style="position:relative;flex:1;min-width:200px;">
                    <i class="bi bi-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.82rem;"></i>
                    <input type="text" name="search" style="width:100%;padding:8px 12px 8px 32px;border:1.5px solid var(--gray-200);border-radius:9px;font-size:0.82rem;outline:none;transition:border 0.15s;" placeholder="Search patient or code…" value="<?php echo htmlspecialchars($search); ?>" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <div style="position:relative;">
                    <i class="bi bi-calendar3" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--gray-400);font-size:0.82rem;pointer-events:none;"></i>
                    <input type="date" name="date" style="padding:8px 12px 8px 32px;border:1.5px solid var(--gray-200);border-radius:9px;font-size:0.82rem;outline:none;transition:border 0.15s;" value="<?php echo htmlspecialchars($date_filter); ?>" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'">
                </div>
                <?php if (!empty($all_doctors)): ?>
                <select name="doctor_id" style="padding:8px 14px;border:1.5px solid var(--gray-200);border-radius:9px;font-size:0.82rem;outline:none;background:var(--white);color:var(--gray-600);transition:border 0.15s;" onfocus="this.style.borderColor='#2563eb'" onblur="this.style.borderColor='#e2e8f0'">
                    <option value="">All Doctors</option>
                    <?php foreach ($all_doctors as $d): ?>
                    <option value="<?php echo $d['id']; ?>" <?php echo $doctor_filter == $d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <button type="submit" style="padding:8px 20px;border-radius:9px;background:#2563eb;color:var(--white);border:none;font-size:0.82rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:6px;transition:background 0.15s;" onmouseover="this.style.background='#1d4ed8'" onmouseout="this.style.background='#2563eb'">
                    <i class="bi bi-funnel-fill"></i> Filter
                </button>
                <?php if ($search || $date_filter || $doctor_filter || $status_filter): ?>
                <a href="list.php" style="padding:8px 16px;border-radius:9px;border:1.5px solid #fca5a5;background:var(--danger-bg);color:var(--danger);font-size:0.82rem;font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px;">
                    <i class="bi bi-x-lg"></i> Clear
                </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- ── Table ────────────────────────────────────────────── -->
        <div class="mobile-card-table-wrap" style="background:var(--white);border-radius:14px;border:var(--border);overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06);">
            <div class="table-responsive" style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;" id="appointmentsTable" class="mobile-card-table">
                <thead>
                   <tr style="background:linear-gradient(to bottom,var(--gray-100),var(--gray-200));border-bottom:2px solid var(--gray-300);">
                       <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Code</th>
                       <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Patient</th>
                       <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Service</th>
                       <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Doctor</th>
                       <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Date & Time</th>
                       <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Status</th>
                       <th style="padding:12px 16px;font-size:0.7rem;font-weight:800;text-transform:uppercase;letter-spacing:0.07em;color:var(--gray-600);text-align:left;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($appointments)): ?>
                    <tr>
                        <td colspan="7" style="padding:60px 20px;text-align:center;color:var(--gray-400);">
                            <i class="bi bi-calendar-x" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:0.5;"></i>
                            <div style="font-size:0.9rem;font-weight:600;">No appointments found.</div>
                            <div style="font-size:0.78rem;margin-top:4px;">Try adjusting your filters or add a new appointment.</div>
                        </td>
                    </tr>
                    <?php else: ?>
                    <?php foreach ($appointments as $a):
                        $isToday = ($a['appointment_date'] === date('Y-m-d'));
                        $rowBg = $isToday ? 'background:linear-gradient(to right,var(--blue-50),var(--white));' : '';
                        // Status config
                        $sCfg = match($a['status']) {
                            'pending'   => ['bg'=>'#fffbeb','color'=>'#d97706','border'=>'#fde68a','label'=>'Pending',   'icon'=>'bi-clock-history'],
                            'confirmed' => ['bg'=>'#eff6ff','color'=>'#2563eb','border'=>'#bfdbfe','label'=>'Confirmed', 'icon'=>'bi-check-circle-fill'],
                            'completed' => ['bg'=>'#f0fdf4','color'=>'#16a34a','border'=>'#bbf7d0','label'=>'Completed', 'icon'=>'bi-check2-all'],
                            'cancelled' => ['bg'=>'#fff1f2','color'=>'#dc2626','border'=>'#fecdd3','label'=>'Cancelled', 'icon'=>'bi-x-circle-fill'],
                            'no-show'   => ['bg'=>'var(--gray-50)','color'=>'#64748b','border'=>'#e2e8f0','label'=>'No-show',   'icon'=>'bi-person-dash'],
                            default     => ['bg'=>'var(--gray-50)','color'=>'#94a3b8','border'=>'#e2e8f0','label'=>ucfirst($a['status']),'icon'=>'bi-circle'],
                        };
                    ?>
                    <tr style="<?php echo $rowBg; ?>border-bottom:1px solid var(--gray-100);transition:background 0.15s;" onmouseover="this.style.background='var(--gray-50)'" onmouseout="this.style.background='<?php echo $isToday ? 'linear-gradient(to right,var(--blue-50),var(--white))' : 'var(--white)'; ?>'">
                        <!-- Code -->
                        <td data-label="Code" style="padding:13px 16px;">
                            <div style="display:flex;align-items:center;gap:6px;">
                                <?php if ($isToday): ?><span style="width:6px;height:6px;border-radius:50%;background:#2563eb;display:inline-block;flex-shrink:0;box-shadow:0 0 0 2px #bfdbfe;"></span><?php endif; ?>
                                <span style="font-size:0.79rem;font-weight:700;color:var(--primary);font-family:monospace;"><?php echo htmlspecialchars($a['appointment_code']); ?></span>
                            </div>
                        </td>
                        <!-- Patient -->
                        <td data-label="Patient" style="padding:13px 16px;">
                            <div style="font-size:0.85rem;font-weight:700;color:var(--gray-900);"><?php echo htmlspecialchars(ucwords(strtolower($a['patient_name']))); ?></div>
                        </td>
                        <!-- Service -->
                        <td data-label="Service" style="padding:13px 16px;">
                            <div style="font-size:0.82rem;color:var(--gray-600);font-weight:500;"><?php echo htmlspecialchars($a['service_name'] ?? '—'); ?></div>
                        </td>
                        <!-- Doctor -->
                        <td data-label="Doctor" style="padding:13px 16px;">
                            <?php if (!empty($a['doctor_name'])): ?>
                            <span style="display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;background:linear-gradient(135deg,#2563eb,#60a5fa);color:var(--white);font-size:0.71rem;font-weight:700;white-space:nowrap;">
                                <i class="bi bi-person-badge" style="font-size:0.68rem;"></i>
                                <?php echo htmlspecialchars($a['doctor_name']); ?>
                            </span>
                            <?php else: ?>
                            <span style="font-size:0.78rem;color:var(--gray-300);">Unassigned</span>
                            <?php endif; ?>
                        </td>
                        <!-- Date & Time (merged) -->
                        <td data-label="Date & Time" style="padding:13px 16px;">
                            <div style="font-size:0.82rem;font-weight:700;color:var(--gray-700);"><?php echo date('M d, Y', strtotime($a['appointment_date'])); ?></div>
                            <div style="font-size:0.73rem;color:var(--gray-400);margin-top:1px;"><i class="bi bi-clock" style="font-size:0.65rem;"></i> <?php echo date('h:i A', strtotime($a['appointment_time'])); ?></div>
                        </td>
                        <!-- Status -->
                        <td data-label="Status" style="padding:13px 16px;">
                            <span style="display:inline-flex;align-items:center;gap:4px;padding:4px 11px;border-radius:20px;font-size:0.73rem;font-weight:700;background:<?php echo $sCfg['bg']; ?>;color:<?php echo $sCfg['color']; ?>;border:1.5px solid <?php echo $sCfg['border']; ?>;">
                                <i class="bi <?php echo $sCfg['icon']; ?>" style="font-size:0.68rem;"></i>
                                <?php echo $sCfg['label']; ?>
                            </span>
                        </td>
                        <!-- Actions -->
                        <td data-label="Actions" style="padding:13px 16px;">
                            <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center;">
                                <?php if ($a['status'] === 'confirmed'): ?>
                                <a href="<?php echo BASE_URL; ?>modules/treatments/add.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>"
                                   style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;background:linear-gradient(135deg,#16a34a,#22c55e);color:var(--white);font-size:0.75rem;font-weight:700;text-decoration:none;box-shadow:0 2px 6px rgba(22,163,74,0.3);white-space:nowrap;">
                                    <i class="bi bi-person-check-fill"></i> Check-in
                                </a>
                                <?php elseif ($a['status'] === 'completed'): ?>
                                <a href="<?php echo BASE_URL; ?>modules/patients/view.php?id=<?php echo $a['patient_id']; ?>"
                                   style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;background:var(--success-bg);color:var(--success);border:1.5px solid var(--success-border);font-size:0.75rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                                    <i class="bi bi-clipboard2-check"></i> Record
                                </a>
                                <?php if (!empty($a['bill_id'])): ?>
                                <a href="<?php echo BASE_URL; ?>modules/billing/view.php?id=<?php echo $a['bill_id']; ?>"
                                   style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;font-size:0.75rem;font-weight:700;text-decoration:none;white-space:nowrap;<?php echo $a['bill_status'] === 'paid' ? 'background:linear-gradient(135deg,#16a34a,#22c55e);color:var(--white);box-shadow:0 2px 6px rgba(22,163,74,0.25);' : 'background:var(--warning-bg);color:var(--warning);border:1.5px solid var(--warning-border);'; ?>">
                                    <i class="bi bi-receipt"></i> <?php echo $a['bill_status'] === 'paid' ? 'Paid' : 'Bill'; ?>
                                </a>
                                <?php else: ?>
                                <a href="<?php echo BASE_URL; ?>modules/billing/create.php?patient_id=<?php echo $a['patient_id']; ?>&appointment_id=<?php echo $a['id']; ?>"
                                   style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:8px;background:var(--blue-50);color:var(--primary);border:1.5px solid var(--blue-200);font-size:0.75rem;font-weight:700;text-decoration:none;white-space:nowrap;">
                                    <i class="bi bi-receipt"></i> Create Bill
                                </a>
                                <?php endif; ?>
                                <?php elseif ($a['status'] === 'pending'): ?>
                                <button onclick="openConfirmModal(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars($a['appointment_code'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($a['patient_name'], ENT_QUOTES); ?>')"
                                   style="display:inline-flex;align-items:center;gap:4px;padding:5px 12px;border-radius:8px;background:var(--blue-50);color:var(--primary);border:1.5px solid var(--blue-200);font-size:0.75rem;font-weight:700;cursor:pointer;white-space:nowrap;">
                                    <i class="bi bi-check-lg"></i> Confirm
                                </button>
                                <?php endif; ?>
                                <!-- Print -->
                                <a href="<?php echo BASE_URL; ?>modules/print/appointment_slip.php?id=<?php echo $a['id']; ?>" target="_blank"
                                   style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--gray-50);color:var(--gray-500);border:1.5px solid var(--gray-200);text-decoration:none;font-size:0.8rem;transition:all 0.12s;" title="Print Slip"
                                   onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background='var(--gray-50)'">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <!-- Edit Status -->
                                <button onclick="updateStatus(<?php echo $a['id']; ?>, this)"
                                   style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--gray-50);color:var(--gray-500);border:1.5px solid var(--gray-200);cursor:pointer;font-size:0.8rem;transition:all 0.12s;" title="Update Status"
                                   onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background='var(--gray-50)'">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <!-- Delete -->
                                <button onclick="confirmDeleteAppt(<?php echo $a['id']; ?>, '<?php echo htmlspecialchars($a['appointment_code'], ENT_QUOTES); ?>')"
                                   style="display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:7px;background:var(--danger-bg);color:var(--danger);border:1.5px solid var(--danger-border);cursor:pointer;font-size:0.8rem;transition:all 0.12s;" title="Delete"
                                   onmouseover="this.style.background='#fecdd3'" onmouseout="this.style.background='#fff1f2'">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            </div><!-- /.table-responsive -->
        </div><!-- /.mobile-card-table-wrap -->

        <?php if ($total_pages > 1): ?>
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-top:16px;background:var(--white);border:var(--border);border-radius:12px;padding:10px 16px;box-shadow:0 1px 4px rgba(0,0,0,0.04);">
            <div style="font-size:0.78rem;color:var(--gray-400);font-weight:600;">
                Showing <strong style="color:var(--gray-600);"><?php echo number_format($offset + 1); ?>–<?php echo number_format(min($offset + $per_page, $total_count)); ?></strong>
                of <strong style="color:var(--gray-600);"><?php echo number_format($total_count); ?></strong> appointments
            </div>
            <div style="display:flex;gap:5px;align-items:center;">
                <?php if ($page > 1): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page - 1; ?>" style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:8px;border:1.5px solid var(--gray-200);background:var(--white);color:var(--gray-600);font-size:0.78rem;font-weight:600;text-decoration:none;transition:all 0.12s;" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background='var(--white)'">
                    <i class="bi bi-chevron-left"></i> Prev
                </a>
                <?php endif; ?>
                <?php for ($pg = max(1, $page - 2); $pg <= min($total_pages, $page + 2); $pg++): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $pg; ?>" style="display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;border-radius:8px;font-size:0.82rem;font-weight:700;text-decoration:none;transition:all 0.12s;<?php echo $pg === $page ? 'background:linear-gradient(135deg,#2563eb,#60a5fa);color:var(--white);border:none;box-shadow:0 2px 8px rgba(37,99,235,0.3);' : 'border:1.5px solid var(--gray-200);background:var(--white);color:var(--gray-600);'; ?>"><?php echo $pg; ?></a>
                <?php endfor; ?>
                <?php if ($page < $total_pages): ?>
                <a href="list.php?<?php echo $filter_qs; ?>page=<?php echo $page + 1; ?>" style="display:inline-flex;align-items:center;gap:4px;padding:6px 12px;border-radius:8px;border:1.5px solid var(--gray-200);background:var(--white);color:var(--gray-600);font-size:0.78rem;font-weight:600;text-decoration:none;transition:all 0.12s;" onmouseover="this.style.background='var(--gray-100)'" onmouseout="this.style.background='var(--white)'">
                    Next <i class="bi bi-chevron-right"></i>
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Status Update Modal -->
<div class="modal fade" id="statusModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Update Appointment Status</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="appt_id">
                <label class="form-label">New Status</label>
                <select id="new_status" class="form-select">
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="no-show">No-show</option>
                </select>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary btn-sm" onclick="saveStatus()">Save</button>
            </div>
        </div>
    </div>
</div>

<!-- Confirm Appointment Modal -->
<div class="modal fade" id="confirmApptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:var(--blue-600);"><i class="bi bi-check-circle-fill"></i> Confirm Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;">
                Confirm appointment <strong id="confirmApptCode"></strong> for <strong id="confirmApptPatient"></strong>?
                <div style="margin-top:10px;padding:10px;background:var(--blue-50);border-radius:6px;font-size:0.78rem;color:var(--blue-600);">
                    <i class="bi bi-info-circle-fill"></i> This will mark the appointment as <strong>Confirmed</strong> and notify the patient.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="doConfirmAppt()">
                    <i class="bi bi-check-lg"></i> Yes, Confirm
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Appointment Modal -->
<div class="modal fade" id="deleteApptModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title" style="color:var(--danger);"><i class="bi bi-exclamation-triangle-fill"></i> Delete Appointment</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="font-size:0.875rem;">
                Permanently delete appointment <strong id="deleteApptCode"></strong>?
                <div style="margin-top:10px;padding:10px;background:var(--danger-bg);border-radius:6px;font-size:0.78rem;color:var(--danger);">
                    <i class="bi bi-exclamation-triangle-fill"></i> <strong>Warning:</strong> This will permanently delete the appointment and its payment records. This cannot be undone.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">No, Keep It</button>
                <button type="button" class="btn btn-sm btn-danger" onclick="doDeleteAppt()">
                    <i class="bi bi-trash"></i> Yes, Delete It
                </button>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
<script>
var statusModal = new bootstrap.Modal(document.getElementById('statusModal'));
var deleteApptModal = new bootstrap.Modal(document.getElementById('deleteApptModal'));
var deleteApptId = null;

function updateStatus(id, btn) {
    document.getElementById('appt_id').value = id;
    statusModal.show();
}

function saveStatus() {
    var id     = document.getElementById('appt_id').value;
    var status = document.getElementById('new_status').value;
    fetch('<?php echo BASE_URL; ?>api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: id, status: status })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') { statusModal.hide(); location.reload(); }
        else alert('Error: ' + data.message);
    });
}

function confirmDeleteAppt(id, code) {
    deleteApptId = id;
    document.getElementById('deleteApptCode').textContent = code;
    deleteApptModal.show();
}

function doDeleteAppt() {
    fetch('<?php echo BASE_URL; ?>api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete_appointment', id: deleteApptId })
    })
    .then(res => res.json())
    .then(data => {
        deleteApptModal.hide();
        if (data.status === 'success') location.reload();
        else alert('Error: ' + data.message);
    });
}

// Confirm appointment modal
var confirmApptModal = new bootstrap.Modal(document.getElementById('confirmApptModal'));
var pendingConfirmId = null;
function openConfirmModal(id, code, patient) {
    pendingConfirmId = id;
    document.getElementById('confirmApptCode').textContent  = code;
    document.getElementById('confirmApptPatient').textContent = patient;
    confirmApptModal.show();
}
function doConfirmAppt() {
    if (!pendingConfirmId) return;
    confirmApptModal.hide();
    fetch('<?php echo BASE_URL; ?>api/appointments.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'update_status', id: pendingConfirmId, status: 'confirmed' })
    })
    .then(res => res.json())
    .then(data => {
        if (data.status === 'success') location.reload();
        else alert('Error: ' + data.message);
    });
}
</script>


<div class="drawer-overlay" id="drawerOverlay" onclick="closeWalkinDrawer()"></div>

<div class="drawer-panel" id="walkinDrawer">
    <div class="drawer-resize-handle" id="drawerResizeHandle" title="Drag to resize"></div>
    <div class="drawer-head">
        <div>
            <h6><i class="bi bi-person-walking"></i> New Appointment</h6>
            <p id="drawerSubtitle">Register a new patient — today or advance booking</p>
        </div>
        <button class="drawer-close" onclick="closeWalkinDrawer()">✕</button>
    </div>
    <div class="drawer-slot-bar" id="drawerSlotBar">
        <span style="color:var(--gray-400);">Loading slot info...</span>
    </div>
    <div id="drawerAlert" style="display:none;margin:14px 22px 0;"></div>
    <div class="drawer-body">
        <form id="walkinDrawerForm" autocomplete="off">
            <input type="hidden" name="_ajax" value="1">
            <input type="hidden" name="existing_patient_id" id="drawerExistingPatientId" value="">
            <div class="row g-3">

                <!-- Date -->
                <div class="col-12">
                    <label class="form-label" style="font-weight:600;">Appointment Date <span style="color:var(--danger)">*</span></label>
                    <input type="date" name="appointment_date" id="drawerDate" class="form-control"
                        min="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
                    <div style="font-size:0.72rem;color:var(--gray-500);margin-top:4px;">Today = walk-in. Future date = advance booking.</div>
                </div>

                <!-- Patient search -->
                <div class="col-12">
                    <label class="form-label" style="font-weight:600;">Patient Search</label>
                    <div style="position:relative;">
                        <input type="text" id="drawerPatientSearch" class="form-control"
                            placeholder="Search by name or phone to find returning patient…"
                            autocomplete="off" oninput="searchPatient(this.value)">
                        <div id="drawerPatientResults" style="display:none;position:absolute;left:0;right:0;top:100%;z-index:500;background:var(--white);border:var(--border);border-top:none;border-radius:0 0 8px 8px;box-shadow:0 6px 20px rgba(0,0,0,0.1);max-height:200px;overflow-y:auto;"></div>
                    </div>
                    <div id="drawerPatientSelected" style="display:none;margin-top:7px;background:var(--blue-50);border:1px solid var(--blue-200);border-radius:8px;padding:8px 12px;font-size:0.82rem;display:flex;align-items:center;gap:8px;">
                        <i class="bi bi-person-check-fill" style="color:var(--blue-500);"></i>
                        <span id="drawerPatientSelectedName" style="font-weight:600;flex:1;"></span>
                        <button type="button" onclick="clearPatientSelection()" style="background:none;border:none;color:var(--gray-400);cursor:pointer;font-size:0.75rem;">✕ New patient</button>
                    </div>
                    <div style="font-size:0.72rem;color:var(--gray-400);margin-top:4px;">Leave blank to register a new patient.</div>
                </div>

                <!-- Name fields (hidden if returning patient selected) -->
                <div class="col-6" id="drawerFirstNameWrap">
                    <label class="form-label">First Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="first_name" id="drawerFirstName" class="form-control" placeholder="e.g. Juan" required>
                </div>
                <div class="col-6" id="drawerLastNameWrap">
                    <label class="form-label">Last Name <span style="color:var(--danger)">*</span></label>
                    <input type="text" name="last_name" id="drawerLastName" class="form-control" placeholder="e.g. dela Cruz" required>
                </div>
                <div class="col-12" id="drawerPhoneWrap">
                    <label class="form-label">Phone <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <input type="text" name="phone" id="drawerPhone" class="form-control" placeholder="09XXXXXXXXX" maxlength="13">
                </div>

                <div class="col-12">
                    <label class="form-label">Service</label>
                    <select name="service_id" class="form-select">
                        <option value="">— No service selected —</option>
                        <?php foreach ($walkin_services as $sv): ?>
                        <option value="<?php echo $sv['id']; ?>"><?php echo e($sv['service_name']); ?> — ₱<?php echo number_format($sv['price'],2); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <label class="form-label">Doctor <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <select name="doctor_id" id="drawerDoctorSelect" class="form-select">
                        <option value="">Any Available Doctor</option>
                        <?php
                        $today_abbr  = strtolower(substr(date('l'), 0, 3));
                        $all_docs_dw = $conn->query("SELECT id, full_name, specialization, schedule_days FROM doctors WHERE is_active = 1 ORDER BY full_name ASC")->fetch_all(MYSQLI_ASSOC);
                        foreach ($all_docs_dw as $d):
                            $ddays = array_map('trim', explode(',', $d['schedule_days'] ?? ''));
                            if (!in_array($today_abbr, $ddays)) continue;
                        ?>
                        <option value="<?php echo $d['id']; ?>"><?php echo e($d['full_name']); ?><?php if ($d['specialization']): ?> — <?php echo e($d['specialization']); ?><?php endif; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div id="drawerDoctorNote" style="font-size:0.72rem;color:var(--gray-400);margin-top:3px;">Showing doctors available today.</div>
                </div>

                <!-- Time slot picker — shown for ALL dates including today -->
                <div class="col-12" id="drawerSlotPickerWrap">
                    <label class="form-label">Preferred Time <span style="color:var(--danger)" id="drawerTimeRequired">*</span> <span id="drawerTimeOptionalNote" style="font-size:0.72rem;color:var(--gray-400);font-weight:400;display:none;">(optional — leave blank to auto-assign)</span></label>
                    <select name="selected_time" id="drawerSlotSelect" class="form-select">
                        <option value="">— Loading slots… —</option>
                    </select>
                    <div id="drawerSlotNote" style="font-size:0.72rem;color:var(--gray-400);margin-top:3px;"></div>
                </div>

                <div class="col-12">
                    <label class="form-label">Notes <span style="font-size:0.72rem;color:var(--gray-400);font-weight:400;">(optional)</span></label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Chief complaint or remarks…" maxlength="500"></textarea>
                </div>
            </div>
        </form>
    </div>
    <div class="drawer-foot">
        <button type="button" class="btn btn-success" id="walkinSubmitBtn" onclick="submitWalkin()">
            <i class="bi bi-person-check-fill"></i> <span id="walkinBtnLabel">Register Patient</span>
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="closeWalkinDrawer()">Cancel</button>
    </div>
</div>

<div id="walkinToast" style="display:none;position:fixed;bottom:28px;right:28px;z-index:2000;background:var(--white);border:1.5px solid var(--success-border);border-radius:12px;padding:14px 20px;box-shadow:0 8px 24px rgba(0,0,0,0.12);max-width:360px;animation:slideToast 0.3s ease;">
    <div style="font-weight:700;margin-bottom:4px;" id="walkinToastTitle"></div>
    <div style="font-size:0.85rem;color:var(--gray-600);" id="walkinToastMsg"></div>
</div>

<script>
var _today = '<?php echo date('Y-m-d'); ?>';
var _baseUrl = '<?php echo BASE_URL; ?>';
var _patientSearchTimer = null;

function openWalkinDrawer() {
    document.getElementById('walkinDrawer').classList.add('open');
    document.getElementById('drawerOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('walkinDrawerForm').reset();
    document.getElementById('drawerDate').value = _today;
    document.getElementById('walkinBtnLabel').textContent = 'Register Patient';
    document.getElementById('drawerExistingPatientId').value = '';
    document.getElementById('drawerPatientSearch').value = '';
    document.getElementById('drawerPatientResults').style.display = 'none';
    document.getElementById('drawerPatientSelected').style.display = 'none';
    showNewPatientFields(true);
    hideDrawerAlert();
    loadDrawerDateData(_today);
}
function closeWalkinDrawer() {
    document.getElementById('walkinDrawer').classList.remove('open');
    document.getElementById('drawerOverlay').classList.remove('open');
    document.body.style.overflow = '';
}

// ── Patient search ────────────────────────────────────────────────────────
function searchPatient(q) {
    clearTimeout(_patientSearchTimer);
    var results = document.getElementById('drawerPatientResults');
    if (q.length < 2) { results.style.display = 'none'; return; }
    _patientSearchTimer = setTimeout(function() {
        fetch(_baseUrl + 'modules/walkin/add.php?action=search_patient&q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
            if (!data.patients || data.patients.length === 0) {
                results.innerHTML = '<div style="padding:10px 14px;font-size:0.82rem;color:var(--gray-400);">No existing patients found — will register as new.</div>';
            } else {
                results.innerHTML = data.patients.map(p => {
                    var name = p.first_name + ' ' + p.last_name;
                    var phone = p.phone || 'No phone';
                    var appts = p.appt_count + ' appt' + (p.appt_count != 1 ? 's' : '');
                    return '<div class="patient-result-item" style="padding:9px 14px;cursor:pointer;border-bottom:1px solid var(--gray-100);font-size:0.83rem;transition:background 0.12s;" '
                        + 'onmouseenter="this.style.background=\'var(--gray-50)\'" onmouseleave="this.style.background=\'\'" '
                        + 'onclick="selectPatient(' + p.id + ',\'' + name.replace(/'/g,"\\'") + '\',\'' + p.patient_code + '\',\'' + phone.replace(/'/g,"\\'") + '\')">'
                        + '<div style="font-weight:600;">' + name + ' <span style="font-size:0.72rem;color:var(--gray-400);">(' + p.patient_code + ')</span></div>'
                        + '<div style="color:var(--gray-500);font-size:0.75rem;">' + phone + ' · ' + appts + '</div>'
                        + '</div>';
                }).join('');
            }
            results.style.display = 'block';
        });
    }, 280);
}

function selectPatient(id, name, code, phone) {
    document.getElementById('drawerExistingPatientId').value = id;
    document.getElementById('drawerPatientResults').style.display = 'none';
    document.getElementById('drawerPatientSearch').value = '';
    document.getElementById('drawerPatientSelectedName').textContent = name + ' (' + code + ') · ' + phone;
    document.getElementById('drawerPatientSelected').style.display = 'flex';
    showNewPatientFields(false);
}

function clearPatientSelection() {
    document.getElementById('drawerExistingPatientId').value = '';
    document.getElementById('drawerPatientSelected').style.display = 'none';
    showNewPatientFields(true);
}

function showNewPatientFields(show) {
    var fn = document.getElementById('drawerFirstNameWrap');
    var ln = document.getElementById('drawerLastNameWrap');
    var ph = document.getElementById('drawerPhoneWrap');
    var fn_input = document.getElementById('drawerFirstName');
    var ln_input = document.getElementById('drawerLastName');
    var ph_input = document.getElementById('drawerPhone');
    
    // Show/hide field wrappers
    [fn, ln, ph].forEach(el => { if(el) el.style.display = show ? '' : 'none'; });
    
    // Toggle required attribute
    if (fn_input) fn_input.required = show;
    if (ln_input) ln_input.required = show;
    if (ph_input) ph_input.required = show;
    
    // Clear fields when hiding (existing patient doesn't need these filled)
    if (!show) {
        if (fn_input) fn_input.value = '';
        if (ln_input) ln_input.value = '';
        if (ph_input) ph_input.value = '';
    }
}

// Close patient results on outside click
document.addEventListener('click', function(e) {
    var res = document.getElementById('drawerPatientResults');
    var inp = document.getElementById('drawerPatientSearch');
    if (res && inp && !inp.contains(e.target) && !res.contains(e.target)) {
        res.style.display = 'none';
    }
});

// ── Load slots for selected date ──────────────────────────────────────────
function loadDrawerDateData(date) {
    var bar        = document.getElementById('drawerSlotBar');
    var docSelect  = document.getElementById('drawerDoctorSelect');
    var slotSelect = document.getElementById('drawerSlotSelect');
    var slotNote   = document.getElementById('drawerSlotNote');
    var docNote    = document.getElementById('drawerDoctorNote');
    var timeReq    = document.getElementById('drawerTimeRequired');
    var timeOpt    = document.getElementById('drawerTimeOptionalNote');
    var isToday    = (date === _today);

    bar.innerHTML = '<span style="color:var(--gray-400);">Checking schedule…</span>';

    fetch(_baseUrl + 'modules/walkin/add.php?action=get_slots&date=' + encodeURIComponent(date))
    .then(r => r.json())
    .then(data => {
        if (data.status !== 'success') {
            bar.innerHTML = '<i class="bi bi-exclamation-triangle-fill" style="color:#f59e0b;"></i> ' + (data.message || 'Could not load schedule.');
            return;
        }
        var sd = data.slot_data, docs = data.doctors || [];

        // Slot bar message
        if (sd.is_closed) {
            bar.innerHTML = '<i class="bi bi-calendar-x" style="color:#f59e0b;"></i> <strong>' + sd.reason + '</strong>';
        } else {
            var free = (sd.total_slots||0) - (sd.booked_count||0);
            var nextPart = sd.slot ? ' · Next: <strong style="color:var(--primary);">' + sd.label + '</strong>' : '';
            bar.innerHTML = '<i class="bi bi-calendar-check" style="color:var(--primary);"></i> '
                + data.day_name + ' — <strong style="color:var(--primary);">' + free + ' slot' + (free!==1?'s':'') + ' free</strong>' + nextPart;
        }

        // Doctor dropdown
        var savedDoc = docSelect.value;
        docSelect.innerHTML = '<option value="">Any Available Doctor</option>';
        if (docs.length === 0) {
            docNote.textContent = 'No doctors scheduled on this day.';
        } else {
            docs.forEach(function(d) {
                var opt = document.createElement('option');
                opt.value = d.id;
                opt.textContent = d.full_name + (d.specialization ? ' — ' + d.specialization : '');
                if (String(d.id) === String(savedDoc)) opt.selected = true;
                docSelect.appendChild(opt);
            });
            docNote.textContent = docs.length + ' doctor' + (docs.length!==1?'s':'') + ' available on this day.';
        }

        // Slot picker — ALWAYS visible for all dates
        document.getElementById('walkinBtnLabel').textContent = isToday ? 'Register Patient' : 'Book Appointment';
        slotSelect.innerHTML = '<option value="">' + (isToday ? '— Auto-assign next slot —' : '— Choose a time slot —') + '</option>';

        if (isToday) {
            // Today: slot is optional — blank = auto-assign
            if (timeReq) timeReq.style.display = 'none';
            if (timeOpt) timeOpt.style.display = '';
            slotSelect.removeAttribute('required');
        } else {
            // Future: slot is required
            if (timeReq) timeReq.style.display = '';
            if (timeOpt) timeOpt.style.display = 'none';
            slotSelect.setAttribute('required', 'required');
        }

        if (sd.is_closed) {
            slotSelect.innerHTML = '<option value="" disabled>Clinic closed this day</option>';
            slotNote.textContent = '';
        } else {
            var fc = 0;
            (sd.all_slots || []).forEach(function(s) {
                if (!s.taken && !s.past) {
                    var opt = document.createElement('option');
                    opt.value = s.time;
                    opt.textContent = s.label;
                    // Mark the next auto-assign slot
                    if (isToday && sd.slot && s.time + ':00' === sd.slot) {
                        opt.textContent = s.label + ' ← auto';
                    }
                    slotSelect.appendChild(opt);
                    fc++;
                }
            });
            slotNote.textContent = fc > 0 ? fc + ' available slot' + (fc!==1?'s':'') + '.' : 'No available slots.';
            if (fc === 0) slotSelect.innerHTML = '<option value="" disabled>No slots available</option>';
        }
    })
    .catch(() => {
        bar.innerHTML = '<span style="color:var(--gray-400);">Could not load schedule.</span>';
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var di = document.getElementById('drawerDate');
    if (di) di.addEventListener('change', function() { if (this.value) loadDrawerDateData(this.value); });
});

function showDrawerAlert(type, msg) {
    var el = document.getElementById('drawerAlert');
    el.style.display = 'block';
    el.innerHTML = '<div class="alert alert-' + type + '" style="margin:0;font-size:0.85rem;"><i class="bi bi-' + (type==='danger'?'x-circle-fill':'check-circle-fill') + '"></i> ' + msg + '</div>';
}
function hideDrawerAlert() { document.getElementById('drawerAlert').style.display = 'none'; }

function submitWalkin() {
    var form    = document.getElementById('walkinDrawerForm');
    var btn     = document.getElementById('walkinSubmitBtn');
    var existingId = document.getElementById('drawerExistingPatientId').value;
    var date    = form.querySelector('[name=appointment_date]').value || _today;
    var isToday = (date === _today);
    var sv      = form.querySelector('[name=selected_time]') ? form.querySelector('[name=selected_time]').value : '';

    // Validate: need either existing patient OR new patient name
    if (!existingId) {
        var first = form.querySelector('[name=first_name]').value.trim();
        var last  = form.querySelector('[name=last_name]').value.trim();
        if (!first || !last) { showDrawerAlert('danger', 'Enter a patient name, or search and select an existing patient.'); return; }
    }

    // Future date requires a time slot
    if (!isToday && !sv) { showDrawerAlert('danger', 'Please select a time slot for the advance booking.'); return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> ' + (isToday ? 'Registering...' : 'Booking...');
    hideDrawerAlert();

    fetch(_baseUrl + 'modules/walkin/add.php', { method: 'POST', body: new FormData(form) })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-person-check-fill"></i> <span id="walkinBtnLabel">' + (isToday ? 'Register Patient' : 'Book Appointment') + '</span>';
        if (res.status === 'success') {
            location.reload();
            var appt = res.appt || {};
            var timeLabel = appt.time ? new Date('1970-01-01T' + appt.time).toLocaleTimeString('en-PH',{hour:'2-digit',minute:'2-digit',hour12:true}) : '';
            var apptDate  = appt.date || date;
            var dateLabel = new Date(apptDate + 'T00:00:00').toLocaleDateString('en-PH',{month:'short',day:'2-digit',year:'numeric'});

            // Inject row
            var tbody = document.querySelector('#appointmentsTable tbody');
            if (tbody) {
                var placeholder = tbody.querySelector('td[colspan]');
                if (placeholder) placeholder.closest('tr').remove();
                var confirmBtn = '<button class="btn btn-sm btn-outline-primary" onclick="openConfirmModal(' + (appt.appt_id||'') + ', \'' + (appt.appt_code||'') + '\', \'' + (appt.patient_name||'').replace(/'/g,"\\'") + '\')" title="Confirm Appointment"><i class="bi bi-check-lg"></i> Confirm</button>';
                var printBtn   = '<a href="' + _baseUrl + 'modules/print/appointment_slip.php?id=' + (appt.appt_id||'') + '" target="_blank" class="btn btn-sm btn-outline-secondary" title="Print Slip"><i class="bi bi-printer"></i></a>';
                var editBtn    = '<button class="btn btn-sm btn-outline-secondary" title="Edit"><i class="bi bi-pencil-square"></i></button>';
                var delBtn     = '<button class="btn btn-sm btn-outline-danger" onclick="confirmDeleteAppt(' + (appt.appt_id||'') + ', \'' + (appt.appt_code||'') + '\')" title="Delete"><i class="bi bi-trash"></i></button>';
                var tr = document.createElement('tr');
                tr.style.cssText = 'background:var(--warning-bg);transition:background 3s ease;';
        
                tr.innerHTML =
                    '<td style="font-weight:600;color:var(--blue-500);font-size:0.8rem;">' + (appt.appt_code||'—') + '</td>' +
                    '<td style="font-weight:500;">' + (appt.patient_name||'—') + returningBadge + '<br><small style="color:var(--gray-400);">' + (appt.patient_code||'') + '</small></td>' +
                    '<td style="color:var(--gray-500);font-size:0.82rem;">' + (appt.service||'—') + '</td>' +
                    '<td><span class="badge rounded-pill" style="background:var(--primary);opacity:0.85;font-size:0.72rem;">' + (appt.doctor_name && appt.doctor_name!=='—' ? appt.doctor_name : 'Any') + '</span></td>' +
                    '<td style="font-size:0.82rem;">' + dateLabel + '</td>' +
                    '<td style="font-size:0.82rem;">' + timeLabel + '</td>' +
                    '<td><span class="badge bg-warning text-dark">Pending</span></td>' +
                    '<td><div style="display:flex;gap:5px;">' + confirmBtn + printBtn + editBtn + delBtn + '</div></td>';
                tbody.insertBefore(tr, tbody.firstChild);
                setTimeout(() => { tr.style.background = ''; }, 3000);
            }

            // Toast
            var isReturning = res.is_returning;
            var toastTitle = isReturning ? '📋 Returning Patient Booked!' : (isToday ? '✅ Patient Registered!' : '📅 Advance Booking Saved!');
            var toastMsg   = '<strong>' + (appt.patient_name||'') + '</strong> (' + (appt.patient_code||'') + ')<br>';
            toastMsg += isReturning ? 'Existing record used — no duplicate created.<br>' : '';
            toastMsg += 'Appt: <strong>' + (appt.appt_code||'') + '</strong> · ' + dateLabel + ' at <strong>' + timeLabel + '</strong>';
            document.getElementById('walkinToastTitle').textContent = toastTitle;
            document.getElementById('walkinToastMsg').innerHTML = toastMsg;
            var toast = document.getElementById('walkinToast');
            toast.style.display = 'block';
            setTimeout(() => { toast.style.display = 'none'; }, 6000);
            form.reset();
        } else {
            showDrawerAlert('danger', res.message || 'Something went wrong. Please try again.');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-person-check-fill"></i> <span id="walkinBtnLabel">Register Patient</span>';
        showDrawerAlert('danger', 'Network error. Please try again.');
    });
}

<?php if ($auto_open_walkin): ?>
document.addEventListener('DOMContentLoaded', function() { openWalkinDrawer(); });
<?php endif; ?>

// ── Resizable Drawer ──────────────────────────────────────────────────────
(function() {
    var handle  = document.getElementById('drawerResizeHandle');
    var drawer  = document.getElementById('walkinDrawer');
    var isDragging = false;
    var startX, startWidth;

    // Restore saved width
    var savedWidth = localStorage.getItem('drawerWidth');
    if (savedWidth) drawer.style.width = savedWidth + 'px';

    handle.addEventListener('mousedown', function(e) {
        isDragging = true;
        startX     = e.clientX;
        startWidth = drawer.offsetWidth;
        handle.classList.add('dragging');
        document.body.style.cursor   = 'ew-resize';
        document.body.style.userSelect = 'none';
        e.preventDefault();
    });

    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        var diff     = startX - e.clientX; // drag left = wider
        var newWidth = Math.min(860, Math.max(340, startWidth + diff));
        drawer.style.width = newWidth + 'px';
    });

    document.addEventListener('mouseup', function() {
        if (!isDragging) return;
        isDragging = false;
        handle.classList.remove('dragging');
        document.body.style.cursor    = '';
        document.body.style.userSelect = '';
        localStorage.setItem('drawerWidth', drawer.offsetWidth);
    });

    // Double-click handle to reset to default width
    handle.addEventListener('dblclick', function() {
        drawer.style.width = '440px';
        localStorage.removeItem('drawerWidth');
    });
})();
</script>
</body>
</html>
