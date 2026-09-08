<?php
/**
 * Export — every filter, every field, shown on screen AND downloadable.
 * One row per ordered ITEM (not per order), since "filter by item"
 * only makes sense at that granularity.
 *
 * Downloads as .csv, not a native .xlsx — this project has
 * deliberately avoided any Composer dependency (see config.php's own
 * comment), and a real .xlsx file needs a heavy library to generate.
 * Excel opens a .csv natively with a double-click, same columns, same
 * data — just without native multi-tab/cell-formatting.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: /admin/login.php');
    exit;
}
if ($_SESSION['admin_role'] !== 'super_admin') {
    http_response_code(403);
    die('Access denied.');
}

$canteens = $pdo->query("SELECT * FROM canteens ORDER BY id")->fetchAll();
$itemNames = $pdo->query("SELECT DISTINCT item_name FROM order_items ORDER BY item_name")->fetchAll(PDO::FETCH_COLUMN);
$MEALS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'snacks' => 'Snacks'];
$STATUSES = ['PENDING_CLEARANCE' => 'Pending', 'APPROVED' => 'Approved', 'SERVED' => 'Served', 'REJECTED' => 'Rejected'];

/**
 * Shared filter-building logic — used identically by both the
 * on-screen preview and the actual CSV download, so the two can
 * never disagree about which rows match.
 */
