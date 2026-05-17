<?php
require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'Audit Logs';

$per_page = 50;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

$search  = trim($_GET['search'] ?? '');
$filter  = trim($_GET['module'] ?? '');

$where = '1=1';
$params = [];
$types  = '';

if ($search !== '') {
    $where   .= " AND (l.user_name LIKE ? OR l.action LIKE ? OR l.details LIKE ?)";
    $s = "%$search%";
    $params = array_merge($params, [$s, $s, $s]);
    $types  .= 'sss';
}
if ($filter !== '') {
    $where  .= " AND l.module = ?";
    $params[] = $filter;
    $types   .= 's';
}

$count_sql = "SELECT COUNT(*) as c FROM audit_logs l WHERE $where";
$stmt = $conn->prepare($count_sql);
if ($types) $stmt->bind_param($types, ...$params);
$stmt->execute();
$total = (int)$stmt->get_result()->fetch_assoc()['c'];
$stmt->close();

$total_pages = max(1, (int)ceil($total / $per_page));

$sql  = "SELECT l.*, u.full_name as user_full FROM audit_logs l
         LEFT JOIN users u ON l.user_id = u.id
         WHERE $where ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
$all_params = array_merge($params, [$per_page, $offset]);
$all_types  = $types . 'ii';
$stmt2 = $conn->prepare($sql);
$stmt2->bind_param($all_types, ...$all_params);
$stmt2->execute();
$logs = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

$modules = $conn->query("SELECT DISTINCT module FROM audit_logs WHERE module IS NOT NULL ORDER BY module")->fetch_all(MYSQLI_ASSOC);
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
                <h5>Audit Logs</h5>
                <p class="text-muted mb-0">Track all system actions and changes</p>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-body py-2">
                <form method="get" class="row g-2 align-items-center">
                    <div class="col-md-5">
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Search user, action, details…"
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="module" class="form-select form-select-sm">
                            <option value="">All Modules</option>
                            <?php foreach ($modules as $m): ?>
                            <option value="<?php echo htmlspecialchars($m['module']); ?>"
                                <?php echo $filter === $m['module'] ? 'selected' : ''; ?>>
                                <?php echo ucfirst(htmlspecialchars($m['module'])); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                        <a href="activity.php" class="btn btn-sm btn-outline-secondary">Clear</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <table class="table table-hover mb-0 table-sm">
                    <thead>
                        <tr>
                            <th>Date/Time</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Module</th>
                            <th>Details</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No audit logs found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="text-nowrap small">
                                <?php echo date('M d, Y g:i A', strtotime($log['created_at'])); ?>
                            </td>
                            <td><?php echo htmlspecialchars($log['user_name'] ?? '—'); ?></td>
                            <td>
                                <span class="badge bg-secondary">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars(ucfirst($log['module'] ?? '—')); ?></td>
                            <td class="small text-muted" style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
                                title="<?php echo htmlspecialchars($log['details'] ?? ''); ?>">
                                <?php echo htmlspecialchars($log['details'] ?? '—'); ?>
                            </td>
                            <td class="small text-muted"><?php echo htmlspecialchars($log['ip_address'] ?? '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
    <small class="text-muted">Showing <?php echo count($logs); ?> of <?php echo $total; ?> entries</small>
    <?php if ($total_pages > 1): ?>
    <nav>
        <ul class="pagination pagination-sm mb-0">
            <?php
            $qs = '&search=' . urlencode($search) . '&module=' . urlencode($filter);
            ?>
            <!-- Previous -->
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo $qs; ?>">
                    &laquo;
                </a>
            </li>

            <?php
            // Show smart page numbers with ellipsis
            $range = 2;
            $start = max(1, $page - $range);
            $end   = min($total_pages, $page + $range);

            if ($start > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=1<?php echo $qs; ?>">1</a>
                </li>
                <?php if ($start > 2): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
            <?php endif; ?>

            <?php for ($i = $start; $i <= $end; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo $i; ?><?php echo $qs; ?>">
                        <?php echo $i; ?>
                    </a>
                </li>
            <?php endfor; ?>

            <?php if ($end < $total_pages): ?>
                <?php if ($end < $total_pages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?php echo $total_pages; ?><?php echo $qs; ?>">
                        <?php echo $total_pages; ?>
                    </a>
                </li>
            <?php endif; ?>

            <!-- Next -->
            <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo $qs; ?>">
                    Next &raquo;
                </a>
            </li>
        </ul>
    </nav>
    <?php endif; ?>
</div>
        </div>

    </div>
    <?php include '../../includes/footer.php'; ?>
</div>
</body>
</html>
