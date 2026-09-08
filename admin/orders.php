<?php
/**
 * Admin Orders dashboard — the real thing this time. Every order as a
 * card: search across ALL dates by name/phone/HRMS ID/order ID, or
 * browse day-by-day (defaults to today) with prev/next + jump-to-date.
 * Inline override controls right on each card — same logic as
 * order-override.php, just embedded per-card instead of a separate
 * lookup page.
 *
 * super_admin only. Receptionist accounts can never reach this page —
 * counter/dashboard.php has zero browsing/search capability by design,
 * so this is the ONLY place in the whole app where a serving code is
 * ever shown outside of the employee's own My Bookings page. Keeping
 * it locked to super_admin is what preserves that boundary.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/meal_rules.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}
if ($_SESSION['admin_role'] !== 'super_admin') {
    http_response_code(403);
    die('Access denied.');
}

$STATUS_LABELS = [
    'PENDING_CLEARANCE' => ['label' => 'Pending', 'class' => 'warning'],
    'APPROVED'          => ['label' => 'Approved', 'class' => 'success'],
    'SERVED'            => ['label' => 'Served', 'class' => 'secondary'],
    'REJECTED'          => ['label' => 'Rejected', 'class' => 'danger'],
];

$overrideMessage = null;
$overrideError = null;

// ------------------------------------------------------------
// Handle an inline override submission from one of the cards below.
// Same logic as order-override.php — kept here too since overrides
// happen directly from this list now, not a separate lookup page.
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['override']) && csrf_verify()) {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $newStatus = $_POST['new_status'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if (!isset($STATUS_LABELS[$newStatus])) {
        $overrideError = "Invalid target status.";
    } elseif ($reason === '') {
        $overrideError = "A reason is required for every override.";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
        $stmt->execute([':id' => $orderId]);
        $target = $stmt->fetch();

        if (!$target) {
            $overrideError = "Order not found.";
        } else {
            try {
                $pdo->beginTransaction();

                $extraSql = '';
                $extraParams = [];
                if ($newStatus === 'APPROVED') {
                    $extraSql = ', approved_at = NOW()';
                } elseif ($newStatus === 'REJECTED') {
                    $extraSql = ', rejected_at = NOW()';
                } elseif ($newStatus === 'SERVED') {
                    $extraSql = ', served_at = NOW(), served_by_admin_id = :admin_id';
                    $extraParams[':admin_id'] = $_SESSION['admin_id'];
                }

                $updateStmt = $pdo->prepare("UPDATE orders SET status = :status{$extraSql} WHERE id = :id");
                $updateStmt->execute(array_merge([':status' => $newStatus, ':id' => $orderId], $extraParams));

                $details = "Token #{$orderId}: {$target['status']} -> {$newStatus}. Reason: {$reason}";
                $pdo->prepare(
                    "INSERT INTO audit_log (admin_id, action_type, order_id, details)
                     VALUES (:admin_id, 'STATUS_OVERRIDE', :order_id, :details)"
                )->execute([':admin_id' => $_SESSION['admin_id'], ':order_id' => $orderId, ':details' => $details]);

                $pdo->commit();
                $overrideMessage = "Token #{$orderId} updated to " . $STATUS_LABELS[$newStatus]['label'] . ".";
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('KPAW override failed: ' . $e->getMessage());
                $overrideError = "Something went wrong saving this change. Please try again.";
            }
        }
    }
}

// ------------------------------------------------------------
// Build the order list: search mode (any date) or browse mode
// (one specific date, defaulting to today).
// ------------------------------------------------------------
$search = trim($_GET['search'] ?? '');
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$mealFilter = $_GET['meal'] ?? '';
if (!in_array($mealFilter, ['breakfast', 'lunch', 'snacks'], true)) {
    $mealFilter = '';
}

$baseSql = "SELECT o.*, c.name AS canteen_name, c.brand_name,
                   COALESCE(u.full_name, g.full_name) AS customer_name,
                   u.hrms_id AS hrms_id, u.phone AS employee_phone,
                   g.phone AS guest_phone
            FROM orders o
            JOIN canteens c ON c.id = o.canteen_id
            LEFT JOIN users u ON o.user_type = 'employee' AND o.user_id = u.id
            LEFT JOIN guests g ON o.user_type = 'guest' AND o.user_id = g.id";

$params = [];
if ($search !== '') {
    $sql = $baseSql . " WHERE (u.full_name LIKE :like OR g.full_name LIKE :like2
                              OR u.hrms_id LIKE :like3 OR g.phone LIKE :like4
                              OR o.id = :exact)";
    $likeParam = '%' . $search . '%';
    $params = [
        ':like' => $likeParam, ':like2' => $likeParam, ':like3' => $likeParam, ':like4' => $likeParam,
        ':exact' => ctype_digit($search) ? (int) $search : 0,
    ];
} else {
    $sql = $baseSql . " WHERE o.order_date = :date";
    $params = [':date' => $selectedDate];
}
if ($mealFilter !== '') {
    $sql .= " AND o.meal_type = :meal";
    $params[':meal'] = $mealFilter;
}
$sql .= $search !== '' ? " ORDER BY o.created_at DESC LIMIT 200" : " ORDER BY o.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Quick status summary — only meaningful for the date-browse view
// (a search's results aren't "today's status" in the same sense).
$summaryCounts = ['PENDING_CLEARANCE' => 0, 'APPROVED' => 0, 'REJECTED' => 0, 'SERVED' => 0];
if ($search === '') {
    foreach ($orders as $o) {
        if (isset($summaryCounts[$o['status']])) {
            $summaryCounts[$o['status']]++;
        }
    }
}

// Batch-fetch items for every order shown, same pattern as My Bookings.
$itemsByOrder = [];
if ($orders) {
    $orderIds = array_column($orders, 'id');
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id IN ($placeholders)");
    $itemsStmt->execute($orderIds);
    foreach ($itemsStmt->fetchAll() as $line) {
        $itemsByOrder[$line['order_id']][] = $line;
    }
}

$timingRules = get_meal_timing_rules($pdo);

$adminContainerMaxWidth = 960;
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Orders</h5>

<?php if ($overrideMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($overrideMessage) ?></div>
<?php endif; ?>
<?php if ($overrideError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($overrideError) ?></div>
<?php endif; ?>

<form method="get" class="d-flex gap-2 mb-2">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
           placeholder="Search name, phone, HRMS ID, or token number" class="form-control">
    <button type="submit" class="btn btn-primary">Search</button>
    <?php if ($search !== ''): ?>
        <a href="/admin/orders.php" class="btn btn-outline-secondary">Clear</a>
    <?php endif; ?>
</form>

<form method="get" class="mb-3">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
    <select name="meal" class="form-select form-select-sm kpaw-meal-filter" style="max-width: 200px;" onchange="this.form.submit()">
        <option value="">All meals</option>
        <option value="breakfast" <?= $mealFilter === 'breakfast' ? 'selected' : '' ?>>Breakfast</option>
        <option value="lunch" <?= $mealFilter === 'lunch' ? 'selected' : '' ?>>Lunch</option>
        <option value="snacks" <?= $mealFilter === 'snacks' ? 'selected' : '' ?>>Snacks</option>
    </select>
</form>

<?php if ($search === ''): ?>
    <div class="kpaw-summary-bar mb-3">
        <div class="kpaw-summary-stat kpaw-summary-stat--warning">
            <div class="kpaw-summary-stat__num"><?= $summaryCounts['PENDING_CLEARANCE'] ?></div>
            <div class="kpaw-summary-stat__label">Pending</div>
        </div>
        <div class="kpaw-summary-stat kpaw-summary-stat--success">
            <div class="kpaw-summary-stat__num"><?= $summaryCounts['APPROVED'] ?></div>
            <div class="kpaw-summary-stat__label">Approved</div>
        </div>
        <div class="kpaw-summary-stat kpaw-summary-stat--danger">
            <div class="kpaw-summary-stat__num"><?= $summaryCounts['REJECTED'] ?></div>
            <div class="kpaw-summary-stat__label">Rejected</div>
        </div>
        <div class="kpaw-summary-stat kpaw-summary-stat--secondary">
            <div class="kpaw-summary-stat__num"><?= $summaryCounts['SERVED'] ?></div>
            <div class="kpaw-summary-stat__label">Served</div>
        </div>
        <div class="kpaw-summary-stat">
            <div class="kpaw-summary-stat__num"><?= count($orders) ?></div>
            <div class="kpaw-summary-stat__label">Total</div>
        </div>
    </div>
<?php endif; ?>

    <?php
    $prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
    $nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
    ?>
    <?php if ($search === ''): ?>
    <div class="d-flex align-items-center gap-2 mb-3 kpaw-date-bar">
        <a href="?date=<?= $prevDate ?>&meal=<?= $mealFilter ?>" class="btn btn-outline-secondary btn-sm">&larr;</a>
        <div class="flex-grow-1 text-center kpaw-mono fw-semibold"><?= $selectedDate === date('Y-m-d') ? 'Today' : htmlspecialchars(date('d-m-Y', strtotime($selectedDate))) ?></div>
        <a href="?date=<?= $nextDate ?>&meal=<?= $mealFilter ?>" class="btn btn-outline-secondary btn-sm">&rarr;</a>
        <form method="get" class="d-flex gap-1">
            <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" class="form-control form-control-sm">
            <button type="submit" class="btn btn-outline-primary btn-sm">Go</button>
        </form>
    </div>
<?php endif; ?>

<?php if (!$orders): ?>
    <div class="alert alert-info">No orders found<?= $search !== '' ? ' for that search' : ' for this date' ?>.</div>
<?php endif; ?>

<?php foreach ($orders as $order):
    $meta = $STATUS_LABELS[$order['status']] ?? ['label' => $order['status'], 'class' => 'secondary'];
    $displayStatus = $order['status'];
    if (in_array($displayStatus, ['PENDING_CLEARANCE', 'APPROVED'], true)) {
        $rule = $timingRules[$order['meal_type']] ?? null;
        if ($rule) {
            $serveEndAt = new DateTime($order['order_date'] . ' ' . $rule['serve_end']);
            if (new DateTime('now') > $serveEndAt) {
                $displayStatus = 'EXPIRED';
            }
        }
    }
?>
    <?php
    $statusClass = $displayStatus === 'EXPIRED' ? 'dark' : $meta['class'];
    $statusLabel = $displayStatus === 'EXPIRED' ? 'Expired' : $meta['label'];
    ?>
    <div class="kpaw-order-card kpaw-order-card--<?= $statusClass ?> mb-3">
        <div class="kpaw-order-card__header">
            <div class="d-flex align-items-center gap-2">
                <span class="kpaw-order-card__dot"></span>
                <span class="kpaw-order-card__status"><?= htmlspecialchars($statusLabel) ?></span>
                <span class="kpaw-mono small">Token #<?= (int) $order['id'] ?></span>
                <?php if ($displayStatus === 'EXPIRED'): ?>
                    <span class="small">(stored: <?= htmlspecialchars($meta['label']) ?> — window closed)</span>
                <?php endif; ?>
            </div>
            <div class="kpaw-mono small"><?= htmlspecialchars(date('d-m-Y g:i A', strtotime($order['created_at']))) ?></div>
        </div>

        <div class="kpaw-order-card__body">
            <div class="row g-2">
                <div class="col-8">
                    <p class="kpaw-order-card__name mb-0">
                        <?= htmlspecialchars($order['customer_name'] ?? 'Unknown') ?>
                    </p>
                    <p class="mb-0 small text-muted">
                        <?php if ($order['user_type'] === 'employee'): ?>
                            Employee &middot; <?= htmlspecialchars($order['hrms_id'] ?? '') ?><?= !empty($order['employee_phone']) ? ' &middot; ' . htmlspecialchars($order['employee_phone']) : '' ?>
                        <?php else: ?>
                            Guest <?= !empty($order['guest_phone']) ? '&middot; ' . htmlspecialchars($order['guest_phone']) : '' ?>
                        <?php endif; ?>
                    </p>
                    <p class="mb-0 small text-muted mt-1">
                        <?= htmlspecialchars($order['canteen_name']) ?> &middot;
                        <?= htmlspecialchars(ucfirst($order['meal_type'])) ?> &middot;
                        <?= htmlspecialchars(format_date_with_day($order['order_date'])) ?>
                    </p>
                    <?php if (!empty($itemsByOrder[$order['id']])): ?>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            <?php foreach ($itemsByOrder[$order['id']] as $line): ?>
                                <span class="kpaw-item-pill"><?= htmlspecialchars($line['item_name']) ?> <span class="kpaw-item-pill__qty">&times;<?= (int) $line['quantity'] ?></span></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="col-4 text-end">
                    <div class="kpaw-mono fw-bold fs-6">&#8377;<?= number_format((float) $order['amount'], 2) ?></div>
                    <?php if ($order['utr_number']): ?><div class="kpaw-mono small text-muted mt-1">UTR <?= htmlspecialchars($order['utr_number']) ?></div><?php endif; ?>
                    <?php if ($order['bank_payment_id']): ?><div class="kpaw-mono small text-muted">Pay ID <?= htmlspecialchars($order['bank_payment_id']) ?></div><?php endif; ?>
                    <?php if ($order['serving_code']): ?><div class="kpaw-mono small text-muted">Code <?= htmlspecialchars($order['serving_code']) ?></div><?php endif; ?>
                </div>
            </div>

            <?php if (!empty($order['last_recon_note'])): ?>
                <div class="kpaw-order-card__note mt-2">&#9888;&#65039; <?= htmlspecialchars($order['last_recon_note']) ?></div>
            <?php endif; ?>

            <div class="text-end mt-2">
                <button type="button" class="kpaw-order-card__manage-btn kpaw-order-card__manage-btn--<?= $statusClass ?>"
                        onclick="document.getElementById('kpaw-override-<?= (int) $order['id'] ?>').classList.toggle('d-none')">
                    Manage &rsaquo;
                </button>
            </div>

            <form method="post" id="kpaw-override-<?= (int) $order['id'] ?>" class="d-flex gap-2 mt-2 d-none">
                <?= csrf_field() ?>
                <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
                <select name="new_status" class="form-select form-select-sm" required style="max-width: 150px;">
                    <option value="">Change status...</option>
                    <?php foreach ($STATUS_LABELS as $value => $info): ?>
                        <?php if ($value !== $order['status']): ?>
                            <option value="<?= $value ?>"><?= $info['label'] ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="reason" placeholder="Reason (required)" class="form-control form-control-sm" required>
                <button type="submit" name="override" value="1" class="btn btn-danger btn-sm">Override</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>