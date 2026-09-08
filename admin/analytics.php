<?php
/**
 * Admin Analytics — registration totals (including "Active" — anyone
 * who's placed at least one confirmed order, vs registered but never
 * used), a full list of every employee/guest with their order count,
 * day-wise registration counts over a chosen date range, and weekly
 * order counts as a plain table. No chart — removed per request,
 * numbers in tables instead.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}
if ($_SESSION['admin_role'] !== 'super_admin') {
    http_response_code(403);
    die('Access denied.');
}

$totalEmployees = (int) $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$totalGuests = (int) $pdo->query("SELECT COUNT(*) FROM guests")->fetchColumn();
$orderTotalsRow = $pdo->query(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total FROM orders WHERE status IN ('APPROVED', 'SERVED')"
)->fetch();
$activeUsers = (int) $pdo->query(
    "SELECT COUNT(DISTINCT CONCAT(user_type, '-', user_id)) FROM orders WHERE status IN ('APPROVED', 'SERVED')"
)->fetchColumn();

$canteens = $pdo->query("SELECT * FROM canteens WHERE is_active = 1 ORDER BY id")->fetchAll();
$canteenStatsStmt = $pdo->prepare(
    "SELECT COUNT(o.id) AS order_count, COALESCE(SUM(o.amount), 0) AS revenue
     FROM orders o WHERE o.canteen_id = :cid AND o.status IN ('APPROVED', 'SERVED')"
);
$canteenStats = [];
foreach ($canteens as $c) {
    $canteenStatsStmt->execute([':cid' => $c['id']]);
    $canteenStats[$c['id']] = $canteenStatsStmt->fetch();
}

// ------------------------------------------------------------
// Search — narrows the full people list below
// ------------------------------------------------------------
$search = trim($_GET['search'] ?? '');

$peopleSql = "SELECT 'employee' AS user_type, id, full_name, hrms_id AS identifier, phone, created_at FROM users";
$peopleSql .= $search !== '' ? " WHERE full_name LIKE :q1 OR hrms_id LIKE :q2 OR phone LIKE :q3" : "";
$peopleSql .= " UNION ALL SELECT 'guest' AS user_type, id, full_name, phone AS identifier, phone, created_at FROM guests";
$peopleSql .= $search !== '' ? " WHERE full_name LIKE :q4 OR phone LIKE :q5" : "";
$peopleSql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($peopleSql);
if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like]);
} else {
    $stmt->execute();
}
$people = $stmt->fetchAll();

$orderCountStmt = $pdo->prepare(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS total FROM orders
     WHERE user_type = :type AND user_id = :id AND status IN ('APPROVED', 'SERVED')"
);
foreach ($people as &$p) {
    $orderCountStmt->execute([':type' => $p['user_type'], ':id' => $p['id']]);
    $stats = $orderCountStmt->fetch();
    $p['order_count'] = $stats['cnt'];
    $p['total_spent'] = $stats['total'];
}
unset($p);

// ------------------------------------------------------------
// Orders by status, over a chosen date range (defaults to the last
// 7 days) — the "how's this week looking, broken down" question.
// ------------------------------------------------------------
$ordFrom = $_GET['ord_from'] ?? date('Y-m-d', strtotime('-6 days'));
$ordTo = $_GET['ord_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ordFrom)) { $ordFrom = date('Y-m-d', strtotime('-6 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ordTo)) { $ordTo = date('Y-m-d'); }

$statusCountStmt = $pdo->prepare(
    "SELECT status, COUNT(*) AS cnt FROM orders WHERE order_date BETWEEN :f AND :t GROUP BY status"
);
$statusCountStmt->execute([':f' => $ordFrom, ':t' => $ordTo]);
$statusCounts = array_column($statusCountStmt->fetchAll(), 'cnt', 'status');
$rangeTotal = array_sum($statusCounts);

// ------------------------------------------------------------
// Top 10 employees by confirmed order count, all-time
// ------------------------------------------------------------
$topEmployeesStmt = $pdo->query(
    "SELECT u.full_name, u.hrms_id, COUNT(o.id) AS order_count, COALESCE(SUM(o.amount), 0) AS total_spent
     FROM orders o JOIN users u ON o.user_type = 'employee' AND o.user_id = u.id
     WHERE o.status IN ('APPROVED', 'SERVED')
     GROUP BY u.id ORDER BY order_count DESC LIMIT 10"
);
$topEmployees = $topEmployeesStmt->fetchAll();

// ------------------------------------------------------------
// Registrations by day, over a chosen date range (defaults to the
// last 14 days)
// ------------------------------------------------------------
$regFrom = $_GET['reg_from'] ?? date('Y-m-d', strtotime('-14 days'));
$regTo = $_GET['reg_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $regFrom)) { $regFrom = date('Y-m-d', strtotime('-14 days')); }
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $regTo)) { $regTo = date('Y-m-d'); }

$empRegStmt = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS cnt FROM users WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY d");
$empRegStmt->execute([':f' => $regFrom, ':t' => $regTo]);
$empRegByDay = array_column($empRegStmt->fetchAll(), 'cnt', 'd');

$guestRegStmt = $pdo->prepare("SELECT DATE(created_at) AS d, COUNT(*) AS cnt FROM guests WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY d");
$guestRegStmt->execute([':f' => $regFrom, ':t' => $regTo]);
$guestRegByDay = array_column($guestRegStmt->fetchAll(), 'cnt', 'd');

$regDays = [];
$cursor = strtotime($regTo);
$endTs = strtotime($regFrom);
while ($cursor >= $endTs) {
    $d = date('Y-m-d', $cursor);
    $regDays[] = ['date' => $d, 'employees' => $empRegByDay[$d] ?? 0, 'guests' => $guestRegByDay[$d] ?? 0];
    $cursor = strtotime('-1 day', $cursor);
}

// ------------------------------------------------------------
// Weekly order counts — plain table, last 12 weeks
// ------------------------------------------------------------
$weeklyStmt = $pdo->query(
    "SELECT YEARWEEK(order_date, 1) AS wk, MIN(order_date) AS week_start, COUNT(*) AS cnt, COALESCE(SUM(amount), 0) AS revenue
     FROM orders WHERE status IN ('APPROVED', 'SERVED') AND order_date >= (CURDATE() - INTERVAL 12 WEEK)
     GROUP BY wk ORDER BY wk DESC"
);
$weeklyOrders = $weeklyStmt->fetchAll();

$adminContainerMaxWidth = 1200;
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Analytics</h5>

<div class="row g-3 mb-4">
    <div class="col-3">
        <div class="kpaw-summary-stat">
            <div class="kpaw-summary-stat__num"><?= $totalEmployees ?></div>
            <div class="kpaw-summary-stat__label">Employees</div>
        </div>
    </div>
    <div class="col-3">
        <div class="kpaw-summary-stat">
            <div class="kpaw-summary-stat__num"><?= $totalGuests ?></div>
            <div class="kpaw-summary-stat__label">Guests</div>
        </div>
    </div>
    <div class="col-3">
        <div class="kpaw-summary-stat kpaw-summary-stat--success">
            <div class="kpaw-summary-stat__num"><?= $activeUsers ?></div>
            <div class="kpaw-summary-stat__label">Active Users</div>
        </div>
    </div>
    <div class="col-3">
        <div class="kpaw-summary-stat">
            <div class="kpaw-summary-stat__num kpaw-mono" style="font-size: 1rem;">&#8377;<?= number_format((float) $orderTotalsRow['total'], 0) ?></div>
            <div class="kpaw-summary-stat__label">Revenue</div>
        </div>
    </div>
</div>
<p class="small text-muted mb-4">"Active" means at least one confirmed (Approved/Served) order ever — registered but never ordered doesn't count.</p>

<h6 class="mb-2">Registrations by Day</h6>
<form method="get" class="d-flex gap-2 mb-3">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="ord_from" value="<?= htmlspecialchars($ordFrom) ?>">
    <input type="hidden" name="ord_to" value="<?= htmlspecialchars($ordTo) ?>">
    <input type="date" name="reg_from" value="<?= htmlspecialchars($regFrom) ?>" class="form-control form-control-sm" style="max-width: 160px;">
    <input type="date" name="reg_to" value="<?= htmlspecialchars($regTo) ?>" class="form-control form-control-sm" style="max-width: 160px;">
    <button type="submit" class="btn btn-outline-primary btn-sm">Go</button>
</form>
<div class="table-responsive mb-4" style="max-height: 300px; overflow-y: auto;">
    <table class="table table-sm">
        <thead><tr><th>Date</th><th class="text-end">Employees</th><th class="text-end">Guests</th><th class="text-end">Total</th></tr></thead>
        <tbody>
            <?php foreach ($regDays as $d): ?>
                <tr>
                    <td class="kpaw-mono"><?= htmlspecialchars(date('d-m-Y', strtotime($d['date']))) ?></td>
                    <td class="text-end kpaw-mono"><?= $d['employees'] ?></td>
                    <td class="text-end kpaw-mono"><?= $d['guests'] ?></td>
                    <td class="text-end kpaw-mono fw-bold"><?= $d['employees'] + $d['guests'] ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h6 class="mb-2">Orders by Status</h6>
<form method="get" class="d-flex gap-2 mb-3">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="reg_from" value="<?= htmlspecialchars($regFrom) ?>">
    <input type="hidden" name="reg_to" value="<?= htmlspecialchars($regTo) ?>">
    <input type="date" name="ord_from" value="<?= htmlspecialchars($ordFrom) ?>" class="form-control form-control-sm" style="max-width: 160px;">
    <input type="date" name="ord_to" value="<?= htmlspecialchars($ordTo) ?>" class="form-control form-control-sm" style="max-width: 160px;">
    <button type="submit" class="btn btn-outline-primary btn-sm">Go</button>
</form>
<div class="row g-2 mb-4">
    <div class="col-3">
        <div class="kpaw-summary-stat kpaw-summary-stat--warning">
            <div class="kpaw-summary-stat__num"><?= $statusCounts['PENDING_CLEARANCE'] ?? 0 ?></div>
            <div class="kpaw-summary-stat__label">Pending</div>
        </div>
    </div>
    <div class="col-3">
        <div class="kpaw-summary-stat kpaw-summary-stat--success">
            <div class="kpaw-summary-stat__num"><?= $statusCounts['APPROVED'] ?? 0 ?></div>
            <div class="kpaw-summary-stat__label">Approved</div>
        </div>
    </div>
    <div class="col-3">
        <div class="kpaw-summary-stat kpaw-summary-stat--danger">
            <div class="kpaw-summary-stat__num"><?= $statusCounts['REJECTED'] ?? 0 ?></div>
            <div class="kpaw-summary-stat__label">Rejected</div>
        </div>
    </div>
    <div class="col-3">
        <div class="kpaw-summary-stat">
            <div class="kpaw-summary-stat__num"><?= $rangeTotal ?></div>
            <div class="kpaw-summary-stat__label">All (incl. Served)</div>
        </div>
    </div>
</div>

<h6 class="mb-2">Top 10 Employees (all-time, confirmed orders)</h6>
<div class="table-responsive mb-4">
    <table class="table table-sm">
        <thead><tr><th>#</th><th>Name</th><th>HRMS ID</th><th class="text-end">Orders</th><th class="text-end">Total Spent</th></tr></thead>
        <tbody>
            <?php foreach ($topEmployees as $i => $e): ?>
                <tr>
                    <td class="kpaw-mono"><?= $i + 1 ?></td>
                    <td><?= htmlspecialchars($e['full_name']) ?></td>
                    <td class="kpaw-mono small"><?= htmlspecialchars($e['hrms_id']) ?></td>
                    <td class="text-end kpaw-mono"><?= (int) $e['order_count'] ?></td>
                    <td class="text-end kpaw-mono">&#8377;<?= number_format((float) $e['total_spent'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topEmployees): ?><tr><td colspan="5" class="text-muted small">No confirmed orders yet.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<h6 class="mb-2">Weekly Orders (last 12 weeks)</h6>
<div class="table-responsive mb-4">
    <table class="table table-sm">
        <thead><tr><th>Week Starting</th><th class="text-end">Orders</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
            <?php foreach ($weeklyOrders as $w): ?>
                <tr>
                    <td class="kpaw-mono"><?= htmlspecialchars(date('d-m-Y', strtotime($w['week_start']))) ?></td>
                    <td class="text-end kpaw-mono"><?= (int) $w['cnt'] ?></td>
                    <td class="text-end kpaw-mono">&#8377;<?= number_format((float) $w['revenue'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$weeklyOrders): ?><tr><td colspan="3" class="text-muted small">No confirmed orders in the last 12 weeks.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<h6 class="mb-2">By Canteen (all-time)</h6>
<div class="table-responsive mb-4">
    <table class="table table-sm">
        <thead><tr><th>Canteen</th><th class="text-end">Orders</th><th class="text-end">Revenue</th></tr></thead>
        <tbody>
            <?php foreach ($canteens as $c): ?>
                <tr>
                    <td><?= htmlspecialchars($c['name']) ?></td>
                    <td class="text-end kpaw-mono"><?= (int) $canteenStats[$c['id']]['order_count'] ?></td>
                    <td class="text-end kpaw-mono">&#8377;<?= number_format((float) $canteenStats[$c['id']]['revenue'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<h6 class="mb-2">All Registered Users (<?= count($people) ?>)</h6>
<form method="get" class="d-flex gap-2 mb-3">
    <input type="hidden" name="reg_from" value="<?= htmlspecialchars($regFrom) ?>">
    <input type="hidden" name="reg_to" value="<?= htmlspecialchars($regTo) ?>">
    <input type="hidden" name="ord_from" value="<?= htmlspecialchars($ordFrom) ?>">
    <input type="hidden" name="ord_to" value="<?= htmlspecialchars($ordTo) ?>">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, HRMS ID, or phone" class="form-control">
    <button type="submit" class="btn btn-primary">Search</button>
    <?php if ($search !== ''): ?><a href="/admin/analytics.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
</form>
<div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
    <table class="table table-sm table-striped">
        <thead>
            <tr><th>Name</th><th>Type</th><th>HRMS / Phone</th><th>Registered</th><th class="text-end">Orders</th><th class="text-end">Total Spent</th></tr>
        </thead>
        <tbody>
            <?php foreach ($people as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['full_name']) ?></td>
                    <td><span class="badge bg-<?= $p['user_type'] === 'employee' ? 'primary' : 'secondary' ?>"><?= ucfirst($p['user_type']) ?></span></td>
                    <td class="kpaw-mono small"><?= htmlspecialchars($p['identifier']) ?></td>
                    <td class="kpaw-mono small"><?= htmlspecialchars(date('d-m-Y', strtotime($p['created_at']))) ?></td>
                    <td class="text-end kpaw-mono"><?= (int) $p['order_count'] ?></td>
                    <td class="text-end kpaw-mono">&#8377;<?= number_format((float) $p['total_spent'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$people): ?><tr><td colspan="6" class="text-muted small">No one found.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>