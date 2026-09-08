<?php
/**
 * TEMPORARY STUB — Phase 5 replaces this with the real CSV upload /
 * reconciliation dashboard. Exists now only so admin/login.php can be
 * tested end-to-end.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}

require_once __DIR__ . '/../includes/admin-header.php';
?>
<div class="card">
    <div class="card-body p-4">
        <h5 class="mb-1">Welcome, <?= htmlspecialchars($_SESSION['admin_name']) ?></h5>
        <p class="text-muted mb-3">Role: <?= htmlspecialchars(ucfirst(str_replace('_', ' ', $_SESSION['admin_role']))) ?></p>
        <p class="small text-muted">Login is confirmed working. The real CSV reconciliation dashboard is next.</p>
        <a href="/admin/csv-upload.php" class="btn btn-primary w-100 mb-2">CSV Reconciliation</a>
        <a href="/admin/orders.php" class="btn btn-outline-primary w-100 mb-2">Orders</a>
        <a href="/admin/reports.php" class="btn btn-outline-primary w-100 mb-2">Reports</a>
        <a href="/admin/analytics.php" class="btn btn-outline-primary w-100 mb-2">Analytics</a>
        <a href="/admin/export.php" class="btn btn-outline-primary w-100 mb-2">Export Data</a>
        <a href="/admin/settings.php" class="btn btn-outline-primary w-100 mb-2">Settings</a>
        <a href="/admin/menu.php" class="btn btn-outline-primary w-100 mb-2">Menu Manager</a>
        <a href="/admin/schedule.php" class="btn btn-outline-primary w-100 mb-2">Weekly Schedule &amp; Holidays</a>
        <a href="/admin/manage-staff.php" class="btn btn-outline-primary w-100 mb-2">Manage Staff</a>

    </div>
</div>
<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>