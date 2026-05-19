<?php
// generate_link.php — Generate a PayMongo payment link for an unpaid/partial bill.
// Staff clicks this, gets a shareable URL, copies and sends it to the patient.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_once '../../includes/paymongo.php';

$page_title = 'Generate Payment Link';
$id = intval($_GET['id'] ?? 0);
if (!$id) { header('Location: list.php'); exit(); }

// Fetch bill
$bill = $conn->query("
    SELECT b.*, CONCAT(p.first_name,' ',p.last_name) as patient_name,
           p.patient_code, p.phone, s.service_name
    FROM bills b
    LEFT JOIN patients p ON b.patient_id = p.id
    LEFT JOIN services s ON b.service_id = s.id
    WHERE b.id = $id AND b.status != 'paid' LIMIT 1
")->fetch_assoc();

if (!$bill) { header('Location: list.php'); exit(); }

$balance     = $bill['amount_due'] - $bill['amount_paid'];
$error       = '';
$success     = '';
$payment_url = '';

// Check if a link was already generated and stored
$existing_url = $bill['paymongo_link_url'] ?? '';
$existing_id  = $bill['paymongo_link_id']  ?? '';

// If POST — (re)generate link
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Amount in centavos
        $centavos    = (int) round($balance * 100);
        $description = trim($_POST['description'] ?? '') ?: 'Payment for ' . ($bill['service_name'] ?? 'Dental Service');
        $description = substr($description, 0, 255); // PayMongo max

        $metadata = [
            'bill_id'      => (string)$id,
            'bill_code'    => $bill['bill_code'],
            'patient_name' => $bill['patient_name'],
            'patient_code' => $bill['patient_code'],
        ];

        $result = paymongo_create_link($centavos, $description, $metadata);

        // Store in DB
        $link_id  = $conn->real_escape_string($result['link_id']);
        $link_url = $conn->real_escape_string($result['url']);
        $conn->query("
            UPDATE bills
            SET paymongo_link_id  = '$link_id',
                paymongo_link_url = '$link_url'
            WHERE id = $id
        ");

        $payment_url  = $result['url'];
        $existing_url = $result['url'];
        $existing_id  = $result['link_id'];
        $success = 'Payment link generated! Copy the link below and send it to the patient.';

        log_action($conn, $current_user_id, $current_user_name,
            'Generated Payment Link', 'billing', $id,
            "Bill: {$bill['bill_code']} | Amount: ₱" . number_format($balance, 2) . " | Link ID: {$result['link_id']}"
        );

        notify($conn, 'payment', 'Payment Link Created',
            "Payment link created for {$bill['patient_name']}'s bill {$bill['bill_code']} — ₱" . number_format($balance, 2) . ".",
            'modules/billing/view.php?id=' . $id
        );

    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    }
}
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
                <h5>Generate Payment Link</h5>
                <p>Bill: <?php echo e($bill['bill_code']); ?> — <?php echo e($bill['patient_name']); ?></p>
            </div>
            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i> Back to Bill
            </a>
        </div>

        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i> <?php echo e($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?php echo e($success); ?></div>
        <?php endif; ?>

        <div class="row g-3">
            <!-- Balance Summary -->
            <div class="col-md-5">
                <div class="card">
                    <div class="card-header"><i class="bi bi-receipt" style="color:var(--blue-500)"></i> Balance</div>
                    <div class="card-body">
                        <div style="display:flex;flex-direction:column;gap:10px;font-size:0.875rem;">
                            <div style="display:flex;justify-content:space-between;">
                                <span style="color:var(--gray-500)">Service</span>
                                <span style="font-weight:600"><?php echo e($bill['service_name'] ?? '—'); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span style="color:var(--gray-500)">Total Bill</span>
                                <span style="font-weight:600">₱<?php echo number_format($bill['amount_due'], 2); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span style="color:var(--gray-500)">Already Paid</span>
                                <span style="font-weight:600;color:var(--success)">₱<?php echo number_format($bill['amount_paid'], 2); ?></span>
                            </div>
                            <div style="display:flex;justify-content:space-between;padding-top:8px;border-top:1px solid var(--gray-100);font-size:1rem;">
                                <span style="font-weight:700;color:var(--danger)">Amount to Collect</span>
                                <span style="font-weight:800;color:var(--danger);font-family:'Outfit',sans-serif;">₱<?php echo number_format($balance, 2); ?></span>
                            </div>
                        </div>

                        <div style="margin-top:16px;padding:12px;background:var(--blue-50);border-radius:8px;font-size:0.78rem;color:var(--gray-600);">
                            <i class="bi bi-info-circle"></i>
                            The patient can pay via <strong>GCash, Maya, Credit/Debit Card,</strong> or <strong>QR Ph</strong> using the link you generate.
                        </div>
                    </div>
                </div>

                <!-- PayMongo branding note -->
                <div style="margin-top:12px;padding:12px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;font-size:0.78rem;color:#166534;display:flex;align-items:center;gap:8px;">
                    <i class="bi bi-shield-check"></i>
                    Payments are processed securely by <strong>PayMongo</strong>. Your clinic never handles card data.
                </div>
            </div>

            <!-- Generate Form -->
            <div class="col-md-7">
                <div class="card">
                    <div class="card-header"><i class="bi bi-link-45deg" style="color:var(--success)"></i> Payment Link</div>
                    <div class="card-body">

                        <?php if ($existing_url): ?>
                        <!-- Show existing link -->
                        <div style="margin-bottom:20px;">
                            <label class="form-label" style="font-weight:600;">
                                <i class="bi bi-check-circle-fill" style="color:var(--success)"></i>
                                Active Payment Link
                            </label>
                            <div style="display:flex;gap:8px;align-items:center;">
                                <input type="text" id="link_url_display" class="form-control form-control-sm"
                                    value="<?php echo e($existing_url); ?>" readonly
                                    style="font-size:0.78rem;background:var(--gray-50);">
                                <button class="btn btn-sm btn-success" onclick="copyLink()" title="Copy link">
                                    <i class="bi bi-clipboard" id="copy_icon"></i>
                                </button>
                                <a href="<?php echo e($existing_url); ?>" target="_blank"
                                   class="btn btn-sm btn-outline-primary" title="Open link">
                                    <i class="bi bi-box-arrow-up-right"></i>
                                </a>
                            </div>
                            <div style="font-size:0.75rem;color:var(--gray-400);margin-top:6px;">
                                Link ID: <?php echo e($existing_id); ?>
                            </div>
                        </div>
                        <hr style="margin:16px 0;">
                        <p style="font-size:0.8rem;color:var(--gray-500);margin-bottom:12px;">
                            Need a fresh link? Generate a new one below (the old link will still work).
                        </p>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Payment Description (shown to patient)</label>
                                <input type="text" name="description" class="form-control"
                                    placeholder="e.g. Dental cleaning - <?php echo e($bill['patient_name']); ?>"
                                    value="Payment for <?php echo e($bill['service_name'] ?? 'Dental Service'); ?> - <?php echo e($bill['bill_code']); ?>"
                                    maxlength="255">
                                <div style="font-size:0.75rem;color:var(--gray-400);margin-top:4px;">This is what the patient sees on the payment page.</div>
                            </div>

                            <div style="background:var(--blue-50);border-radius:8px;padding:14px;margin-bottom:16px;font-size:0.85rem;">
                                <div style="font-weight:700;margin-bottom:4px;">Amount: ₱<?php echo number_format($balance, 2); ?></div>
                                <div style="color:var(--gray-500);">This link will collect the full remaining balance.</div>
                            </div>

                            <div style="display:flex;gap:10px;">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-link-45deg"></i>
                                    <?php echo $existing_url ? 'Regenerate Link' : 'Generate Payment Link'; ?>
                                </button>
                                <a href="pay.php?id=<?php echo $id; ?>" class="btn btn-outline-secondary">
                                    <i class="bi bi-cash"></i> Manual Payment Instead
                                </a>
                            </div>
                        </form>

                    </div>
                </div>

                <!-- How it works -->
                <div class="card" style="margin-top:16px;">
                    <div class="card-header" style="font-size:0.82rem;"><i class="bi bi-question-circle"></i> How it works</div>
                    <div class="card-body" style="font-size:0.82rem;padding:14px 16px;">
                        <div style="display:flex;flex-direction:column;gap:10px;">
                            <div style="display:flex;gap:10px;align-items:flex-start;">
                                <div style="width:22px;height:22px;background:var(--success);border-radius:50%;color:#fff;font-size:0.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">1</div>
                                <span>Click <strong>Generate Payment Link</strong> above to create a secure PayMongo link.</span>
                            </div>
                            <div style="display:flex;gap:10px;align-items:flex-start;">
                                <div style="width:22px;height:22px;background:var(--success);border-radius:50%;color:#fff;font-size:0.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">2</div>
                                <span>Copy the link and send it to the patient via <strong>Messenger, SMS, Viber,</strong> etc.</span>
                            </div>
                            <div style="display:flex;gap:10px;align-items:flex-start;">
                                <div style="width:22px;height:22px;background:var(--success);border-radius:50%;color:#fff;font-size:0.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">3</div>
                                <span>The patient clicks the link and pays using <strong>GCash, Maya, Card, or QR Ph</strong>.</span>
                            </div>
                            <div style="display:flex;gap:10px;align-items:flex-start;">
                                <div style="width:22px;height:22px;background:var(--blue-500);border-radius:50%;color:#fff;font-size:0.7rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;">4</div>
                                <span>The bill status updates to <strong>Paid</strong> automatically within seconds.</span>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>
</div>
<?php include '../../includes/footer.php'; ?>
<script>
function copyLink() {
    var input = document.getElementById('link_url_display');
    if (!input) return;
    navigator.clipboard.writeText(input.value).then(function() {
        var icon = document.getElementById('copy_icon');
        icon.className = 'bi bi-check2';
        setTimeout(function() { icon.className = 'bi bi-clipboard'; }, 2000);
    });
}
</script>
</body>
</html>
