<?php
/**
 * Confirmation screen shown right after UTR submission (Phase 4), and
 * revisitable afterwards. Reads the order straight from the DB — never
 * from session — so it always reflects live status (still PENDING, or
 * already APPROVED/REJECTED by the time Phase 5's CSV reconciliation runs).
 * A proper "My Bookings" list is Phase 6's job; this is a single-order view.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/meal_rules.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$orderId = (int) ($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    header('Location: /app/dashboard.php');
    exit;
}

// Ownership check — a user can only view their own order, never guess
// another order_id and see someone else's booking.
$orderStmt = $pdo->prepare(
    "SELECT o.*, c.name AS canteen_name, c.brand_name
     FROM orders o
     JOIN canteens c ON c.id = o.canteen_id
     WHERE o.id = :id AND o.user_type = :user_type AND o.user_id = :user_id"
);
$orderStmt->execute([
    ':id'        => $orderId,
    ':user_type' => $_SESSION['user_type'],
    ':user_id'   => $_SESSION['user_id'],
]);
$order = $orderStmt->fetch();

if (!$order) {
    header('Location: /app/dashboard.php');
    exit;
}

$itemsStmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = :id");
$itemsStmt->execute([':id' => $orderId]);
$items = $itemsStmt->fetchAll();

$statusMeta = [
    'PENDING_CLEARANCE' => ['label' => 'Pending Clearance', 'class' => 'warning'],
    'APPROVED'          => ['label' => 'Approved',          'class' => 'success'],
    'SERVED'            => ['label' => 'Served',             'class' => 'secondary'],
    'REJECTED'          => ['label' => 'Rejected',           'class' => 'danger'],
    'EXPIRED'           => ['label' => 'Expired',            'class' => 'dark'],
];
$meta = $statusMeta[$order['status']] ?? ['label' => $order['status'], 'class' => 'secondary'];

require_once __DIR__ . '/../includes/header.php';
?>
<div class="kpaw-success-check mb-3">
    <svg viewBox="0 0 52 52" width="72" height="72">
        <circle class="kpaw-success-check__circle" cx="26" cy="26" r="24" fill="none"/>
        <path class="kpaw-success-check__mark" fill="none" d="M14 27l7 7 16-16"/>
    </svg>
</div>
<h5 class="mb-3 text-center">Booking Confirmed</h5>

<div class="card mb-3">
    <div class="card-body">
        <span class="badge bg-<?= $meta['class'] ?> mb-2"><?= htmlspecialchars($meta['label']) ?></span>
        <p class="mb-1"><strong><?= htmlspecialchars($order['canteen_name']) ?></strong> (<?= htmlspecialchars($order['brand_name']) ?>)</p>
        <p class="mb-1 small text-muted">
            <?= htmlspecialchars(ucfirst($order['meal_type'])) ?> &middot;
            <?= htmlspecialchars(format_date_with_day($order['order_date'])) ?>
        </p>
        <p class="mb-1 small text-muted">Token #<?= (int) $order['id'] ?> &middot; UTR: <?= htmlspecialchars($order['utr_number']) ?></p>

        <hr>

        <?php foreach ($items as $line): ?>
            <p class="mb-1"><?= htmlspecialchars($line['item_name']) ?> &times; <?= (int) $line['quantity'] ?> &mdash; &#8377;<?= number_format((float) $line['line_total'], 2) ?></p>
        <?php endforeach; ?>
        <p class="fw-bold mb-0">Total: &#8377;<?= number_format((float) $order['amount'], 2) ?></p>
    </div>
</div>

<?php if ($order['status'] === 'PENDING_CLEARANCE'): ?>
    <div class="alert alert-info">
        Your payment is being verified against the bank statement. This page will show <strong>Approved</strong> once it clears — check back or revisit this link anytime.
    </div>
<?php endif; ?>

<a href="/app/my-bookings.php" class="btn btn-primary w-100">Go to My Bookings</a>
<p class="small text-muted text-center mt-2 mb-0">Redirecting automatically in <span id="kpaw-countdown">10</span>s&hellip;</p>

<a href="/app/book.php" class="btn btn-secondary w-100 mt-3">Book Another</a>
<a href="/app/dashboard.php" class="btn btn-link w-100 mt-2">Back to Dashboard</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
<script>
(function () {
    var seconds = 10;
    var el = document.getElementById('kpaw-countdown');
    var timer = setInterval(function () {
        seconds--;
        if (el) el.textContent = seconds;
        if (seconds <= 0) {
            clearInterval(timer);
            window.location.href = '/app/my-bookings.php';
        }
    }, 1000);
})();
</script>