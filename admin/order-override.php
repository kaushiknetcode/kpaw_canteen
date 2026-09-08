<?php
/**
 * Admin status override — the general fix for exactly the scenario
 * discussed: a receptionist marks the wrong order Served, a typo'd
 * UTR needs a manual Force Approve, or any other status correction.
 *
 * super_admin only — this is deliberately more powerful than the
 * receptionist screen (any status, not canteen-scoped), so it's kept
 * separate rather than folded into counter/dashboard.php.
 *
 * Every action requires a typed reason and writes a full entry to
 * audit_log (already existed in the schema, unused until now) —
 * same rigor the plan already requires for VIP tokens, since a wrong
 * status change is exactly the same class of risk.
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

$order = null;
$lookupError = null;
$success = null;

$STATUS_LABELS = [
    'PENDING_CLEARANCE' => 'Pending Clearance',
    'APPROVED'          => 'Approved',
    'REJECTED'          => 'Rejected',
    'SERVED'            => 'Served',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    if (isset($_POST['lookup'])) {
        $search = trim($_POST['search'] ?? '');
        if ($search === '' || !ctype_digit($search)) {
            $lookupError = "Enter a 6-digit serving code or an order ID.";
        } else {
            // Matches either the serving code OR the raw order ID —
            // admin might have either on hand (e.g. an order ID from
            // the CSV upload results' "Flagged" list built earlier).
            $stmt = $pdo->prepare(
                "SELECT o.*, c.name AS canteen_name FROM orders o
                 JOIN canteens c ON c.id = o.canteen_id
                 WHERE o.serving_code = :search OR o.id = :search2"
            );
            $stmt->execute([':search' => $search, ':search2' => $search]);
            $order = $stmt->fetch();
            if (!$order) {
                $lookupError = "No order found matching that code or ID.";
            }
        }
    } elseif (isset($_POST['override'])) {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? '';
        $reason = trim($_POST['reason'] ?? '');

        if (!isset($STATUS_LABELS[$newStatus])) {
            $lookupError = "Invalid target status.";
        } elseif ($reason === '') {
            $lookupError = "A reason is required for every override.";
        } else {
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
            $stmt->execute([':id' => $orderId]);
            $target = $stmt->fetch();

            if (!$target) {
                $lookupError = "Order not found.";
            } else {
                try {
                    $pdo->beginTransaction();

                    // Set the matching side-column for whichever status
                    // we're moving to, same fields the normal flows use
                    // (approved_at via reconciliation, served_at via the
                    // counter screen) — so an overridden order looks
                    // identical to a normally-processed one afterward.
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

                    $updateStmt = $pdo->prepare(
                        "UPDATE orders SET status = :status{$extraSql} WHERE id = :id"
                    );
                    $updateStmt->execute(array_merge(
                        [':status' => $newStatus, ':id' => $orderId],
                        $extraParams
                    ));

                    $details = "Order #{$orderId}: {$target['status']} -> {$newStatus}. Reason: {$reason}";
                    $pdo->prepare(
                        "INSERT INTO audit_log (admin_id, action_type, order_id, details)
                         VALUES (:admin_id, 'STATUS_OVERRIDE', :order_id, :details)"
                    )->execute([
                        ':admin_id' => $_SESSION['admin_id'],
                        ':order_id' => $orderId,
                        ':details'  => $details,
                    ]);

                    $pdo->commit();
                    $success = "Order #{$orderId} updated to " . $STATUS_LABELS[$newStatus] . ".";
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('KPAW override failed: ' . $e->getMessage());
                    $lookupError = "Something went wrong saving this change. Please try again.";
                }
            }
        }
    }
}

// Live EXPIRED display, same logic as My Bookings / counter — purely
// informational here, since the underlying stored status is what
// actually gets overridden (see the note in the UI below).
$displayStatus = null;
if ($order) {
    $displayStatus = $order['status'];
    if (in_array($displayStatus, ['PENDING_CLEARANCE', 'APPROVED'], true)) {
        $timingRules = get_meal_timing_rules($pdo);
        $rule = $timingRules[$order['meal_type']] ?? null;
        if ($rule) {
            $serveEndAt = new DateTime($order['order_date'] . ' ' . $rule['serve_end']);
            if (new DateTime('now') > $serveEndAt) {
                $displayStatus = 'EXPIRED';
            }
        }
    }
}

require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Order Override</h5>

<?php if ($success): ?>
    <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($lookupError): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($lookupError) ?></div>
<?php endif; ?>

<form method="post" class="card mb-3">
    <div class="card-body">
        <?= csrf_field() ?>
        <label class="form-label fw-bold">Serving Code or Order ID</label>
        <input type="text" name="search" inputmode="numeric" class="form-control kpaw-mono mb-2" required autofocus>
        <button type="submit" name="lookup" value="1" class="btn btn-primary w-100">Look Up</button>
    </div>
</form>

<?php if ($order): ?>
    <div class="card mb-3">
        <div class="card-body">
            <p class="mb-1">Order #<?= (int) $order['id'] ?> — <?= htmlspecialchars($order['canteen_name']) ?></p>
            <p class="mb-1"><?= htmlspecialchars(ucfirst($order['meal_type'])) ?> &middot; <?= htmlspecialchars(format_date_with_day($order['order_date'])) ?></p>
            <p class="mb-1 kpaw-mono">&#8377;<?= number_format((float) $order['amount'], 2) ?></p>
            <p class="mb-1">
                Current stored status:
                <span class="badge bg-secondary"><?= htmlspecialchars($STATUS_LABELS[$order['status']] ?? $order['status']) ?></span>
                <?php if ($displayStatus === 'EXPIRED'): ?>
                    <span class="badge bg-dark">Displays as Expired</span>
                <?php endif; ?>
            </p>
            <?php if ($displayStatus === 'EXPIRED'): ?>
                <p class="small text-muted mb-0">
                    This meal's serving window has already closed — the receptionist screen will
                    still show Expired regardless of what status is set here, so an override to
                    Approved won't actually allow serving. Overriding is still useful for correcting
                    the record itself (e.g. financial accuracy), just not for enabling pickup.
                </p>
            <?php endif; ?>
        </div>
    </div>

    <form method="post" class="card">
        <div class="card-body">
            <?= csrf_field() ?>
            <input type="hidden" name="order_id" value="<?= (int) $order['id'] ?>">
            <label class="form-label fw-bold">Change status to</label>
            <select name="new_status" class="form-select mb-3" required>
                <option value="">Choose...</option>
                <?php foreach ($STATUS_LABELS as $value => $label): ?>
                    <?php if ($value !== $order['status']): ?>
                        <option value="<?= $value ?>"><?= $label ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <label class="form-label fw-bold">Reason (required)</label>
            <textarea name="reason" class="form-control mb-3" rows="2" required placeholder="e.g. Receptionist marked wrong order as served, correcting so the real order can be served"></textarea>
            <button type="submit" name="override" value="1" class="btn btn-danger w-100">Apply Override</button>
        </div>
    </form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>