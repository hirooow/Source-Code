<?php
require_once '../../includes/auth.php';
require_once '../../includes/db.php';

if ($_SESSION['role'] !== 'admin') {
    header('Location: ../../dashboard.php');
    exit;
}

$page_title = 'Audit Log Dashboard';

$total_logs = $conn->query("SELECT COUNT(*) as cnt FROM audit_logs")->fetch_assoc()['cnt'] ?? 0;
$today_logs = $conn->query("SELECT COUNT(*) as cnt FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['cnt'] ?? 0;
$unique_users = $conn->query("SELECT COUNT(DISTINCT user_id) as cnt FROM audit_logs WHERE DATE(created_at) = CURDATE()")->fetch_assoc()['cnt'] ?? 0;

$logs_result = $conn->query("
    SELECT al.*, u.name as user_name, u.role as user_role
    FROM audit_logs al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT 50
");

include '../../includes/head.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<div class="main-content">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-shield-check me-2"></i>Audit Log Dashboard</h2>
            <a href="activity.php" class="btn btn-outline-primary">
                <i class="bi bi-list-ul me-1"></i>View Full Activity Log
            </a>
        </div>

        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-3 me-3">
                            <i class="bi bi-journal-text text-primary fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Total Logs</div>
                            <div class="fw-bold fs-4"><?= number_format($total_logs) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle bg-success bg-opacity-10 p-3 me-3">
                            <i class="bi bi-calendar-day text-success fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Today's Logs</div>
                            <div class="fw-bold fs-4"><?= number_format($today_logs) ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm">
                    <div class="card-body d-flex align-items-center">
                        <div class="rounded-circle bg-info bg-opacity-10 p-3 me-3">
                            <i class="bi bi-people text-info fs-4"></i>
                        </div>
                        <div>
                            <div class="text-muted small">Active Users Today</div>
                            <div class="fw-bold fs-4"><?= number_format($unique_users) ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white border-0 pt-3">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Recent Activity</h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>User</th>
                                <th>Action</th>
                                <th>Description</th>
                                <th>Date & Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($logs_result && $logs_result->num_rows > 0): ?>
                                <?php while ($log = $logs_result->fetch_assoc()): ?>
                                    <tr>
                                        <td>
                                            <div class="fw-semibold"><?= htmlspecialchars($log['user_name'] ?? 'System') ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($log['user_role'] ?? '') ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $action = strtolower($log['action'] ?? '');
                                            if (str_contains($action, 'create') || str_contains($action, 'add')) $badge = 'success';
                                            elseif (str_contains($action, 'delete')) $badge = 'danger';
                                            elseif (str_contains($action, 'update') || str_contains($action, 'edit')) $badge = 'warning';
                                            elseif (str_contains($action, 'login') || str_contains($action, 'logout')) $badge = 'info';
                                            else $badge = 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $badge ?>"><?= htmlspecialchars($log['action'] ?? '') ?></span>
                                        </td>
                                        <td><?= htmlspecialchars($log['description'] ?? $log['details'] ?? '-') ?></td>
                                        <td><small><?= date('M d, Y h:i A', strtotime($log['created_at'])) ?></small></td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">No logs found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>
