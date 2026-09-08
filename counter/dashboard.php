<?php
/**
 * Receptionist dashboard — full card list, same visual language as
 * admin/orders.php, but deliberately different in two ways:
 *
 * 1. Hard-scoped to the receptionist's own assigned canteen — every
 *    query below filters on $_SESSION['admin_canteen_id'], no way to
 *    see or search another canteen's orders.
 * 2. NO serving code, UTR, or payment ID shown anywhere on the list —
 *    a receptionist can browse and see who's who, but the only way to
 *    actually mark something Served is entering the code the
 *    employee just told them, in a confirmation popup. Browsing the
 *    list alone can never serve anyone.
 *
 * Mobile-first — this is where receptionists will actually use it
 * day to day.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/meal_rules.php';

if (empty($_SESSION['admin_id']) || $_SESSION['admin_role'] !== 'receptionist') {
    header('Location: /admin/login.php');
    exit;
}

$myCanteenId = (int) ($_SESSION['admin_canteen_id'] ?? 0);
if (!$myCanteenId) {
    session_destroy();
    header('Location: /admin/login.php');
    exit;
}

$canteenStmt = $pdo->prepare("SELECT * FROM canteens WHERE id = :id");
$canteenStmt->execute([':id' => $myCanteenId]);
$myCanteen = $canteenStmt->fetch();

$serveMessage = null;
$serveError = null;
$retryOrderId = null; // set on failure, so the modal can re-open pre-filled

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_serve']) && csrf_verify()) {
    $orderId = (int) ($_POST['order_id'] ?? 0);
    $enteredCode = trim($_POST['entered_code'] ?? '');
    $retryOrderId = $orderId;

    if (!preg_match('/^\d{6}$/', $enteredCode)) {
        $serveError = "Enter the 6-digit code exactly as given.";
    } else {
        $stmt = $pdo->prepare(
            "UPDATE orders SET status = 'SERVED', served_at = NOW(), served_by_admin_id = :admin_id
             WHERE id = :id AND canteen_id = :canteen_id AND serving_code = :code AND status = 'APPROVED'"
        );
        $stmt->execute([
            ':admin_id'   => $_SESSION['admin_id'],
            ':id'         => $orderId,
            ':canteen_id' => $myCanteenId,
            ':code'       => $enteredCode,
        ]);

        if ($stmt->rowCount() > 0) {
            $serveMessage = "Marked as served.";
            $retryOrderId = null;
        } else {
            $serveError = "That code doesn't match this order, or its status has changed. Please check with the employee and try again.";
        }
    }
}

$search = trim($_GET['search'] ?? '');
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}
$mealFilter = $_GET['meal'] ?? '';
if (!in_array($mealFilter, ['breakfast', 'lunch', 'snacks'], true)) {
    $mealFilter = '';
}

$baseSql = "SELECT o.*, COALESCE(u.full_name, g.full_name) AS customer_name,
                   u.hrms_id AS hrms_id, u.phone AS employee_phone, g.phone AS guest_phone
            FROM orders o
            LEFT JOIN users u ON o.user_type = 'employee' AND o.user_id = u.id
            LEFT JOIN guests g ON o.user_type = 'guest' AND o.user_id = g.id
            WHERE o.canteen_id = :canteen_id";

$params = [':canteen_id' => $myCanteenId];

if ($search !== '') {
    $sql = $baseSql . " AND (u.full_name LIKE :like OR g.full_name LIKE :like2
                              OR u.hrms_id LIKE :like3 OR g.phone LIKE :like4 OR o.id = :exact)";
    $likeParam = '%' . $search . '%';
    $params += [':like' => $likeParam, ':like2' => $likeParam, ':like3' => $likeParam, ':like4' => $likeParam,
                ':exact' => ctype_digit($search) ? (int) $search : 0];
} else {
    $sql = $baseSql . " AND o.order_date = :date";
    $params[':date'] = $selectedDate;
}

if ($mealFilter !== '') {
    $sql .= " AND o.meal_type = :meal";
    $params[':meal'] = $mealFilter;
}

$sql .= $search !== '' ? " ORDER BY o.created_at DESC LIMIT 100" : " ORDER BY o.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$summaryCounts = ['PENDING_CLEARANCE' => 0, 'APPROVED' => 0, 'REJECTED' => 0, 'SERVED' => 0];
if ($search === '') {
    foreach ($orders as $o) {
        if (isset($summaryCounts[$o['status']])) {
            $summaryCounts[$o['status']]++;
        }
    }
}

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

$STATUS_LABELS = [
    'PENDING_CLEARANCE' => ['label' => 'Pending', 'class' => 'warning'],
    'APPROVED'          => ['label' => 'Approved', 'class' => 'success'],
    'SERVED'            => ['label' => 'Served', 'class' => 'secondary'],
    'REJECTED'          => ['label' => 'Rejected', 'class' => 'danger'],
];

require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-1">Counter — <?= htmlspecialchars($myCanteen['name']) ?></h5>
<p class="text-muted small mb-3"><?= htmlspecialchars($myCanteen['brand_name']) ?></p>
<a href="/counter/reports.php" class="btn btn-outline-primary btn-sm mb-3">Reports</a>

<?php if ($serveMessage): ?><div class="alert alert-success"><?= htmlspecialchars($serveMessage) ?></div><?php endif; ?>

<form method="get" class="d-flex gap-2 mb-2">
    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
           placeholder="Search name, phone, HRMS ID, or token number" class="form-control">
    <button type="submit" class="btn btn-primary">Go</button>
    <?php if ($search !== ''): ?><a href="/counter/dashboard.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
</form>

<form method="get" class="mb-3">
    <input type="hidden" name="search" value="<?= htmlspecialchars($search) ?>">
    <input type="hidden" name="date" value="<?= htmlspecialchars($selectedDate) ?>">
    <select name="meal" class="form-select form-select-sm kpaw-meal-filter" onchange="this.form.submit()">
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
    <?php
    $prevDate = date('Y-m-d', strtotime($selectedDate . ' -1 day'));
    $nextDate = date('Y-m-d', strtotime($selectedDate . ' +1 day'));
    ?>
    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="?date=<?= $prevDate ?>&meal=<?= $mealFilter ?>" class="btn btn-outline-secondary btn-sm">&larr;</a>
        <div class="flex-grow-1 text-center kpaw-mono fw-semibold"><?= $selectedDate === date('Y-m-d') ? 'Today' : htmlspecialchars(date('d-m-Y', strtotime($selectedDate))) ?></div>
        <a href="?date=<?= $nextDate ?>&meal=<?= $mealFilter ?>" class="btn btn-outline-secondary btn-sm">&rarr;</a>
        <form method="get" class="d-flex gap-1">
            <input type="hidden" name="meal" value="<?= $mealFilter ?>">
            <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" class="form-control form-control-sm">
            <button type="submit" class="btn btn-outline-primary btn-sm">Go</button>
        </form>
    </div>
<?php endif; ?>

<?php if (!$orders): ?>
    <div class="alert alert-info">No tokens found<?= $search !== '' ? ' for that search' : ' for this date' ?>.</div>
<?php endif; ?>

<?php foreach ($orders as $order):
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
    $meta = $STATUS_LABELS[$order['status']] ?? ['label' => $order['status'], 'class' => 'secondary'];
    $statusClass = $displayStatus === 'EXPIRED' ? 'dark' : $meta['class'];
    $statusLabel = $displayStatus === 'EXPIRED' ? 'Expired' : $meta['label'];
?>
    <div class="kpaw-order-card kpaw-order-card--<?= $statusClass ?> mb-3">
        <div class="kpaw-order-card__header">
            <div class="d-flex align-items-center gap-2">
                <span class="kpaw-order-card__dot"></span>
                <span class="kpaw-order-card__status"><?= htmlspecialchars($statusLabel) ?></span>
                <span class="kpaw-mono small">Token #<?= (int) $order['id'] ?></span>
            </div>
            <div class="kpaw-mono small"><?= htmlspecialchars(date('d-m-Y g:i A', strtotime($order['created_at']))) ?></div>
        </div>

        <div class="kpaw-order-card__body">
            <p class="kpaw-order-card__name mb-0"><?= htmlspecialchars($order['customer_name'] ?? 'Unknown') ?></p>
            <p class="mb-0 small text-muted">
                <?php if ($order['user_type'] === 'employee'): ?>
                    Employee &middot; <?= htmlspecialchars($order['hrms_id'] ?? '') ?><?= !empty($order['employee_phone']) ? ' &middot; ' . htmlspecialchars($order['employee_phone']) : '' ?>
                <?php else: ?>
                    Guest <?= !empty($order['guest_phone']) ? '&middot; ' . htmlspecialchars($order['guest_phone']) : '' ?>
                <?php endif; ?>
            </p>
            <p class="mb-0 small text-muted mt-1">
                <?= htmlspecialchars(ucfirst($order['meal_type'])) ?> &middot;
                <?= htmlspecialchars(format_date_with_day($order['order_date'])) ?>
                <?php if (isset($timingRules[$order['meal_type']])):
                    $r = $timingRules[$order['meal_type']];
                ?>
                    &middot; <?= htmlspecialchars(date('g:i', strtotime($r['serve_start']))) ?>&ndash;<?= htmlspecialchars(date('g:i A', strtotime($r['serve_end']))) ?>
                <?php endif; ?>
            </p>
            <?php if (!empty($itemsByOrder[$order['id']])): ?>
                <div class="d-flex flex-wrap gap-1 mt-2">
                    <?php foreach ($itemsByOrder[$order['id']] as $line): ?>
                        <span class="kpaw-item-pill"><?= htmlspecialchars($line['item_name']) ?> <span class="kpaw-item-pill__qty">&times;<?= (int) $line['quantity'] ?></span></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="d-flex gap-3 mt-2 kpaw-mono small text-muted">
                <span class="fw-bold">&#8377;<?= number_format((float) $order['amount'], 2) ?></span>
                <?php if ($order['utr_number']): ?><span>UTR <?= htmlspecialchars($order['utr_number']) ?></span><?php endif; ?>
            </div>

            <?php if (!empty($order['last_recon_note'])): ?>
                <p class="mb-0 small text-warning fw-semibold mt-1">&#9888;&#65039; <?= htmlspecialchars($order['last_recon_note']) ?></p>
            <?php endif; ?>

            <?php if ($displayStatus === 'APPROVED'): ?>
                <button type="button" class="btn btn-success w-100 mt-2"
                        data-bs-toggle="modal" data-bs-target="#kpaw-serve-modal"
                        onclick="kpawOpenServeModal('<?= (int) $order['id'] ?>', '<?= htmlspecialchars(addslashes($order['customer_name'] ?? '')) ?>')">
                    Mark as Served
                </button>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<!-- Shared confirmation modal — reused for every card via JS above -->
<div class="modal fade" id="kpaw-serve-modal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="order_id" id="kpaw-serve-order-id">
            <div class="modal-header">
                <h6 class="modal-title" id="kpaw-serve-order-label">Confirm Serve</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if ($serveError): ?>
                    <div class="alert alert-danger small py-2"><?= htmlspecialchars($serveError) ?></div>
                <?php endif; ?>
                <label class="form-label">Enter the 6-digit code the employee just told you</label>
                <input type="text" name="entered_code" id="kpaw-serve-code-input" inputmode="numeric" maxlength="6"
                       class="form-control form-control-lg kpaw-mono text-center" required autofocus
                       oninput="this.value = this.value.replace(/\D/g, '').slice(0, 6)">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" name="confirm_serve" value="1" class="btn btn-success">Confirm &amp; Serve</button>
            </div>
        </form>
    </div>
</div>

<script>
function kpawOpenServeModal(orderId, name) {
    document.getElementById('kpaw-serve-order-id').value = orderId;
    document.getElementById('kpaw-serve-order-label').textContent = 'Token #' + orderId + ' — ' + name;
}
<?php if ($serveError && $retryOrderId): ?>
    // Re-open the modal automatically with the error shown inside it,
    // pre-filled so the receptionist can immediately retry.
    document.addEventListener('DOMContentLoaded', function () {
        document.getElementById('kpaw-serve-order-id').value = '<?= (int) $retryOrderId ?>';
        new bootstrap.Modal(document.getElementById('kpaw-serve-modal')).show();
    });
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>