function kpaw_build_export_query(array $input): array
{
    $sql = "SELECT o.id AS order_id, o.created_at, o.order_date, c.name AS canteen_name, o.meal_type,
                   COALESCE(u.full_name, g.full_name) AS customer_name, o.user_type,
                   u.hrms_id, COALESCE(u.phone, g.phone) AS phone,
                   oi.item_name, oi.quantity, oi.unit_price, oi.line_total,
                   o.amount AS order_total, o.utr_number, o.bank_payment_id, o.serving_code,
                   o.status, o.approved_at, o.served_at, o.rejected_at, o.last_recon_note
            FROM order_items oi
            JOIN orders o ON o.id = oi.order_id
            JOIN canteens c ON c.id = o.canteen_id
            LEFT JOIN users u ON o.user_type = 'employee' AND o.user_id = u.id
            LEFT JOIN guests g ON o.user_type = 'guest' AND o.user_id = g.id
            WHERE 1=1";
    $params = [];

    if (!empty($input['date_from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date_from'])) {
        $sql .= " AND o.order_date >= :date_from";
        $params[':date_from'] = $input['date_from'];
    }
    if (!empty($input['date_to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $input['date_to'])) {
        $sql .= " AND o.order_date <= :date_to";
        $params[':date_to'] = $input['date_to'];
    }
    if (!empty($input['canteen_id']) && (int) $input['canteen_id'] > 0) {
        $sql .= " AND o.canteen_id = :canteen_id";
        $params[':canteen_id'] = (int) $input['canteen_id'];
    }
    if (!empty($input['meal_type'])) {
        $sql .= " AND o.meal_type = :meal_type";
        $params[':meal_type'] = $input['meal_type'];
    }
    if (!empty($input['status'])) {
        $sql .= " AND o.status = :status";
        $params[':status'] = $input['status'];
    }
    if (!empty($input['item_name'])) {
        $sql .= " AND oi.item_name = :item_name";
        $params[':item_name'] = $input['item_name'];
    }
    if (!empty($input['user_type'])) {
        $sql .= " AND o.user_type = :user_type";
        $params[':user_type'] = $input['user_type'];
    }
    if (isset($input['amount_min']) && $input['amount_min'] !== '' && is_numeric($input['amount_min'])) {
        $sql .= " AND o.amount >= :amount_min";
        $params[':amount_min'] = $input['amount_min'];
    }
    if (isset($input['amount_max']) && $input['amount_max'] !== '' && is_numeric($input['amount_max'])) {
        $sql .= " AND o.amount <= :amount_max";
        $params[':amount_max'] = $input['amount_max'];
    }

    $sql .= " ORDER BY o.order_date DESC, o.id DESC";
    return [$sql, $params];
}

$filters = $_POST ?: $_GET;
$hasFilters = $_SERVER['REQUEST_METHOD'] === 'POST';

// ------------------------------------------------------------
// Actual download — streams CSV directly, no HTML around it.
// ------------------------------------------------------------
if ($hasFilters && isset($_POST['do_export']) && csrf_verify()) {
    [$sql, $params] = kpaw_build_export_query($filters);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ahar-export-' . date('Y-m-d_His') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Order ID', 'Booked At', 'Meal Date', 'Canteen', 'Meal Type',
        'Customer Name', 'User Type', 'HRMS ID', 'Phone',
        'Item Name', 'Quantity', 'Unit Price', 'Item Total',
        'Order Total', 'UTR Number', 'Bank Payment ID', 'Serving Code',
        'Status', 'Approved At', 'Served At', 'Rejected At', 'Reconciliation Note',
    ]);
    while ($row = $stmt->fetch()) {
        fputcsv($out, [
            $row['order_id'], $row['created_at'], $row['order_date'], $row['canteen_name'], ucfirst($row['meal_type']),
            $row['customer_name'], ucfirst($row['user_type']), $row['hrms_id'] ?? '', $row['phone'],
            $row['item_name'], $row['quantity'], $row['unit_price'], $row['line_total'],
            $row['order_total'], $row['utr_number'], $row['bank_payment_id'] ?? '', $row['serving_code'] ?? '',
            $STATUSES[$row['status']] ?? $row['status'], $row['approved_at'] ?? '', $row['served_at'] ?? '',
            $row['rejected_at'] ?? '', $row['last_recon_note'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// ------------------------------------------------------------
// On-screen preview — same filters, same query, capped at 200 rows
// so the page stays fast; the actual download has no cap.
// ------------------------------------------------------------
$previewRows = [];
$previewTruncated = false;
if ($hasFilters && isset($_POST['preview']) && csrf_verify()) {
    [$sql, $params] = kpaw_build_export_query($filters);
    $stmt = $pdo->prepare($sql . " LIMIT 201");
    $stmt->execute($params);
    $previewRows = $stmt->fetchAll();
    if (count($previewRows) > 200) {
        $previewTruncated = true;
        array_pop($previewRows);
    }
}

$adminContainerMaxWidth = 1400;
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Export Data</h5>
<p class="small text-muted">Preview shows up to 200 matching rows on screen — the CSV download has no limit and includes everything that matches.</p>

<form method="post" id="kpaw-export-form">
    <?= csrf_field() ?>

    <div class="row g-2 mb-2">
        <div class="col-2">
            <label class="form-label small mb-1">From date</label>
            <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from'] ?? '') ?>" class="form-control form-control-sm">
        </div>
        <div class="col-2">
            <label class="form-label small mb-1">To date</label>
            <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to'] ?? '') ?>" class="form-control form-control-sm">
        </div>
        <div class="col-2">
            <label class="form-label small mb-1">Canteen</label>
            <select name="canteen_id" class="form-select form-select-sm">
                <option value="0">All canteens</option>
                <?php foreach ($canteens as $c): ?>
                    <option value="<?= (int) $c['id'] ?>" <?= (int) ($filters['canteen_id'] ?? 0) === $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-2">
            <label class="form-label small mb-1">Meal</label>
            <select name="meal_type" class="form-select form-select-sm">
                <option value="">All meals</option>
                <?php foreach ($MEALS as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($filters['meal_type'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-2">
            <label class="form-label small mb-1">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">All statuses</option>
                <?php foreach ($STATUSES as $val => $label): ?>
                    <option value="<?= $val ?>" <?= ($filters['status'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-2">
            <label class="form-label small mb-1">Item</label>
            <select name="item_name" class="form-select form-select-sm">
                <option value="">All items</option>
                <?php foreach ($itemNames as $name): ?>
                    <option value="<?= htmlspecialchars($name) ?>" <?= ($filters['item_name'] ?? '') === $name ? 'selected' : '' ?>><?= htmlspecialchars($name) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row g-2 mb-3">
        <div class="col-3">
            <label class="form-label small mb-1">Booked by</label>
            <select name="user_type" class="form-select form-select-sm">
                <option value="">Employees &amp; Guests</option>
                <option value="employee" <?= ($filters['user_type'] ?? '') === 'employee' ? 'selected' : '' ?>>Employees only</option>
                <option value="guest" <?= ($filters['user_type'] ?? '') === 'guest' ? 'selected' : '' ?>>Guests only</option>
            </select>
        </div>
        <div class="col-2">
            <label class="form-label small mb-1">Min &#8377;</label>
            <input type="number" step="0.01" name="amount_min" value="<?= htmlspecialchars($filters['amount_min'] ?? '') ?>" class="form-control form-control-sm">
        </div>
        <div class="col-2">
            <label class="form-label small mb-1">Max &#8377;</label>
            <input type="number" step="0.01" name="amount_max" value="<?= htmlspecialchars($filters['amount_max'] ?? '') ?>" class="form-control form-control-sm">
        </div>
        <div class="col-5 d-flex align-items-end gap-2">
            <button type="submit" name="preview" value="1" class="btn btn-outline-primary flex-grow-1">Preview Results</button>
            <button type="submit" name="do_export" value="1" class="btn btn-primary flex-grow-1">Download CSV</button>
        </div>
    </div>
</form>

<?php if ($hasFilters && isset($_POST['preview'])): ?>
    <?php if ($previewTruncated): ?>
        <div class="alert alert-warning small">Showing the first 200 matching rows — the CSV download will include all of them.</div>
    <?php endif; ?>
    <?php if (!$previewRows): ?>
        <div class="alert alert-info">No rows match those filters.</div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-striped">
                <thead>
                    <tr>
                        <th>Order</th><th>Booked</th><th>Meal Date</th><th>Canteen</th><th>Meal</th>
                        <th>Customer</th><th>Type</th><th>Item</th><th>Qty</th><th class="text-end">Item Total</th>
                        <th class="text-end">Order Total</th><th>Status</th><th>UTR</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($previewRows as $row): ?>
                        <tr>
                            <td class="kpaw-mono">#<?= (int) $row['order_id'] ?></td>
                            <td class="kpaw-mono small"><?= htmlspecialchars(date('d-m-Y g:iA', strtotime($row['created_at']))) ?></td>
                            <td class="kpaw-mono small"><?= htmlspecialchars(date('d-m-Y', strtotime($row['order_date']))) ?></td>
                            <td><?= htmlspecialchars($row['canteen_name']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($row['meal_type'])) ?></td>
                            <td><?= htmlspecialchars($row['customer_name']) ?></td>
                            <td><?= htmlspecialchars(ucfirst($row['user_type'])) ?></td>
                            <td><?= htmlspecialchars($row['item_name']) ?></td>
                            <td class="kpaw-mono"><?= (int) $row['quantity'] ?></td>
                            <td class="text-end kpaw-mono">&#8377;<?= number_format((float) $row['line_total'], 2) ?></td>
                            <td class="text-end kpaw-mono">&#8377;<?= number_format((float) $row['order_total'], 2) ?></td>
                            <td><span class="badge bg-secondary"><?= htmlspecialchars($STATUSES[$row['status']] ?? $row['status']) ?></span></td>
                            <td class="kpaw-mono small"><?= htmlspecialchars($row['utr_number'] ?? '') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>