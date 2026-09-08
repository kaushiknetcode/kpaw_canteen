<?php
/**
 * My Bookings — same session-driven flow for both employees and guests
 * (uses $_SESSION['user_type']/'user_id' generically throughout, exactly
 * like every other page in the booking pipeline — no employee-only
 * branch anywhere here).
 *
 * EXPIRED is never written to the database — computed live on every
 * page load by comparing current server time to that order's meal's
 * serve-end time ("no cron job, no refund, no food given").
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/meal_rules.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$stmt = $pdo->prepare(
    "SELECT o.*, c.name AS canteen_name, c.brand_name
     FROM orders o
     JOIN canteens c ON c.id = o.canteen_id
     WHERE o.user_type = :user_type AND o.user_id = :user_id
     ORDER BY o.created_at DESC"
);
$stmt->execute([
    ':user_type' => $_SESSION['user_type'],
    ':user_id'   => $_SESSION['user_id'],
]);
$orders = $stmt->fetchAll();

$timingRules = get_meal_timing_rules($pdo);

$statusMeta = [
    'PENDING_CLEARANCE' => ['label' => 'Pending Clearance', 'class' => 'warning'],
    'APPROVED'          => ['label' => 'Approved',          'class' => 'success'],
    'SERVED'            => ['label' => 'Served',             'class' => 'secondary'],
    'REJECTED'          => ['label' => 'Rejected',           'class' => 'danger'],
    'EXPIRED'           => ['label' => 'Expired',            'class' => 'dark'],
];

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

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">My Bookings</h5>

<?php if (!$orders): ?>
    <div class="alert alert-info">You haven't booked any meals yet.</div>
    <a href="/app/book.php" class="btn btn-primary w-100">Book a Meal</a>
<?php else: ?>
    <?php foreach ($orders as $order):
        $status = $order['status'];
        $rule = $timingRules[$order['meal_type']] ?? null; // needed for display below regardless of status
        if (in_array($status, ['PENDING_CLEARANCE', 'APPROVED'], true) && $rule) {
            $serveEndAt = new DateTime($order['order_date'] . ' ' . $rule['serve_end']);
            if (new DateTime('now') > $serveEndAt) {
                $status = 'EXPIRED';
            }
        }
        $meta = $statusMeta[$status] ?? ['label' => $status, 'class' => 'secondary'];
    ?>
        <div class="kpaw-booking-card kpaw-booking-card--<?= $meta['class'] ?> mb-3">
            <div class="d-flex justify-content-between align-items-start">
                <span class="badge bg-<?= $meta['class'] ?>"><?= htmlspecialchars($meta['label']) ?></span>
                <span class="small text-muted kpaw-mono">Token #<?= (int) $order['id'] ?></span>
            </div>

            <p class="kpaw-booking-card__title mt-2 mb-0"><?= htmlspecialchars($order['canteen_name']) ?></p>
            <p class="mb-1 small text-muted">
                <?= htmlspecialchars(ucfirst($order['meal_type'])) ?> &middot;
                <?= htmlspecialchars(format_date_with_day($order['order_date'])) ?>
                <?php if ($rule): ?>
                    &middot; <span class="kpaw-mono"><?= htmlspecialchars(date('g:i', strtotime($rule['serve_start']))) ?>&ndash;<?= htmlspecialchars(date('g:i A', strtotime($rule['serve_end']))) ?></span>
                <?php endif; ?>
            </p>
            <p class="mb-2 small text-muted kpaw-mono">
                Booked <?= htmlspecialchars(date('d-m-Y g:i A', strtotime($order['created_at']))) ?>
            </p>

            <?php if ($status === 'APPROVED' && !empty($order['serving_code'])): ?>
                <div class="kpaw-serving-code mb-2">
                    <div class="kpaw-serving-code__label">Show this code at the counter</div>
                    <div class="kpaw-serving-code__value kpaw-mono"><?= htmlspecialchars($order['serving_code']) ?></div>
                </div>
            <?php endif; ?>

            <?php if (in_array($status, ['PENDING_CLEARANCE', 'REJECTED'], true) && !empty($order['last_recon_note'])): ?>
                <div class="alert alert-<?= $status === 'REJECTED' ? 'danger' : 'warning' ?> small py-2 px-3 mb-2">
                    <?= htmlspecialchars($order['last_recon_note']) ?>
                    <?php if ($order['last_recon_note'] === 'Wrong amount.'): ?>
                        <div class="kpaw-mono fw-semibold mt-1">You booked: &#8377;<?= number_format((float) $order['amount'], 2) ?></div>
                        <div class="mt-1">Check what actually left your account for this payment — it doesn't match what you booked for.</div>
                    <?php elseif (!empty($order['utr_number'])): ?>
                        <div class="kpaw-mono fw-semibold mt-1">You submitted: <?= htmlspecialchars($order['utr_number']) ?></div>
                        <?php if ($status === 'PENDING_CLEARANCE'): ?>
                            <div class="mt-1">Double-check this against your UPI app's payment history — a single wrong digit will cause this.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($itemsByOrder[$order['id']])): ?>
                <div class="d-flex flex-wrap gap-1 mb-2">
                    <?php foreach ($itemsByOrder[$order['id']] as $line): ?>
                        <span class="kpaw-item-pill"><?= htmlspecialchars($line['item_name']) ?> <span class="kpaw-item-pill__qty">&times;<?= (int) $line['quantity'] ?></span></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="d-flex justify-content-between align-items-center mt-2">
                <span class="fw-semibold kpaw-mono">&#8377;<?= number_format((float) $order['amount'], 2) ?></span>
                <?php if ($order['utr_number']): ?>
                    <span class="small text-muted kpaw-mono">UTR <?= htmlspecialchars($order['utr_number']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<a href="/app/dashboard.php" class="btn btn-link w-100 mt-2">Back to Dashboard</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>