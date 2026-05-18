<?php
// Record a dental treatment for a patient and mark appointment as completed.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

// Variables from auth.php for static analysis
/** @var int $current_user_id */
/** @var string $current_user_name */
/** @var string $current_user_role */

$page_title = 'Add Dental Record';
$error   = '';
$success = '';

$pre_patient_id     = intval($_GET['patient_id'] ?? 0);
$pre_appointment_id = intval($_GET['appointment_id'] ?? 0);

$patients = $conn->query("SELECT id, patient_code, first_name, last_name FROM patients WHERE is_active = 1 ORDER BY last_name ASC")->fetch_all(MYSQLI_ASSOC);
$services = $conn->query("SELECT id, service_name FROM services WHERE is_active = 1 ORDER BY service_name ASC")->fetch_all(MYSQLI_ASSOC);

// Pre-load appointment details when coming from Check-in
$pre_appointment = null;
$pre_service_id  = 0;
if ($pre_appointment_id) {
    $pa_stmt = $conn->prepare("
        SELECT a.*, s.id as service_id, s.service_name
        FROM appointments a
        LEFT JOIN services s ON a.service_id = s.id
        WHERE a.id = ? LIMIT 1
    ");
    $pa_stmt->bind_param('i', $pre_appointment_id);
    $pa_stmt->execute();
    $pre_appointment = $pa_stmt->get_result()->fetch_assoc();
    $pa_stmt->close();
    if ($pre_appointment) $pre_service_id = intval($pre_appointment['service_id'] ?? 0);
}

$pre_patient = null;
if ($pre_patient_id) {
    $pp_stmt = $conn->prepare("SELECT first_name, last_name, allergies, medical_notes, illness_history, blood_type, phone FROM patients WHERE id = ? LIMIT 1");
    $pp_stmt->bind_param('i', $pre_patient_id);
    $pp_stmt->execute();
    $pre_patient = $pp_stmt->get_result()->fetch_assoc();
    $pp_stmt->close();
}

// Patient history: last 5 treatments
$past_treatments = [];
if ($pre_patient_id) {
    $pt_stmt = $conn->prepare("
        SELECT dr.visit_date, dr.treatment_done, dr.diagnosis, dr.tooth_number,
               dr.tooth_status, dr.medications_prescribed, dr.next_visit_notes,
               s.service_name
        FROM dental_records dr
        LEFT JOIN services s ON dr.service_id = s.id
        WHERE dr.patient_id = ?
        ORDER BY dr.visit_date DESC, dr.id DESC
        LIMIT 5
    ");
    $pt_stmt->bind_param('i', $pre_patient_id);
    $pt_stmt->execute();
    $past_treatments = $pt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $pt_stmt->close();
}

// Outstanding balance
$outstanding = 0;
if ($pre_patient_id) {
    $bal_stmt = $conn->prepare("SELECT COALESCE(SUM(amount_due - amount_paid),0) as bal FROM bills WHERE patient_id = ? AND status != 'paid'");
    $bal_stmt->bind_param('i', $pre_patient_id);
    $bal_stmt->execute();
    $outstanding = floatval($bal_stmt->get_result()->fetch_assoc()['bal']);
    $bal_stmt->close();
}

$patient_appointments = [];
if ($pre_patient_id) {
    $patient_appointments = $conn->query("
        SELECT id, appointment_code, appointment_date FROM appointments
        WHERE patient_id = $pre_patient_id AND status IN ('pending','confirmed','completed')
        ORDER BY appointment_date DESC
    ")->fetch_all(MYSQLI_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $patient_id     = intval($_POST['patient_id'] ?? 0);
    $appointment_id = intval($_POST['appointment_id'] ?? 0) ?: null;
    $service_id     = intval($_POST['service_id'] ?? 0) ?: null;
    $tooth_number   = trim($_POST['tooth_number'] ?? '');
    // Safety: DB column is VARCHAR(255) after fix — trim to 255 chars just in case
    if (strlen($tooth_number) > 255) {
        $tooth_number = substr($tooth_number, 0, 255);
    }
    // BUG FIX #1: Capture tooth_status from the new form field
    $tooth_status   = trim($_POST['tooth_status'] ?? 'normal');
    // Validate tooth_status is a valid ENUM value
    $valid_statuses = ['normal','caries','filling','extraction','missing','crown','rootcanal','bridge','implant','denture'];
    if (!in_array($tooth_status, $valid_statuses)) $tooth_status = 'normal';

    $chief_complaint = trim($_POST['chief_complaint'] ?? '');
    $diagnosis       = trim($_POST['diagnosis'] ?? '');
    $treatment_done  = trim($_POST['treatment_done'] ?? '');
    $medications     = trim($_POST['medications_prescribed'] ?? '');
    $next_visit      = trim($_POST['next_visit_notes'] ?? '');
    $visit_date      = $_POST['visit_date'] ?? date('Y-m-d');

    if (!$patient_id || empty($treatment_done)) {
        $error = 'Patient and treatment done are required.';
    } else {
        // BUG FIX #1 + #5: tooth_status added to INSERT, bind_param types corrected
        // BUG FIX #5: appointment_id and service_id are nullable ints — 'i' type handles
        //             PHP null correctly in MySQLi when value is explicitly null (not 0)
        $stmt = $conn->prepare("
            INSERT INTO dental_records
            (patient_id, appointment_id, service_id, tooth_number, tooth_status,
             chief_complaint, diagnosis, treatment_done, medications_prescribed,
             next_visit_notes, recorded_by, visit_date)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ");
        if (!$stmt) {
            $error = 'Database error: ' . $conn->error;
        } else {
            // 'i' × 3, 's' × 8, 'i' × 1, 's' × 1 = 12 params
            $stmt->bind_param(
                'iiisssssssis',
                $patient_id, $appointment_id, $service_id,
                $tooth_number, $tooth_status,
                $chief_complaint, $diagnosis, $treatment_done,
                $medications, $next_visit,
                $current_user_id, $visit_date
            );

            if ($stmt->execute()) {
                $new_id = $conn->insert_id;
                $stmt->close();

                if ($appointment_id) {
                    $upd = $conn->prepare("UPDATE appointments SET status = 'completed' WHERE id = ?");
                    $upd->bind_param('i', $appointment_id);
                    $upd->execute();
                    $upd->close();
                }

                log_action($conn, $current_user_id, $current_user_name, 'Added Dental Record', 'treatments', $new_id, "Patient ID: $patient_id | Visit: $visit_date | Status: $tooth_status");

                // Chain: after saving dental record → go to billing
                if ($patient_id) {
                    $billing_url = BASE_URL . "modules/billing/create.php?patient_id=$patient_id";
                    if ($appointment_id) $billing_url .= "&appointment_id=$appointment_id";
                    $billing_url .= "&from_treatment=1";
                    header("Location: $billing_url");
                    exit();
                }
                $success = 'Dental record saved successfully.';
            } else {
                $stmt->close();
                $error = 'Failed to save dental record. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Add Dental Record — mobile ── */
@media (max-width: 640px) {
    /* Workflow breadcrumb: scrollable on small phones */
    .workflow-breadcrumb {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        white-space: nowrap !important;
        padding-bottom: 4px !important;
    }
    /* Tooth chart: scrollable, teeth slightly smaller */
    #toothChartWrap {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
        padding: 12px 8px !important;
    }
    .tooth-btn {
        width: 28px !important;
        height: 28px !important;
        font-size: 0.58rem !important;
    }
    /* Tooth root indicators shrink too */
    #toothChartWrap [style*="height:6px"] {
        width: 28px !important;
    }
    /* Primary teeth circle buttons */
    #toothChartWrap [style*="border-radius:50%"].tooth-btn {
        width: 26px !important;
        height: 26px !important;
    }
    /* Condition preview: full width */
    #conditionPreview {
        width: 100% !important;
        justify-content: center !important;
    }
    /* Save + Cancel buttons: stack */
    .dental-form-actions {
        flex-direction: column !important;
    }
    .dental-form-actions > * {
        width: 100% !important;
        text-align: center !important;
        justify-content: center !important;
    }
    /* Medical alert grid: single column */
    .medical-alert-grid {
        grid-template-columns: 1fr !important;
    }
}
</style>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Workflow breadcrumb -->
        <?php if ($pre_appointment_id): ?>
        <div class="workflow-breadcrumb" style="background:var(--blue-50);border:1px solid var(--blue-100);border-radius:8px;padding:12px 16px;margin-bottom:20px;font-size:0.82rem;">
            <strong style="color:var(--blue-600);">Patient Flow:</strong>
            <span style="color:var(--gray-500);">Appointment</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-500);">Check-in</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <strong style="color:var(--blue-600);">Record Treatment</strong>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-400);">Create Bill</span>
            <i class="bi bi-arrow-right" style="color:var(--gray-400);margin:0 6px;"></i>
            <span style="color:var(--gray-400);">Done</span>
        </div>
        <?php endif; ?>

        <!-- =====================================================================
             BUG FIX #3 — Patient Medical Context Panel
             Shows allergies, medical notes, illness history as a warning banner.
             Only renders if patient is pre-selected AND has relevant data.
        ===================================================================== -->
        <?php if ($pre_patient && ($pre_patient['allergies'] || $pre_patient['medical_notes'] || $pre_patient['illness_history'] || $pre_patient['blood_type'])): ?>
        <div style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:10px;padding:14px 18px;margin-bottom:20px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:10px;">
                <i class="bi bi-shield-exclamation" style="font-size:1.1rem;color:#d97706;"></i>
                <strong style="font-size:0.85rem;color:#92400e;">
                    Medical Alert — <?php echo e($pre_patient['last_name'].', '.$pre_patient['first_name']); ?>
                </strong>
                <?php if ($pre_patient['blood_type']): ?>
                <span style="margin-left:auto;background:#dc2626;color:#fff;font-size:0.7rem;font-weight:700;padding:2px 8px;border-radius:20px;">
                    <?php echo e($pre_patient['blood_type']); ?>
                </span>
                <?php endif; ?>
            </div>
            <div class="medical-alert-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px;">
                <?php if ($pre_patient['allergies']): ?>
                <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:7px;padding:8px 12px;">
                    <div style="font-size:0.7rem;font-weight:700;color:#dc2626;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">
                        <i class="bi bi-exclamation-triangle-fill"></i> Allergies
                    </div>
                    <div style="font-size:0.8rem;color:#7f1d1d;"><?php echo e($pre_patient['allergies']); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($pre_patient['medical_notes']): ?>
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:7px;padding:8px 12px;">
                    <div style="font-size:0.7rem;font-weight:700;color:#1d4ed8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">
                        <i class="bi bi-heart-pulse-fill"></i> Medical Notes
                    </div>
                    <div style="font-size:0.8rem;color:#1e3a8a;"><?php echo e($pre_patient['medical_notes']); ?></div>
                </div>
                <?php endif; ?>
                <?php if ($pre_patient['illness_history']): ?>
                <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:7px;padding:8px 12px;">
                    <div style="font-size:0.7rem;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:3px;">
                        <i class="bi bi-clock-history"></i> Illness History
                    </div>
                    <div style="font-size:0.8rem;color:#14532d;"><?php echo e($pre_patient['illness_history']); ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="page-header">
            <div>
                <h5>Record Treatment</h5>
                <p style="font-size:0.82rem;color:var(--gray-500);">
                    Fill in what was done for the patient. After saving, you will be taken to billing.
                </p>
            </div>
            <a href="<?php echo $pre_appointment_id ? BASE_URL.'modules/appointments/list.php' : 'list.php'; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Appointments
            </a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><i class="bi bi-journal-medical" style="color:var(--blue-500)"></i> Treatment Information</div>
            <div class="card-body">
                <form method="POST">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Patient <span style="color:var(--danger)">*</span></label>
                            <select name="patient_id" id="patient_select" class="form-select" required>
                                <option value="">Select Patient</option>
                                <?php foreach ($patients as $p): ?>
                                    <option value="<?php echo $p['id']; ?>" <?php echo $pre_patient_id == $p['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($p['last_name'].', '.$p['first_name'].' ('.$p['patient_code'].')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Linked Appointment</label>
                            <select name="appointment_id" id="appt_select" class="form-select">
                                <option value="">None / Walk-in</option>
                                <?php foreach ($patient_appointments as $a): ?>
                                    <option value="<?php echo $a['id']; ?>" <?php echo $pre_appointment_id == $a['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a['appointment_code'].' — '.date('M d, Y', strtotime($a['appointment_date']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- =====================================================================
                             BUG FIX #2 — Service is no longer locked.
                             Pre-selects the appointment service but allows the dentist to change
                             it if the actual treatment differed from what was booked.
                        ===================================================================== -->
                        <div class="col-md-4">
                            <label class="form-label">Service
                                <?php if ($pre_appointment_id && $pre_service_id): ?>
                                <span style="font-size:0.72rem;color:var(--blue-500);font-weight:600;">
                                    <i class="bi bi-link-45deg"></i> Pre-filled from appointment — change if needed
                                </span>
                                <?php endif; ?>
                            </label>
                            <select name="service_id" class="form-select">
                                <option value="">Select Service</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?php echo $s['id']; ?>"
                                        <?php echo $pre_service_id == $s['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($s['service_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Visit Date</label>
                            <input type="date" name="visit_date" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Chief Complaint <span style="color:var(--gray-400);font-size:0.8rem;">(patient's own words)</span></label>
                            <input type="text" name="chief_complaint" class="form-control" placeholder="e.g. Masakit ang ngipin ko sa kanan, may butas — what the patient says when they walk in">
                        </div>

                        <!-- ===== INTERACTIVE TOOTH CHART ===== -->
                        <div class="col-md-12">
                            <label class="form-label">Tooth Chart <span style="color:var(--gray-400);font-size:0.8rem;">(click teeth to select — selected teeth appear in the field below)</span></label>
                            <div id="toothChartWrap" style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:18px 12px;user-select:none;">

                                <!-- Legend -->
                                <div style="text-align:center;font-size:0.72rem;color:var(--gray-500);margin-bottom:10px;font-family:'Outfit',sans-serif;">
                                    <span style="display:inline-block;width:18px;height:18px;background:var(--blue-500);border-radius:50%;margin-right:4px;vertical-align:middle;"></span> Selected &nbsp;
                                    <span style="display:inline-block;width:18px;height:18px;background:var(--gray-200);border-radius:50%;margin-right:4px;vertical-align:middle;border:1px solid var(--gray-300);"></span> Healthy
                                </div>

                                <!-- UPPER JAW -->
                                <div style="text-align:center;font-size:0.7rem;color:var(--gray-400);margin-bottom:3px;">UPPER JAW</div>
                                <div style="display:flex;justify-content:center;gap:4px;margin-bottom:2px;">
                                    <?php foreach ([18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28] as $tn): ?>
                                    <div class="tooth-btn" data-tooth="<?php echo $tn; ?>" title="Tooth <?php echo $tn; ?>" style="width:34px;height:34px;border-radius:6px 6px 14px 14px;background:var(--gray-200);border:1px solid var(--gray-300);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.65rem;color:var(--gray-600);font-weight:600;transition:all 0.15s;flex-shrink:0;"><?php echo $tn; ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <!-- upper root indicators -->
                                <div style="display:flex;justify-content:center;gap:4px;margin-bottom:8px;">
                                    <?php foreach ([18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28] as $tn): ?>
                                    <div style="width:34px;height:6px;background:linear-gradient(to bottom,var(--gray-300),transparent);border-radius:0 0 4px 4px;flex-shrink:0;"></div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Gum line -->
                                <div style="border-top:2px dashed var(--gray-300);margin:0 auto 8px;width:90%;position:relative;">
                                    <span style="position:absolute;top:-9px;left:50%;transform:translateX(-50%);background:var(--gray-50);padding:0 8px;font-size:0.65rem;color:var(--gray-400);">GUM LINE</span>
                                </div>

                                <!-- lower root indicators -->
                                <div style="display:flex;justify-content:center;gap:4px;margin-bottom:2px;">
                                    <?php foreach ([48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38] as $tn): ?>
                                    <div style="width:34px;height:6px;background:linear-gradient(to top,var(--gray-300),transparent);border-radius:4px 4px 0 0;flex-shrink:0;"></div>
                                    <?php endforeach; ?>
                                </div>
                                <!-- LOWER JAW -->
                                <div style="display:flex;justify-content:center;gap:4px;margin-bottom:4px;">
                                    <?php foreach ([48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38] as $tn): ?>
                                    <div class="tooth-btn" data-tooth="<?php echo $tn; ?>" title="Tooth <?php echo $tn; ?>" style="width:34px;height:34px;border-radius:14px 14px 6px 6px;background:var(--gray-200);border:1px solid var(--gray-300);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.65rem;color:var(--gray-600);font-weight:600;transition:all 0.15s;flex-shrink:0;"><?php echo $tn; ?></div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="text-align:center;font-size:0.7rem;color:var(--gray-400);margin-top:3px;">LOWER JAW</div>

                                <!-- Primary teeth -->
                                <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--gray-200);">
                                    <div style="text-align:center;font-size:0.7rem;color:var(--gray-400);margin-bottom:6px;">PRIMARY TEETH (DECIDUOUS)</div>
                                    <div style="display:flex;justify-content:center;gap:4px;flex-wrap:wrap;">
                                        <?php foreach (['55','54','53','52','51','61','62','63','64','65','85','84','83','82','81','71','72','73','74','75'] as $pt): ?>
                                        <div class="tooth-btn" data-tooth="<?php echo $pt; ?>" title="Primary tooth <?php echo $pt; ?>" style="width:30px;height:30px;border-radius:50%;background:var(--gray-200);border:1px solid var(--gray-300);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:0.6rem;color:var(--gray-600);font-weight:600;transition:all 0.15s;flex-shrink:0;"><?php echo $pt; ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div style="text-align:center;margin-top:12px;">
                                    <button type="button" id="clearTeeth" style="font-size:0.75rem;padding:4px 12px;background:none;border:1px solid var(--gray-300);border-radius:6px;cursor:pointer;color:var(--gray-500);">✕ Clear Selection</button>
                                </div>
                            </div>
                            <!-- Hidden input storing selected teeth -->
                            <input type="text" name="tooth_number" id="toothNumberInput" class="form-control mt-2" placeholder="Or type directly, e.g. 16, 21 — chart will sync" value="">
                            <small style="color:var(--gray-400);font-size:0.75rem;">Selected teeth update this field automatically. You can also type here manually.</small>
                        </div>

                        <!-- =====================================================================
                             BUG FIX #1 + #4 — Tooth Condition (tooth_status) selector.
                             This field was completely missing. Without it, every record saved
                             as 'normal' regardless of treatment, making the odontogram useless.
                             Selecting a tooth on the chart AND picking the condition here gives
                             the DB the full picture it needs for persistent history.
                        ===================================================================== -->
                        <div class="col-md-4">
                            <label class="form-label">
                                Tooth Condition
                                <span style="color:var(--gray-400);font-size:0.78rem;">(for selected teeth above)</span>
                            </label>
                            <select name="tooth_status" id="tooth_status_select" class="form-select">
                                <option value="normal">Normal / Healthy</option>
                                <option value="caries">Caries (Cavity)</option>
                                <option value="filling">Filling Done</option>
                                <option value="extraction">Extraction / Pulled</option>
                                <option value="missing">Already Missing</option>
                                <option value="crown">Crown Placed</option>
                                <option value="rootcanal">Root Canal Treated</option>
                                <option value="bridge">Bridge</option>
                                <option value="implant">Implant</option>
                                <option value="denture">Denture</option>
                            </select>
                            <small style="color:var(--gray-400);font-size:0.72rem;">
                                This records the condition of the tooth/teeth listed above in the chart.
                            </small>
                        </div>
                        <!-- Condition preview badge — updates live as the select changes -->
                        <div class="col-md-8" style="display:flex;align-items:flex-end;padding-bottom:6px;">
                            <div id="conditionPreview" style="display:none;padding:7px 14px;border-radius:8px;font-size:0.8rem;font-weight:600;transition:all 0.2s;">
                            </div>
                        </div>

                        <div class="col-md-12">
                            <label class="form-label">Diagnosis</label>
                            <textarea name="diagnosis" class="form-control" rows="2" placeholder="Clinical findings..."></textarea>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Treatment Done <span style="color:var(--danger)">*</span></label>
                            <textarea name="treatment_done" class="form-control" rows="3" required placeholder="Describe the procedure performed..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Medications Prescribed</label>
                            <textarea name="medications_prescribed" class="form-control" rows="2" placeholder="List medications given..."></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Next Visit Notes</label>
                            <textarea name="next_visit_notes" class="form-control" rows="2" placeholder="Follow-up instructions..."></textarea>
                        </div>
                    </div>
                    <div class="dental-form-actions mt-3" style="display:flex;gap:10px;">
                        <button type="submit" class="btn btn-success"><i class="bi bi-check-lg"></i> Save Record</button>
                        <a href="<?php echo $pre_appointment_id ? BASE_URL.'modules/appointments/list.php' : ($pre_patient_id ? '../patients/view.php?id='.$pre_patient_id : 'list.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
<script>
// ── Patient change → reload appointments via API ──────────────────────────
document.getElementById('patient_select').addEventListener('change', function() {
    var pid    = this.value;
    var select = document.getElementById('appt_select');
    select.innerHTML = '<option value="">Loading...</option>';
    if (!pid) { select.innerHTML = '<option value="">None / Walk-in</option>'; return; }
    fetch('<?php echo BASE_URL; ?>api/patients.php?action=get_appointments&patient_id=' + pid)
    .then(r => r.json())
    .then(data => {
        select.innerHTML = '<option value="">None / Walk-in</option>';
        (data.appointments || []).forEach(function(a) {
            var opt = document.createElement('option');
            opt.value = a.id;
            opt.textContent = a.appointment_code + ' — ' + a.appointment_date;
            select.appendChild(opt);
        });
    });
});

// ── Tooth Chart Logic ─────────────────────────────────────────────────────
(function() {
    var selected = new Set();
    var input    = document.getElementById('toothNumberInput');
    var SELECTED_BG     = 'var(--blue-500)';
    var SELECTED_COLOR  = '#fff';
    var SELECTED_BORDER = 'var(--blue-600)';
    var DEFAULT_BG      = 'var(--gray-200)';
    var DEFAULT_COLOR   = 'var(--gray-600)';
    var DEFAULT_BORDER  = 'var(--gray-300)';

    function syncInputFromSet() {
        input.value = Array.from(selected).sort((a, b) => {
            var na = parseInt(a), nb = parseInt(b);
            return isNaN(na) || isNaN(nb) ? a.localeCompare(b) : na - nb;
        }).join(', ');
    }

    function syncChartFromInput() {
        var raw   = input.value;
        var parts = raw.split(/[\s,;]+/).map(s => s.trim()).filter(Boolean);
        selected.clear();
        parts.forEach(t => selected.add(t));
        document.querySelectorAll('.tooth-btn').forEach(function(btn) {
            var t = btn.dataset.tooth;
            if (selected.has(t)) {
                btn.style.background  = SELECTED_BG;
                btn.style.color       = SELECTED_COLOR;
                btn.style.borderColor = SELECTED_BORDER;
                btn.style.transform   = 'scale(1.12)';
                btn.style.boxShadow   = '0 2px 8px rgba(37,99,235,0.35)';
            } else {
                btn.style.background  = DEFAULT_BG;
                btn.style.color       = DEFAULT_COLOR;
                btn.style.borderColor = DEFAULT_BORDER;
                btn.style.transform   = '';
                btn.style.boxShadow   = '';
            }
        });
    }

    document.querySelectorAll('.tooth-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var t = this.dataset.tooth;
            if (selected.has(t)) {
                selected.delete(t);
                this.style.background  = DEFAULT_BG;
                this.style.color       = DEFAULT_COLOR;
                this.style.borderColor = DEFAULT_BORDER;
                this.style.transform   = '';
                this.style.boxShadow   = '';
            } else {
                selected.add(t);
                this.style.background  = SELECTED_BG;
                this.style.color       = SELECTED_COLOR;
                this.style.borderColor = SELECTED_BORDER;
                this.style.transform   = 'scale(1.12)';
                this.style.boxShadow   = '0 2px 8px rgba(37,99,235,0.35)';
            }
            syncInputFromSet();
        });
        btn.addEventListener('mouseenter', function() {
            if (!selected.has(this.dataset.tooth)) {
                this.style.background  = 'var(--blue-100)';
                this.style.borderColor = 'var(--blue-300)';
            }
        });
        btn.addEventListener('mouseleave', function() {
            if (!selected.has(this.dataset.tooth)) {
                this.style.background  = DEFAULT_BG;
                this.style.borderColor = DEFAULT_BORDER;
            }
        });
    });

    input.addEventListener('input', syncChartFromInput);

    document.getElementById('clearTeeth').addEventListener('click', function() {
        selected.clear();
        input.value = '';
        syncChartFromInput();
    });
})();

// ── Tooth Condition Preview Badge ─────────────────────────────────────────
// Shows a colored pill next to the select so dentist can see at a glance
// what condition they picked — especially useful for quick visual confirmation.
(function() {
    var conditionColors = {
        normal:     { bg: '#f0fdf4', color: '#15803d', border: '#bbf7d0', label: '✓ Normal / Healthy'           },
        caries:     { bg: '#fef3c7', color: '#92400e', border: '#fde68a', label: '⚠ Caries (Cavity)'            },
        filling:    { bg: '#eff6ff', color: '#1d4ed8', border: '#bfdbfe', label: '◈ Filling Done'               },
        extraction: { bg: '#fef2f2', color: '#dc2626', border: '#fecaca', label: '✕ Extraction / Pulled'        },
        missing:    { bg: '#f3f4f6', color: '#374151', border: '#d1d5db', label: '○ Already Missing'            },
        crown:      { bg: '#fdf4ff', color: '#7e22ce', border: '#e9d5ff', label: '♛ Crown Placed'               },
        rootcanal:  { bg: '#fff1f2', color: '#be123c', border: '#fecdd3', label: '◎ Root Canal Treated'         },
        bridge:     { bg: '#ecfdf5', color: '#065f46', border: '#a7f3d0', label: '⇌ Bridge'                     },
        implant:    { bg: '#f0f9ff', color: '#0369a1', border: '#bae6fd', label: '⊕ Implant'                    },
        denture:    { bg: '#fafaf9', color: '#44403c', border: '#d6d3d1', label: '⬡ Denture'                     }
    };

    var select  = document.getElementById('tooth_status_select');
    var preview = document.getElementById('conditionPreview');

    function updatePreview() {
        var val = select.value;
        var cfg = conditionColors[val];
        if (!cfg) { preview.style.display = 'none'; return; }
        preview.style.display     = 'inline-flex';
        preview.style.background  = cfg.bg;
        preview.style.color       = cfg.color;
        preview.style.border      = '1px solid ' + cfg.border;
        preview.textContent       = cfg.label;
    }

    select.addEventListener('change', updatePreview);
    updatePreview(); // run on page load
})();
// ── END TOOTH CHART ───────────────────────────────────────────────────────
</script>
</body>
</html>
