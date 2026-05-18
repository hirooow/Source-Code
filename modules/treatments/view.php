<?php
// View a single dental record in full detail.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Dental Record';

$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$stmt = $conn->prepare("
    SELECT dr.*,
           s.service_name,
           CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.id as pid,
           p.date_of_birth, p.gender, p.blood_type,
           p.allergies, p.medical_notes, p.illness_history,
           CONCAT(u.full_name) as recorded_by_name,
           doc.full_name as doctor_name
    FROM dental_records dr
    LEFT JOIN patients  p   ON dr.patient_id     = p.id
    LEFT JOIN services  s   ON dr.service_id     = s.id
    LEFT JOIN users     u   ON dr.recorded_by    = u.id
    LEFT JOIN appointments a ON dr.appointment_id = a.id
    LEFT JOIN doctors   doc ON a.doctor_id        = doc.id
    WHERE dr.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $id);
$stmt->execute();
$r = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$r) { header('Location: list.php'); exit(); }

// Tooth status label + color map
$status_map = [
    'normal'    => ['label' => 'Normal / Healthy',     'bg' => '#f0fdf4', 'color' => '#15803d', 'border' => '#bbf7d0'],
    'caries'    => ['label' => 'Caries (Cavity)',       'bg' => '#fef3c7', 'color' => '#92400e', 'border' => '#fde68a'],
    'filling'   => ['label' => 'Filling Done',          'bg' => '#eff6ff', 'color' => '#1d4ed8', 'border' => '#bfdbfe'],
    'extraction'=> ['label' => 'Extraction / Pulled',   'bg' => '#fef2f2', 'color' => '#dc2626', 'border' => '#fecaca'],
    'missing'   => ['label' => 'Already Missing',       'bg' => '#f3f4f6', 'color' => '#374151', 'border' => '#d1d5db'],
    'crown'     => ['label' => 'Crown Placed',          'bg' => '#fdf4ff', 'color' => '#7e22ce', 'border' => '#e9d5ff'],
    'rootcanal' => ['label' => 'Root Canal Treated',    'bg' => '#fff1f2', 'color' => '#be123c', 'border' => '#fecdd3'],
    'bridge'    => ['label' => 'Bridge',                'bg' => '#ecfdf5', 'color' => '#065f46', 'border' => '#a7f3d0'],
    'implant'   => ['label' => 'Implant',               'bg' => '#f0f9ff', 'color' => '#0369a1', 'border' => '#bae6fd'],
    'denture'   => ['label' => 'Denture',               'bg' => '#fafaf9', 'color' => '#44403c', 'border' => '#d6d3d1'],
];
$ts  = $r['tooth_status'] ?? 'normal';
$tsc = $status_map[$ts] ?? $status_map['normal'];
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?>
<style>
/* ── Dental Record view — mobile ── */
@media (max-width: 640px) {
    /* Stack the 2-column layout vertically */
    .dental-view-grid {
        grid-template-columns: 1fr !important;
    }
    /* Header action buttons: stack vertically */
    .dental-view-actions {
        flex-direction: column !important;
        width: 100% !important;
    }
    .dental-view-actions a {
        width: 100% !important;
        justify-content: center !important;
    }
    /* Medications/next-visit 2-col: stack */
    .dental-meds-grid {
        grid-template-columns: 1fr !important;
    }
    /* Patient quick-action buttons: wrap */
    .patient-action-btns {
        flex-wrap: wrap !important;
    }
    .patient-action-btns a {
        flex: 1 !important;
        justify-content: center !important;
        text-align: center !important;
    }
    /* Tooth chart: allow horizontal scroll */
    .tooth-chart-scroll {
        overflow-x: auto !important;
        -webkit-overflow-scrolling: touch !important;
    }
    /* Page header: stack on mobile */
    .dental-page-header {
        flex-direction: column !important;
        align-items: flex-start !important;
        gap: 10px !important;
    }
}
</style>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Header row -->
        <div class="page-header" style="margin-bottom:20px;">
            <div>
                <h5>Dental Record</h5>
                <p style="font-size:0.82rem;color:var(--gray-500);margin:0;">
                    <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" style="color:var(--blue-500);">
                        <?php echo e($r['patient_name']); ?>
                    </a>
                    &nbsp;·&nbsp; <?php echo e($r['patient_code']); ?>
                    &nbsp;·&nbsp; Visit: <?php echo date('F d, Y', strtotime($r['visit_date'])); ?>
                </p>
            </div>
            <div class="dental-view-actions" style="display:flex;gap:8px;flex-wrap:wrap;">
                <a href="list.php?patient_id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> All Records for Patient
                </a>
                <a href="../print/medical_certificate.php?id=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-primary">
                    <i class="bi bi-file-earmark-medical"></i> Certificate
                </a>
                <a href="../print/prescription.php?id=<?php echo $r['id']; ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-capsule"></i> RX
                </a>
            </div>
        </div>

        <!-- Medical alert banner (shown if patient has relevant data) -->
        <?php if ($r['allergies'] || $r['medical_notes'] || $r['illness_history']): ?>
        <div style="background:#fffbeb;border:1.5px solid #f59e0b;border-radius:10px;padding:12px 16px;margin-bottom:18px;display:flex;align-items:flex-start;gap:10px;">
            <i class="bi bi-shield-exclamation" style="color:#d97706;font-size:1.1rem;flex-shrink:0;margin-top:2px;"></i>
            <div style="font-size:0.8rem;color:#92400e;">
                <strong>Medical Alert</strong>
                <?php if ($r['blood_type']): ?>
                    <span style="background:#dc2626;color:#fff;font-size:0.68rem;font-weight:700;padding:1px 7px;border-radius:20px;margin-left:8px;"><?php echo e($r['blood_type']); ?></span>
                <?php endif; ?>
                <div style="margin-top:5px;display:flex;gap:16px;flex-wrap:wrap;">
                    <?php if ($r['allergies']): ?><span><strong>Allergies:</strong> <?php echo e($r['allergies']); ?></span><?php endif; ?>
                    <?php if ($r['medical_notes']): ?><span><strong>Medical:</strong> <?php echo e($r['medical_notes']); ?></span><?php endif; ?>
                    <?php if ($r['illness_history']): ?><span><strong>History:</strong> <?php echo e($r['illness_history']); ?></span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="dental-view-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">

            <!-- LEFT: Clinical Details -->
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-journal-medical" style="color:var(--blue-500);margin-right:6px;"></i>
                    Clinical Record
                    <span style="margin-left:auto;font-size:0.75rem;color:var(--gray-400);">
                        <?php echo date('M d, Y', strtotime($r['visit_date'])); ?>
                    </span>
                </div>
                <div class="card-body" style="padding:18px 22px;">

                    <!-- Service + Doctor row -->
                    <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
                        <?php if ($r['service_name']): ?>
                        <span style="background:rgba(37,99,235,0.08);color:#1d4ed8;border:1px solid rgba(37,99,235,0.15);border-radius:7px;padding:4px 12px;font-size:0.78rem;font-weight:600;">
                            <i class="bi bi-clipboard2-pulse"></i> <?php echo e($r['service_name']); ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($r['doctor_name']): ?>
                        <span style="background:rgba(22,163,74,0.08);color:#15803d;border:1px solid rgba(22,163,74,0.15);border-radius:7px;padding:4px 12px;font-size:0.78rem;font-weight:600;">
                            <i class="bi bi-person-badge"></i> <?php echo e($r['doctor_name']); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Chief Complaint -->
                    <?php if ($r['chief_complaint']): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Chief Complaint</div>
                        <div style="font-size:0.88rem;color:var(--gray-700);font-style:italic;">"<?php echo e($r['chief_complaint']); ?>"</div>
                    </div>
                    <?php endif; ?>

                    <!-- Tooth Chart (read-only visual) -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:8px;">
                            Tooth Chart
                            <?php if ($r['tooth_number']): ?>
                            <span style="margin-left:8px;font-size:0.72rem;color:var(--blue-500);text-transform:none;letter-spacing:0;font-weight:500;">
                                Selected: <?php echo e($r['tooth_number']); ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <?php
                        $selected_teeth = array_map('trim', explode(',', $r['tooth_number'] ?? ''));
                        $upper = [18,17,16,15,14,13,12,11,21,22,23,24,25,26,27,28];
                        $lower = [48,47,46,45,44,43,42,41,31,32,33,34,35,36,37,38];
                        $primary_upper = ['55','54','53','52','51','61','62','63','64','65'];
                        $primary_lower = ['85','84','83','82','81','71','72','73','74','75'];
                        ?>
                        <div class="tooth-chart-scroll" style="background:var(--gray-50);border:1px solid var(--gray-200);border-radius:10px;padding:14px 10px;overflow-x:auto;-webkit-overflow-scrolling:touch;">
                            <!-- Upper permanent -->
                            <div style="display:flex;justify-content:center;gap:3px;margin-bottom:3px;">
                                <?php foreach($upper as $tn):
                                    $sel = in_array((string)$tn, $selected_teeth);
                                ?>
                                <div title="Tooth <?php echo $tn; ?>" style="width:30px;height:30px;border-radius:6px 6px 12px 12px;background:<?php echo $sel ? 'var(--blue-500)' : 'var(--gray-200)'; ?>;border:1px solid <?php echo $sel ? 'var(--blue-600)' : 'var(--gray-300)'; ?>;display:flex;align-items:center;justify-content:center;font-size:0.6rem;color:<?php echo $sel ? '#fff' : 'var(--gray-600)'; ?>;font-weight:600;flex-shrink:0;"><?php echo $tn; ?></div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Primary upper -->
                            <div style="display:flex;justify-content:center;gap:3px;margin-bottom:2px;">
                                <?php foreach($primary_upper as $pt):
                                    $sel = in_array($pt, $selected_teeth);
                                ?>
                                <div title="Primary <?php echo $pt; ?>" style="width:26px;height:26px;border-radius:50%;background:<?php echo $sel ? 'var(--blue-400)' : 'var(--gray-100)'; ?>;border:1px solid <?php echo $sel ? 'var(--blue-500)' : 'var(--gray-300)'; ?>;display:flex;align-items:center;justify-content:center;font-size:0.55rem;color:<?php echo $sel ? '#fff' : 'var(--gray-500)'; ?>;font-weight:600;flex-shrink:0;"><?php echo $pt; ?></div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Divider line -->
                            <div style="border-top:2px dashed var(--gray-300);margin:4px 0;"></div>
                            <!-- Primary lower -->
                            <div style="display:flex;justify-content:center;gap:3px;margin-bottom:2px;">
                                <?php foreach($primary_lower as $pt):
                                    $sel = in_array($pt, $selected_teeth);
                                ?>
                                <div title="Primary <?php echo $pt; ?>" style="width:26px;height:26px;border-radius:50%;background:<?php echo $sel ? 'var(--blue-400)' : 'var(--gray-100)'; ?>;border:1px solid <?php echo $sel ? 'var(--blue-500)' : 'var(--gray-300)'; ?>;display:flex;align-items:center;justify-content:center;font-size:0.55rem;color:<?php echo $sel ? '#fff' : 'var(--gray-500)'; ?>;font-weight:600;flex-shrink:0;"><?php echo $pt; ?></div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Lower permanent -->
                            <div style="display:flex;justify-content:center;gap:3px;">
                                <?php foreach($lower as $tn):
                                    $sel = in_array((string)$tn, $selected_teeth);
                                ?>
                                <div title="Tooth <?php echo $tn; ?>" style="width:30px;height:30px;border-radius:12px 12px 6px 6px;background:<?php echo $sel ? 'var(--blue-500)' : 'var(--gray-200)'; ?>;border:1px solid <?php echo $sel ? 'var(--blue-600)' : 'var(--gray-300)'; ?>;display:flex;align-items:center;justify-content:center;font-size:0.6rem;color:<?php echo $sel ? '#fff' : 'var(--gray-600)'; ?>;font-weight:600;flex-shrink:0;"><?php echo $tn; ?></div>
                                <?php endforeach; ?>
                            </div>
                            <!-- Legend -->
                            <div style="display:flex;gap:14px;justify-content:center;margin-top:10px;font-size:0.65rem;color:var(--gray-400);">
                                <span><span style="display:inline-block;width:8px;height:8px;background:var(--blue-500);border-radius:2px;margin-right:3px;"></span>Affected</span>
                                <span><span style="display:inline-block;width:8px;height:8px;background:var(--gray-200);border-radius:2px;margin-right:3px;"></span>Normal</span>
                                <span><span style="display:inline-block;width:8px;height:8px;background:var(--gray-100);border-radius:50%;margin-right:3px;border:1px solid var(--gray-300);"></span>Primary</span>
                            </div>
                        </div>
                    </div>

                    <!-- Tooth Condition -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Tooth Condition</div>
                        <span style="background:<?php echo $tsc['bg']; ?>;color:<?php echo $tsc['color']; ?>;border:1px solid <?php echo $tsc['border']; ?>;border-radius:7px;padding:3px 10px;font-size:0.78rem;font-weight:600;">
                            <?php echo $tsc['label']; ?>
                        </span>
                    </div>

                    <!-- Diagnosis -->
                    <?php if ($r['diagnosis']): ?>
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Diagnosis</div>
                        <div style="font-size:0.85rem;color:var(--gray-700);line-height:1.6;"><?php echo e($r['diagnosis']); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Treatment Done -->
                    <div style="margin-bottom:14px;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Treatment Done</div>
                        <div style="background:var(--gray-50);border:1px solid var(--gray-100);border-radius:8px;padding:10px 12px;font-size:0.85rem;color:var(--gray-800);line-height:1.6;">
                            <?php echo e($r['treatment_done']); ?>
                        </div>
                    </div>

                    <!-- Medications -->
                    <div class="dental-meds-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                        <div>
                            <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Medications Prescribed</div>
                            <div style="font-size:0.82rem;color:var(--gray-700);">
                                <?php echo $r['medications_prescribed'] ? e($r['medications_prescribed']) : '<span style="color:var(--gray-400);">None</span>'; ?>
                            </div>
                        </div>
                        <div>
                            <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:0.06em;color:var(--gray-400);font-weight:600;margin-bottom:4px;">Next Visit Notes</div>
                            <div style="font-size:0.82rem;color:var(--gray-700);">
                                <?php echo $r['next_visit_notes'] ? e($r['next_visit_notes']) : '<span style="color:var(--gray-400);">None</span>'; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Footer meta -->
                    <div style="margin-top:16px;padding-top:12px;border-top:1px solid var(--gray-100);font-size:0.73rem;color:var(--gray-400);display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
                        <span><i class="bi bi-person-fill"></i> Recorded by: <?php echo e($r['recorded_by_name'] ?? '—'); ?></span>
                        <span><i class="bi bi-clock"></i> <?php echo date('M d, Y h:i A', strtotime($r['created_at'])); ?></span>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Patient Summary -->
            <div style="display:flex;flex-direction:column;gap:18px;">

                <!-- Patient quick info -->
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-person-circle" style="color:var(--blue-500);margin-right:6px;"></i>
                        Patient
                    </div>
                    <div class="card-body" style="padding:16px 22px;">
                        <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
                            <div style="width:48px;height:48px;border-radius:50%;background:linear-gradient(135deg,var(--primary),#5a8fff);display:flex;align-items:center;justify-content:center;color:#fff;font-size:1.2rem;font-weight:700;flex-shrink:0;">
                                <?php echo strtoupper(substr($r['patient_name'], 0, 1)); ?>
                            </div>
                            <div>
                                <div style="font-weight:700;font-size:0.95rem;"><?php echo e($r['patient_name']); ?></div>
                                <div style="font-size:0.78rem;color:var(--blue-500);"><?php echo e($r['patient_code']); ?></div>
                                <?php if ($r['date_of_birth']): ?>
                                <div style="font-size:0.75rem;color:var(--gray-400);">
                                    <?php
                                    $dob = new DateTime($r['date_of_birth']);
                                    $age = (new DateTime())->diff($dob)->y;
                                    echo $age . ' years old · ' . ucfirst($r['gender'] ?? '');
                                    ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="patient-action-btns" style="display:flex;gap:8px;flex-wrap:wrap;">
                            <a href="../patients/view.php?id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-person"></i> Full Profile
                            </a>
                            <a href="list.php?patient_id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-journal-medical"></i> All Records
                            </a>
                            <a href="add.php?patient_id=<?php echo $r['pid']; ?>" class="btn btn-sm btn-success">
                                <i class="bi bi-plus"></i> Add Record
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Other dental records for this patient -->
                <?php
                $other_stmt = $conn->prepare("
                    SELECT dr.id, dr.visit_date, dr.tooth_number, dr.tooth_status, dr.treatment_done,
                           s.service_name
                    FROM dental_records dr
                    LEFT JOIN services s ON dr.service_id = s.id
                    WHERE dr.patient_id = ? AND dr.id != ?
                    ORDER BY dr.visit_date DESC
                    LIMIT 5
                ");
                $other_stmt->bind_param('ii', $r['pid'], $id);
                $other_stmt->execute();
                $other_records = $other_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
                $other_stmt->close();
                ?>
                <?php if (!empty($other_records)): ?>
                <div class="card">
                    <div class="card-header">
                        <i class="bi bi-clock-history" style="color:var(--blue-500);margin-right:6px;"></i>
                        Previous Records
                        <span style="margin-left:auto;font-size:0.73rem;color:var(--gray-400);">Most recent 5</span>
                    </div>
                    <div class="card-body p-0">
                        <?php foreach ($other_records as $or): ?>
                        <a href="view.php?id=<?php echo $or['id']; ?>" style="display:block;padding:11px 18px;border-bottom:1px solid var(--gray-100);text-decoration:none;transition:background 0.15s;"
                           onmouseover="this.style.background='var(--gray-50)'" onmouseout="this.style.background=''">
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                <div>
                                    <div style="font-size:0.82rem;font-weight:600;color:var(--gray-800);">
                                        <?php echo e($or['service_name'] ?? 'Treatment'); ?>
                                        <?php if ($or['tooth_number']): ?>
                                            <span style="font-size:0.72rem;color:var(--gray-400);">· Tooth <?php echo e($or['tooth_number']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:0.75rem;color:var(--gray-500);margin-top:1px;">
                                        <?php echo strlen($or['treatment_done']) > 55 ? substr(e($or['treatment_done']), 0, 55) . '…' : e($or['treatment_done']); ?>
                                    </div>
                                </div>
                                <div style="font-size:0.72rem;color:var(--gray-400);white-space:nowrap;flex-shrink:0;">
                                    <?php echo date('M d, Y', strtotime($or['visit_date'])); ?>
                                </div>
                            </div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
