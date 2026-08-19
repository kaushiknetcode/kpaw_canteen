<?php
/**
 * TEMPORARY STUB — Phase 4 replaces this with real UPI link generation
 * and UTR submission, using $_SESSION['pending_order'] exactly as this
 * stub does. Reads ONLY from the server-validated session cart set by
 * review.php — never trusts a fresh client POST for order contents.
 */
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

$pending = $_SESSION['pending_order'] ?? null;
if (!$pending) {
    header('Location: /app/book.php');
    exit;
}

// One more re-check — time may have passed between review and this click
if (!is_slot_still_valid($pdo, $pending['meal_type'], $pending['target_date'])) {
    unset($_SESSION['pending_order']);
    header('Location: /app/book.php');
    exit;
}

$canteenStmt = $pdo->prepare("SELECT * FROM canteens WHERE id = :id");
$canteenStmt->execute([':id' => $pending['canteen_id']]);
$canteen = $canteenStmt->fetch();

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Booking Confirmed (Placeholder)</h5>
<div class="card mb-3">
    <div class="card-body">
        <p class="mb-1"><strong><?= htmlspecialchars($canteen['name']) ?></strong> (<?= htmlspecialchars($canteen['brand_name']) ?>)</p>
        <?php foreach ($pending['items'] as $line): ?>
            <p class="mb-1"><?= htmlspecialchars($line['name']) ?> &times; <?= $line['quantity'] ?> &mdash; &#8377;<?= number_format($line['line_total'], 2) ?></p>
        <?php endforeach; ?>
        <p class="fw-bold mb-0">Total: &#8377;<?= number_format($pending['total'], 2) ?></p>
    </div>
</div>
<div class="alert alert-info">
    Real UPI payment and UTR submission are built in Phase 4 — this confirms your full itemized order reached the server correctly and matches what Phase 4 will actually charge.
</div>
<a href="/app/book.php" class="btn btn-secondary w-100">Book Another</a>
<a href="/app/dashboard.php" class="btn btn-link w-100 mt-2">Back to Dashboard</a>
<?php
unset($_SESSION['pending_order']);
require_once __DIR__ . '/../includes/footer.php';