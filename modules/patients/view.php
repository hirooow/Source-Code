<?php
// View a patient profile: personal info, dental records, appointments, and billing.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';

$page_title = 'Patient Profile';

$id = secure_int($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

$patient = $conn->query("SELECT * FROM patients WHERE id = $id AND is_active = 1 LIMIT 1")->fetch_assoc();
if (!$patient) { header('Location: list.php'); exit(); }

// Dental records for this patient
$dental_records = $conn->query("
    SELECT dr.*, s.service_name, CONCAT(u.full_name) as recorded_by_name
    FROM dental_records dr
    LEFT JOIN services s ON dr.service_id = s.id
    LEFT JOIN users u ON dr.recorded_by = u.id
    WHERE dr.patient_id = $id
    ORDER BY dr.visit_date DESC
")->fetch_all(MYSQLI_ASSOC);

// Appointment history
$appointments = $conn->query("
    SELECT a.*, s.service_name, d.full_name as doctor_name
    FROM appointments a
    LEFT JOIN services s ON a.service_id = s.id
    LEFT JOIN doctors d ON a.doctor_id = d.id
    WHERE a.patient_id = $id
    ORDER BY a.appointment_date DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

// Billing history (from bills table)
$payments = $conn->query("
    SELECT b.*, s.service_name,
           b.amount_paid, b.amount_due, b.status as payment_status,
           b.payment_method, b.created_at
    FROM bills b
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.patient_id = $id
    ORDER BY b.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$total_paid = array_sum(array_column($payments, 'amount_paid'));
?>
<!DOCTYPE html>
<html lang="en">
<head><?php include '../../includes/head.php'; ?></head>
<body>
<?php include '../../includes/sidebar.php'; ?>
<div class="main-content">
    <?php include '../../includes/header.php'; ?>
    <div class="page-content">

        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h5 class="mb-0"><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></h5>
                <small class="text-muted"><?php echo htmlspecialchars($patient['patient_code']); ?></small>
            </div>
            <div class="d-flex gap-2">
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-pencil"></i> Edit
                </a>
                <a href="../treatments/add.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-success">
                    <i class="bi bi-journal-plus"></i> Add Dental Record
                </a>
                <a href="../appointments/add.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-calendar-plus"></i> Book Appointment
                </a>
                <a href="list.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Back
                </a>
            </div>
        </div>

        <div class="row g-4">

            <!-- Left Column: Patient Info -->
            <div class="col-md-4">

                <!-- Personal Info Card -->
                <div class="card mb-3">
                    <div class="card-header">Personal Information</div>
                    <div class="card-body">
                        <div class="table-responsive">
<table class="table table-sm table-borderless mb-0">
                            <tr><th class="text-muted" style="width:40%">Full Name</th><td><?php echo htmlspecialchars($patient['first_name'] . ' ' . ($patient['middle_name'] ? $patient['middle_name'] . ' ' : '') . $patient['last_name']); ?></td></tr>
                            <tr><th class="text-muted">Date of Birth</th><td><?php echo $patient['date_of_birth'] ? date('M d, Y', strtotime($patient['date_of_birth'])) : '—'; ?></td></tr>
                            <tr><th class="text-muted">Gender</th><td><?php echo ucfirst($patient['gender'] ?? '—'); ?></td></tr>
                            <tr><th class="text-muted">Civil Status</th><td><?php echo ucfirst($patient['civil_status'] ?? '—'); ?></td></tr>
                            <tr><th class="text-muted">Blood Type</th><td><?php echo htmlspecialchars($patient['blood_type'] ?? '—'); ?></td></tr>
                            <tr><th class="text-muted">Address</th><td><?php echo htmlspecialchars($patient['address'] ?? '—'); ?></td></tr>
                            <tr><th class="text-muted">Occupation</th><td><?php echo htmlspecialchars($patient['occupation'] ?: '—'); ?></td></tr>
                            <tr><th class="text-muted">Phone</th><td><?php echo htmlspecialchars($patient['phone'] ?? '—'); ?></td></tr>
                            <tr><th class="text-muted">Email</th><td><?php echo htmlspecialchars($patient['email'] ?? '—'); ?></td></tr>
                        </table>
                    </div>
                </div>

                <!-- Emergency Contact -->
                <div class="card mb-3">
                    <div class="card-header">Emergency Contact</div>
                    <div class="card-body">
                        <div class="table-responsive">
<table class="table table-sm table-borderless mb-0">
                            <tr><th class="text-muted" style="width:40%">Name</th><td><?php echo htmlspecialchars($patient['emergency_contact_name'] ?? '—'); ?></td></tr>
                            <tr><th class="text-muted">Phone</th><td><?php echo htmlspecialchars($patient['emergency_contact_phone'] ?? '—'); ?></td></tr>
                        </table>
                    </div>
                </div>

                <!-- Medical Background -->
                <div class="card mb-3">
                    <div class="card-header">Medical Background</div>
                    <div class="card-body">
                        <p class="mb-1"><strong class="text-muted small">Allergies:</strong></p>
                        <p class="mb-3"><?php echo nl2br(htmlspecialchars($patient['allergies'] ?? 'None reported')); ?></p>
                        <p class="mb-1"><strong class="text-muted small">Medical Notes:</strong></p>
                        <p class="mb-3"><?php echo nl2br(htmlspecialchars($patient['medical_notes'] ?? 'None')); ?></p>
                        <?php if (!empty($patient['illness_history'])): ?>
                        <p class="mb-1"><strong class="text-muted small">History of Illness:</strong></p>
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($patient['illness_history'])); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Payment Summary -->
                <div class="card">
                    <div class="card-header">Payment Summary</div>
                    <div class="card-body">
                        <h4 class="mb-0">₱<?php echo number_format($total_paid, 2); ?></h4>
                        <small class="text-muted">Total paid (all time)</small>
                        <hr>
                        <a href="../billing/create.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-success w-100">
                            <i class="bi bi-plus"></i> Record Payment
                        </a>
                    </div>
                </div>

            </div>

            <!-- Right Column: Records & History -->
            <div class="col-md-8">

                <!-- Dental Records -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Dental / Treatment Records</span>
                        <a href="../treatments/add.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-plus"></i> Add
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($dental_records)): ?>
                            <p class="text-muted text-center py-3">No dental records yet.</p>
                        <?php else: ?>
                            <div class="accordion accordion-flush" id="dentalAccordion">
                                <?php foreach ($dental_records as $i => $rec): ?>
                                <div class="accordion-item">
                                    <h2 class="accordion-header">
                                        <button class="accordion-button <?php echo $i > 0 ? 'collapsed' : ''; ?>" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#rec<?php echo $rec['id']; ?>">
                                            <span class="me-3"><?php echo date('M d, Y', strtotime($rec['visit_date'])); ?></span>
                                            <span class="text-muted"><?php echo htmlspecialchars($rec['service_name'] ?? 'General'); ?></span>
                                        </button>
                                    </h2>
                                    <div id="rec<?php echo $rec['id']; ?>" class="accordion-collapse collapse <?php echo $i === 0 ? 'show' : ''; ?>">
                                        <div class="accordion-body">
                                            <div class="row g-2">
                                                <div class="col-md-6">
                                                    <?php if (!empty($rec['chief_complaint'])): ?>
                                                    <p class="mb-1"><strong>Chief Complaint:</strong> <em><?php echo htmlspecialchars($rec['chief_complaint']); ?></em></p>
                                                    <?php endif; ?>
                                                    <p class="mb-1"><strong>Tooth Number:</strong> <?php echo htmlspecialchars($rec['tooth_number'] ?? '—'); ?></p>
                                                    <p class="mb-1"><strong>Diagnosis:</strong> <?php echo nl2br(htmlspecialchars($rec['diagnosis'] ?? '—')); ?></p>
                                                    <p class="mb-1"><strong>Treatment Done:</strong> <?php echo nl2br(htmlspecialchars($rec['treatment_done'])); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p class="mb-1"><strong>Medications:</strong> <?php echo nl2br(htmlspecialchars($rec['medications_prescribed'] ?? '—')); ?></p>
                                                    <p class="mb-1"><strong>Next Visit Notes:</strong> <?php echo nl2br(htmlspecialchars($rec['next_visit_notes'] ?? '—')); ?></p>
                                                    <p class="mb-0 text-muted small">Recorded by: <?php echo htmlspecialchars($rec['recorded_by_name'] ?? '—'); ?></p>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Appointment History -->
                <div class="card mb-4">
                    <div class="card-header">Recent Appointments</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
<table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr><th>Code</th><th>Service</th><th>Doctor</th><th>Date</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($appointments)): ?>
                                    <tr><td colspan="5" class="text-center text-muted py-2">No appointments.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($appointments as $a): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($a['appointment_code']); ?></td>
                                        <td><?php echo htmlspecialchars($a['service_name'] ?? 'N/A'); ?></td>
                                        <td><?php echo htmlspecialchars($a['doctor_name'] ?? '—'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($a['appointment_date'])); ?></td>
                                        <td>
                                            <span class="badge bg-<?php
                                                echo match($a['status']) {
                                                    'pending'   => 'warning',
                                                    'confirmed' => 'primary',
                                                    'completed' => 'success',
                                                    'cancelled' => 'danger',
                                                    'no-show'   => 'secondary',
                                                    default     => 'light'
                                                };
                                            ?>"><?php echo ucfirst($a['status']); ?></span>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Payment History -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Payment History</span>
                        <a href="../billing/create.php?patient_id=<?php echo $id; ?>" class="btn btn-sm btn-outline-success">
                            <i class="bi bi-plus"></i> Add
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
<table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr><th>Service</th><th>Due</th><th>Paid</th><th>Method</th><th>Status</th><th>Date</th></tr>
                            </thead>
                            <tbody>
                                <?php if (empty($payments)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-2">No payment records.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $py): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($py['service_name'] ?? 'N/A'); ?></td>
                                        <td>₱<?php echo number_format($py['amount_due'], 2); ?></td>
                                        <td>₱<?php echo number_format($py['amount_paid'], 2); ?></td>
                                        <td><?php echo ucfirst($py['payment_method']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php echo match($py['payment_status']) { 'paid' => 'success', 'partial' => 'warning', default => 'danger' }; ?>">
                                                <?php echo ucfirst($py['payment_status']); ?>
                                            </span>
                                        </td>
                        <td><?php echo date('M d, Y', strtotime($py['created_at'])); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
