<?php
/**
 * Reports — item-level counts and revenue, per canteen + meal + date.
 * Deliberately built ONLY from orders that are APPROVED or SERVED —
 * never PENDING_CLEARANCE, since those payments haven't actually been
 * verified yet and counting them would give the kitchen a misleading
 * picture of what's genuinely been paid for.
 *
 * Uses csv_upload_log (already built for the "already uploaded today"
 * reminder) as the signal for whether this canteen+meal+date has
 * actually been reconciled yet — if not, shows a plain explanatory
 * state instead of a mostly-empty table that could be mistaken for
 * "nobody ordered anything."
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

$MEALS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'snacks' => 'Snacks'];

$canteens = $pdo->query("SELECT * FROM canteens WHERE is_active = 1 ORDER BY id")->fetchAll();
$canteenId = (int) ($_GET['canteen_id'] ?? ($canteens[0]['id'] ?? 0));
$mealType = $_GET['meal'] ?? 'breakfast';
if (!array_key_exists($mealType, $MEALS)) {
    $mealType = 'breakfast';
}
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$currentCanteen = null;
foreach ($canteens as $c) {
    if ($c['id'] === $canteenId) { $currentCanteen = $c; break; }
}

// Has this exact canteen+meal+date combination actually been
// reconciled yet? Same signal already used for the "already uploaded
// today" reminder on the CSV upload page.
$reconciledStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM csv_upload_log
     WHERE canteen_id = :cid AND meal_period = :meal AND DATE(uploaded_at) = :date"
);
$reconciledStmt->execute([':cid' => $canteenId, ':meal' => $mealType, ':date' => $selectedDate]);
$hasBeenReconciled = (int) $reconciledStmt->fetchColumn() > 0;

$items = [];
$grandTotal = 0;
$totalOrders = 0;
if ($hasBeenReconciled) {
    $stmt = $pdo->prepare(
        "SELECT oi.item_name, SUM(oi.quantity) AS total_qty, SUM(oi.line_total) AS total_amount, COUNT(DISTINCT o.id) AS order_count
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.canteen_id = :cid AND o.meal_type = :meal AND o.order_date = :date
           AND o.status IN ('APPROVED', 'SERVED')
         GROUP BY oi.item_name
         ORDER BY total_qty DESC"
    );
    $stmt->execute([':cid' => $canteenId, ':meal' => $mealType, ':date' => $selectedDate]);
    $items = $stmt->fetchAll();
    foreach ($items as $it) {
        $grandTotal += (float) $it['total_amount'];
    }
    $orderCountStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders WHERE canteen_id = :cid AND meal_type = :meal AND order_date = :date AND status IN ('APPROVED','SERVED')"
    );
    $orderCountStmt->execute([':cid' => $canteenId, ':meal' => $mealType, ':date' => $selectedDate]);
    $totalOrders = (int) $orderCountStmt->fetchColumn();
}

$adminContainerMaxWidth = 800;
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Reports</h5>

<div class="d-flex gap-2 mb-2">
    <?php foreach ($canteens as $c): ?>
        <a href="?canteen_id=<?= (int) $c['id'] ?>&meal=<?= $mealType ?>&date=<?= $selectedDate ?>"
           class="btn btn-sm <?= $c['id'] === $canteenId ? 'btn-primary' : 'btn-outline-primary' ?>">
            <?= htmlspecialchars($c['name']) ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="d-flex gap-2 mb-2">
    <?php foreach ($MEALS as $key => $label): ?>
        <a href="?canteen_id=<?= $canteenId ?>&meal=<?= $key ?>&date=<?= $selectedDate ?>"
           class="btn btn-sm <?= $key === $mealType ? 'btn-primary' : 'btn-outline-primary' ?>">
            <?= $label ?>
        </a>
    <?php endforeach; ?>
</div>

<form method="get" class="d-flex gap-2 mb-3">
    <input type="hidden" name="canteen_id" value="<?= $canteenId ?>">
    <input type="hidden" name="meal" value="<?= $mealType ?>">
    <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" class="form-control form-control-sm" style="max-width: 180px;">
    <button type="submit" class="btn btn-outline-secondary btn-sm">Go</button>
</form>

<h6 class="mb-3">
    <?= htmlspecialchars($currentCanteen['name'] ?? '') ?> &middot;
    <?= $MEALS[$mealType] ?> &middot;
    <?= htmlspecialchars(date('d-m-Y', strtotime($selectedDate))) ?>
</h6>

<?php if (!$hasBeenReconciled): ?>
    <div class="alert alert-secondary">
        <strong>Not yet available.</strong> The bank statement for <?= htmlspecialchars($currentCanteen['name'] ?? '') ?>'s
        <?= strtolower($MEALS[$mealType]) ?> on this date hasn't been reconciled yet. Figures will appear here
        automatically once that CSV has been uploaded and processed.
    </div>
<?php elseif (!$items): ?>
    <div class="alert alert-info">Reconciled, but no approved orders for this combination.</div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead><tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Revenue</th></tr></thead>
            <tbody>
                <?php foreach ($items as $it): ?>
                    <tr>
                        <td><?= htmlspecialchars($it['item_name']) ?></td>
                        <td class="text-end kpaw-mono"><?= (int) $it['total_qty'] ?></td>
                        <td class="text-end kpaw-mono">&#8377;<?= number_format((float) $it['total_amount'], 2) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr class="fw-bold">
                    <td><?= $totalOrders ?> order(s)</td>
                    <td></td>
                    <td class="text-end kpaw-mono">&#8377;<?= number_format($grandTotal, 2) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>