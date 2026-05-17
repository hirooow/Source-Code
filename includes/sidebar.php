]<?php
// sidebar.php — Left navigation sidebar shown on every admin page.
// NOTE: Requires auth.php to be included first for $current_user_* variables

// Fallback for variables if auth.php wasn't included (safety check)
$current_user_name = $current_user_name ?? 'User';
$current_user_role = $current_user_role ?? 'staff';

$initials = strtoupper(substr($current_user_name, 0, 1));

// Returns 'active' CSS class if the current URL contains the given path segment
function nav_active($path) {
    return strpos($_SERVER['PHP_SELF'], $path) !== false ? 'active' : '';
}
?>
<!-- Mobile sidebar backdrop — tap to close -->
<div id="sidebar-backdrop" onclick="closeMobileSidebar()"></div>

<div id="sidebar">

    <div class="sidebar-brand" id="brandTrigger" title="Click to customize" style="cursor:pointer;">
    <div class="sidebar-brand-icon" id="brandIconWrap">
        <img id="brandLogoImg" src="" alt="" style="display:none;width:36px;height:36px;border-radius:50%;object-fit:cover;">
        <span id="brandLogoEmoji">🦷</span>
    </div>
    <div class="sidebar-brand-text">
        <span class="brand-name" id="brandNameDisplay">DentalCare.PH</span>
        <span class="brand-sub" id="brandSubDisplay">Clinic System</span>
    </div>
</div>

<!-- Brand Customizer Modal -->
<div id="brandOverlay" style="display:none;position:fixed;inset:0;z-index:9999;backdrop-filter:blur(6px);-webkit-backdrop-filter:blur(6px);background:rgba(0,0,0,0.45);">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;border-radius:16px;padding:32px 28px;width:340px;box-shadow:0 20px 60px rgba(0,0,0,0.25);">
        <h2 style="margin:0 0 6px;font-size:1.1rem;font-weight:700;color:#1e3a5f;">Customize Clinic Branding</h2>
        <p style="margin:0 0 24px;font-size:0.8rem;color:#888;">Changes are saved to this browser only.</p>

        <!-- Logo Upload -->
        <div style="text-align:center;margin-bottom:24px;">
            <div id="logoPreviewCircle" onclick="document.getElementById('logoFileInput').click()" style="width:80px;height:80px;border-radius:50%;border:3px dashed #3b82f6;background:#eff6ff;margin:0 auto 10px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:2rem;overflow:hidden;">
                <span id="modalLogoEmoji">🦷</span>
                <img id="modalLogoImg" src="" style="display:none;width:100%;height:100%;object-fit:cover;">
            </div>
            <input type="file" id="logoFileInput" accept="image/*" style="display:none;">
            <p style="font-size:0.75rem;color:#666;margin:0;">Click circle to upload logo</p>
        </div>

        <!-- Name Fields -->
        <div style="margin-bottom:14px;">
            <label style="font-size:0.78rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Clinic Name</label>
            <input id="inputBrandName" type="text" maxlength="30" placeholder="e.g. DentalCare" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;box-sizing:border-box;outline:none;">
        </div>
        <div style="margin-bottom:24px;">
            <label style="font-size:0.78rem;font-weight:600;color:#374151;display:block;margin-bottom:4px;">Subtitle</label>
            <input id="inputBrandSub" type="text" maxlength="30" placeholder="e.g. Clinic System" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:0.9rem;box-sizing:border-box;outline:none;">
        </div>

        <!-- Buttons -->
        <div style="display:flex;gap:10px;">
            <button onclick="closeBrandModal()" style="flex:1;padding:10px;border:1px solid #d1d5db;background:#fff;border-radius:8px;font-size:0.88rem;cursor:pointer;color:#374151;font-weight:500;">Cancel</button>
            <button onclick="confirmBrandSave()" style="flex:1;padding:10px;background:#1d4ed8;color:#fff;border:none;border-radius:8px;font-size:0.88rem;cursor:pointer;font-weight:600;">Next →</button>
        </div>
    </div>
</div>

