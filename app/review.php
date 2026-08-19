<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/meal_rules.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    header('Location: /app/book.php');
    exit;
}

$meal_type   = $_POST['meal_type'] ?? '';
$target_date = $_POST['target_date'] ?? '';
$canteen_id  = (int) ($_POST['canteen_id'] ?? 0);
$cartRaw     = json_decode($_POST['cart_json'] ?? '{}', true);

$slot = find_slot($pdo, $meal_type, $target_date);
if (!$slot || !is_array($cartRaw) || empty($cartRaw)) {
    header('Location: /app/book.php');
    exit;
}

// Re-validate every item server-side — never trust quantities or prices
// from the client. Only real, active items belonging to this exact
// canteen + meal_type make it into the validated cart.
$stmt = $pdo->prepare(
    "SELECT * FROM meal_items WHERE canteen_id = :cid AND meal_type = :mt AND is_active = 1"
);
$stmt->execute([':cid' => $canteen_id, ':mt' => $meal_type]);
$validItems = [];
foreach ($stmt->fetchAll() as $row) {
    $validItems[$row['id']] = $row;
}

$cart = [];
$total = 0.0;
foreach ($cartRaw as $itemId => $qty) {
    $itemId = (int) $itemId;
    $qty = max(1, min(10, (int) $qty));
    if (!isset($validItems[$itemId])) {
        continue; // silently drop anything that doesn't check out
    }
    $item = $validItems[$itemId];
    $lineTotal = (float) $item['price'] * $qty;
    $cart[] = [
        'meal_item_id' => $itemId,
        'name'         => $item['name'],
        'unit_price'   => (float) $item['price'],
        'quantity'     => $qty,
        'line_total'   => $lineTotal,
    ];
    $total += $lineTotal;
}

if (empty($cart)) {
    header('Location: /app/book.php');
    exit;
}

$canteenStmt = $pdo->prepare("SELECT * FROM canteens WHERE id = :id");
$canteenStmt->execute([':id' => $canteen_id]);
$canteen = $canteenStmt->fetch();

// Store the SERVER-validated cart in session — checkout-stub.php reads
// from here, not from any client-submitted data again.
$_SESSION['pending_order'] = [
    'meal_type'   => $meal_type,
    'target_date' => $target_date,
    'canteen_id'  => $canteen_id,
    'items'       => $cart,
    'total'       => $total,
];

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Review Your Order</h5>

<div class="card mb-3">
    <div class="card-body">
        <p class="mb-1"><strong><?= htmlspecialchars($canteen['name']) ?></strong> (<?= htmlspecialchars($canteen['brand_name']) ?>)</p>
        <p class="mb-1"><?= htmlspecialchars($slot['label']) ?></p>
        <p class="mb-1 small text-muted">
            <?= htmlspecialchars(format_date_with_day($target_date)) ?> &middot;
            Serving <?= date('g:i A', strtotime($slot['serve_start'])) ?>&ndash;<?= date('g:i A', strtotime($slot['serve_end'])) ?>
        </p>
    </div>
</div>

<table class="table">
    <thead>
        <tr><th>Item</th><th class="text-center">Qty</th><th class="text-end">Subtotal</th></tr>
    </thead>
    <tbody>
        <?php foreach ($cart as $line): ?>
            <tr>
                <td><?= htmlspecialchars($line['name']) ?><br><span class="text-muted small">&#8377;<?= number_format($line['unit_price'], 2) ?> each</span></td>
                <td class="text-center"><?= $line['quantity'] ?></td>
                <td class="text-end">&#8377;<?= number_format($line['line_total'], 2) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr class="fw-bold"><td colspan="2">Total</td><td class="text-end">&#8377;<?= number_format($total, 2) ?></td></tr>
    </tfoot>
</table>

<form method="post" action="/app/checkout-stub.php">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn-primary w-100">Pay Now</button>
</form>
<a href="/app/book.php?<?= htmlspecialchars(http_build_query(['meal_type' => $meal_type, 'date' => $target_date, 'canteen_id' => $canteen_id])) ?>" class="btn btn-link w-100">Edit Order</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>