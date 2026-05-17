<?php
// List all system users. Toggle active/inactive status.

require_once '../../includes/config.php';
require_once '../../includes/db.php';
require_once '../../includes/auth.php';
require_admin();

$page_title = 'User Management';

// Handle toggle active — fully prepared
if (isset($_GET['toggle']) && isset($_GET['uid'])) {
    $uid = secure_int($_GET['uid'] ?? 0);
    if ($uid > 0 && $uid !== $current_user_id) {
        $stmt = $conn->prepare("SELECT is_active FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($user) {
            $new_status   = $user['is_active'] ? 0 : 1;
            $stmt2 = $conn->prepare("UPDATE users SET is_active = ? WHERE id = ?");
            $stmt2->bind_param('ii', $new_status, $uid);
            $stmt2->execute();
            $stmt2->close();
            $label = $new_status ? 'Activated User' : 'Deactivated User';
            log_action($conn, $current_user_id, $current_user_name, $label, 'users', $uid);
        }
    }
    header('Location: list.php');
    exit();
}

$users = $conn->query("SELECT * FROM users ORDER BY role ASC, full_name ASC")->fetch_all(MYSQLI_ASSOC);
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
                <h5>User Management</h5>
            </div>
            <div class="page-header-actions">
                <a href="add.php" class="btn btn-sm btn-primary">
                    <i class="bi bi-person-plus"></i> Add User
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-body p-0">
                <div class="table-responsive">
<table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Full Name</th>
                            <th>Username</th>
                            <th>Role</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Status</th>
                            <th>Created</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($u['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $u['role'] === 'admin' ? 'danger' : 'secondary'; ?>">
                                    <?php echo ucfirst($u['role']); ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($u['email'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($u['phone'] ?? '—'); ?></td>
                            <td>
                                <span class="badge bg-<?php echo $u['is_active'] ? 'success' : 'secondary'; ?>">
                                    <?php echo $u['is_active'] ? 'Active' : 'Inactive'; ?>
                                </span>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($u['created_at'])); ?></td>
                            <td>
                                <a href="edit.php?id=<?php echo $u['id']; ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
                                <?php if ($u['id'] !== $current_user_id): ?>
                                    <a href="list.php?toggle=1&uid=<?php echo $u['id']; ?>"
                                       class="btn btn-sm btn-outline-<?php echo $u['is_active'] ? 'warning' : 'success'; ?>"
                                       onclick="return confirm('<?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?> this user?')">
                                        <?php echo $u['is_active'] ? 'Deactivate' : 'Activate'; ?>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
</body>
</html>
