<?php
/**
 * Receptionist Reports — same item-count + revenue logic as
 * admin/reports.php, fixed to the receptionist's own single canteen
 * (no canteen switcher, since they only have one). Built only from
 * APPROVED/SERVED orders, same reconciliation-gated placeholder
 * message as the admin version.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

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

$MEALS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'snacks' => 'Snacks'];

$mealType = $_GET['meal'] ?? 'breakfast';
if (!array_key_exists($mealType, $MEALS)) {
    $mealType = 'breakfast';
}
$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
    $selectedDate = date('Y-m-d');
}

$reconciledStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM csv_upload_log
     WHERE canteen_id = :cid AND meal_period = :meal AND DATE(uploaded_at) = :date"
);
$reconciledStmt->execute([':cid' => $myCanteenId, ':meal' => $mealType, ':date' => $selectedDate]);
$hasBeenReconciled = (int) $reconciledStmt->fetchColumn() > 0;

$items = [];
$grandTotal = 0;
$totalOrders = 0;
if ($hasBeenReconciled) {
    $stmt = $pdo->prepare(
        "SELECT oi.item_name, SUM(oi.quantity) AS total_qty, SUM(oi.line_total) AS total_amount
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.canteen_id = :cid AND o.meal_type = :meal AND o.order_date = :date
           AND o.status IN ('APPROVED', 'SERVED')
         GROUP BY oi.item_name
         ORDER BY total_qty DESC"
    );
    $stmt->execute([':cid' => $myCanteenId, ':meal' => $mealType, ':date' => $selectedDate]);
    $items = $stmt->fetchAll();
    foreach ($items as $it) {
        $grandTotal += (float) $it['total_amount'];
    }
    $orderCountStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM orders WHERE canteen_id = :cid AND meal_type = :meal AND order_date = :date AND status IN ('APPROVED','SERVED')"
    );
    $orderCountStmt->execute([':cid' => $myCanteenId, ':meal' => $mealType, ':date' => $selectedDate]);
    $totalOrders = (int) $orderCountStmt->fetchColumn();
}

require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-1">Reports — <?= htmlspecialchars($myCanteen['name']) ?></h5>
<p class="text-muted small mb-3"><?= htmlspecialchars($myCanteen['brand_name']) ?></p>

<div class="d-flex gap-2 mb-3">
    <?php foreach ($MEALS as $key => $label): ?>
        <a href="?meal=<?= $key ?>&date=<?= $selectedDate ?>"
           class="btn btn-sm <?= $key === $mealType ? 'btn-primary' : 'btn-outline-primary' ?>">
            <?= $label ?>
        </a>
    <?php endforeach; ?>
</div>

<form method="get" class="d-flex gap-2 mb-3">
    <input type="hidden" name="meal" value="<?= $mealType ?>">
    <input type="date" name="date" value="<?= htmlspecialchars($selectedDate) ?>" class="form-control form-control-sm" style="max-width: 180px;">
    <button type="submit" class="btn btn-outline-secondary btn-sm">Go</button>
</form>

<h6 class="mb-3">
    <?= $MEALS[$mealType] ?> &middot; <?= htmlspecialchars(date('d-m-Y', strtotime($selectedDate))) ?>
</h6>

<?php if (!$hasBeenReconciled): ?>
    <div class="alert alert-secondary">
        <strong>Not yet available.</strong> The bank statement for <?= strtolower($MEALS[$mealType]) ?> on this
        date hasn't been reconciled yet. Figures will appear here automatically once that's processed.
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