<script>
(function() {
    // Apply saved branding on load
    var saved = JSON.parse(localStorage.getItem('clinicBrand') || '{}');
    if (saved.name)  { document.getElementById('brandNameDisplay').textContent = saved.name; document.title = document.title.replace(/^[^|]+/, saved.name + ' '); }
    if (saved.sub)   document.getElementById('brandSubDisplay').textContent  = saved.sub;
    if (saved.logo)  { document.getElementById('brandLogoImg').src = saved.logo; document.getElementById('brandLogoImg').style.display='block'; document.getElementById('brandLogoEmoji').style.display='none'; }

    window.openBrandModal = function() {
        var s = JSON.parse(localStorage.getItem('clinicBrand') || '{}');
        document.getElementById('inputBrandName').value = s.name || 'DentalCare';
        document.getElementById('inputBrandSub').value  = s.sub  || 'Clinic System';
        if (s.logo) { document.getElementById('modalLogoImg').src=s.logo; document.getElementById('modalLogoImg').style.display='block'; document.getElementById('modalLogoEmoji').style.display='none'; }
        else        { document.getElementById('modalLogoImg').style.display='none'; document.getElementById('modalLogoEmoji').style.display='inline'; }
        document.getElementById('brandOverlay').style.display = 'block';
    };

    window.closeBrandModal = function() {
        document.getElementById('brandOverlay').style.display = 'none';
        document.getElementById('logoFileInput').value = '';
    };

    window.confirmBrandSave = function() {
        var name = document.getElementById('inputBrandName').value.trim() || 'DentalCare';
        var sub  = document.getElementById('inputBrandSub').value.trim()  || 'Clinic System';
        if (!confirm('Apply these branding changes?\n\nClinic Name: ' + name + '\nSubtitle: ' + sub + '\n\nThis will update the sidebar on all pages.')) return;
        var brand = { name: name, sub: sub };
        var img = document.getElementById('modalLogoImg');
        if (img.style.display !== 'none' && img.src) brand.logo = img.src;
        localStorage.setItem('clinicBrand', JSON.stringify(brand));
        location.reload();
    };

    // Logo file preview
    document.getElementById('logoFileInput').addEventListener('change', function(e) {
        var file = e.target.files[0]; if (!file) return;
        var reader = new FileReader();
        reader.onload = function(ev) {
            document.getElementById('modalLogoImg').src = ev.target.result;
            document.getElementById('modalLogoImg').style.display = 'block';
            document.getElementById('modalLogoEmoji').style.display = 'none';
        };
        reader.readAsDataURL(file);
    });

    document.getElementById('brandTrigger').addEventListener('click', openBrandModal);

    // Close if clicking outside the card
    document.getElementById('brandOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeBrandModal();
    });
})();
</script>

    <ul class="sidebar-nav">

        <li><span class="nav-section-label">Main</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>dashboard.php" class="<?php echo nav_active('dashboard'); ?>">
                <i class="bi bi-house-door-fill"></i><span class="nav-label">Dashboard</span>
            </a>
        </li>

        <li><span class="nav-section-label">Patients</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/patients/list.php" class="<?php echo nav_active('/patients/list'); ?>">
                <i class="bi bi-people-fill"></i><span class="nav-label">Patient Records</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/treatments/list.php" class="<?php echo nav_active('/treatments/'); ?>">
                <i class="bi bi-journal-medical"></i><span class="nav-label">Dental Records</span>
            </a>
        </li>

        <li><span class="nav-section-label">Appointments</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/appointments/list.php" class="<?php echo nav_active('/appointments/list'); ?>">
                <i class="bi bi-calendar-check-fill"></i><span class="nav-label">Appointments</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/appointments/calendar.php" class="<?php echo nav_active('/appointments/calendar'); ?>">
                <i class="bi bi-calendar3"></i><span class="nav-label">Calendar</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/schedule/manage.php" class="<?php echo nav_active('/schedule/'); ?>">
                <i class="bi bi-clock-history"></i><span class="nav-label">Schedule</span>
            </a>
        </li>

        <li><span class="nav-section-label">Billing</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/billing/list.php" class="<?php echo nav_active('/billing/'); ?>">
                <i class="bi bi-receipt"></i><span class="nav-label">Billing</span>
            </a>
        </li>

        <?php if (is_admin()): ?>
        <li><span class="nav-section-label">Admin</span></li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/analytics/dashboard.php" class="<?php echo nav_active('/analytics/'); ?>">
                <i class="bi bi-bar-chart-fill"></i><span class="nav-label">Analytics</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/reports/index.php" class="<?php echo nav_active('/reports/'); ?>">
                <i class="bi bi-file-earmark-bar-graph-fill"></i><span class="nav-label">Reports</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/users/list.php" class="<?php echo nav_active('/users/'); ?>">
                <i class="bi bi-person-gear"></i><span class="nav-label">Users</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/services/list.php" class="<?php echo nav_active('/services/'); ?>">
                <i class="bi bi-grid-fill"></i><span class="nav-label">Services</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/doctors/list.php" class="<?php echo nav_active('/doctors/'); ?>">
                <i class="bi bi-person-badge-fill"></i><span class="nav-label">Doctors</span>
            </a>
        </li>
        <li>
            <a href="<?php echo BASE_URL; ?>modules/logs/activity.php" class="<?php echo nav_active('/logs/'); ?>">
                <i class="bi bi-shield-fill-check"></i><span class="nav-label">Audit Logs</span>
            </a>
        </li>
        <?php endif; ?>

    </ul>

    <div class="sidebar-footer">
        <div class="sidebar-user">
            <div class="user-avatar"><?php echo $initials; ?></div>
            <div class="user-info">
                <div class="user-name"><?php echo e($current_user_name); ?></div>
                <div class="user-role"><?php echo ucfirst($current_user_role); ?></div>
            </div>
        </div>
    </div>

</div>
