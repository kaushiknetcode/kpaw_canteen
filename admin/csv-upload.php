<?php
/**
 * Phase 5 — CSV Reconciliation Engine.
 *
 * Restricted to super_admin only — this approves real money, receptionists
 * never see this page even if they guess the URL.
 *
 * Column mapping confirmed against a real YesPay Hub export:
 *   ID                    -> orders.bank_payment_id (for fast lookup)
 *   Transaction UTR Number -> the actual matching key (orders.utr_number)
 *   Transaction_Amount    -> the actual matching key (orders.amount)
 * Read by HEADER NAME, not fixed column position, so a future export
 * with reordered columns doesn't silently break the match.
 *
 * The reconciliation query is exactly the one specified in the plan,
 * with one addition: scoped to the specific canteen_id whose upload
 * slot the file was submitted to. UTRs are already globally unique
 * (enforced by a DB constraint), so this can never change which orders
 * legitimately match — it's a pure safety net against the human error
 * of uploading the wrong canteen's file into the wrong slot.
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

$UPLOAD_DIR = __DIR__ . '/../uploads/csv-processing/';
$MAX_FILE_BYTES = 2 * 1024 * 1024; // 2MB, per the plan — a statement CSV should never realistically exceed this

$canteens = $pdo->query("SELECT * FROM canteens WHERE is_active = 1 ORDER BY id")->fetchAll();

// Today's upload history per canteen — pure display + the data source
// for the "you already uploaded for this meal today" JS confirmation
// below. Doesn't influence matching in any way.
$todayLogStmt = $pdo->prepare(
    "SELECT l.*, a.name AS admin_name FROM csv_upload_log l
     JOIN admins a ON a.id = l.admin_id
     WHERE l.canteen_id = :canteen_id AND DATE(l.uploaded_at) = CURDATE()
     ORDER BY l.uploaded_at DESC"
);
$todayLogsByCanteen = [];
$uploadedMealsByCanteen = []; // canteen_id => ['breakfast', ...], for the JS confirm check
foreach ($canteens as $c) {
    $todayLogStmt->execute([':canteen_id' => $c['id']]);
    $logs = $todayLogStmt->fetchAll();
    $todayLogsByCanteen[$c['id']] = $logs;
    $uploadedMealsByCanteen[$c['id']] = array_values(array_unique(array_column($logs, 'meal_period')));
}

$results = null; // set after a successful upload+process, drives the results screen
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = "Your session expired — please try again.";
    } else {
        $canteenId = (int) ($_POST['canteen_id'] ?? 0);
        $mealPeriod = $_POST['meal_period'] ?? '';
        $canteenStmt = $pdo->prepare("SELECT * FROM canteens WHERE id = :id AND is_active = 1");
        $canteenStmt->execute([':id' => $canteenId]);
        $canteen = $canteenStmt->fetch();

        if (!$canteen) {
            $error = "Invalid canteen selected.";
        } elseif (!in_array($mealPeriod, ['breakfast', 'lunch', 'snacks'], true)) {
            $error = "Please select which meal period this upload is for.";
        } elseif (empty($_FILES['csv']) || empty($_FILES['csv']['name'][0])) {
            $error = "No file was uploaded, or the upload failed. Please try again.";
        } else {
            // Reshape PHP's multi-file $_FILES structure into a clean
            // array of individual file arrays to loop over.
            $fileCount = count($_FILES['csv']['name']);
            $uploadedFiles = [];
            for ($i = 0; $i < $fileCount; $i++) {
                $uploadedFiles[] = [
                    'name'     => $_FILES['csv']['name'][$i],
                    'tmp_name' => $_FILES['csv']['tmp_name'][$i],
                    'error'    => $_FILES['csv']['error'][$i],
                    'size'     => $_FILES['csv']['size'][$i],
                ];
            }

            $required = ['ID', 'Transaction UTR Number', 'Transaction_Amount'];
            $storedPaths = [];
            $fileValidationError = null;

            // PASS 1: validate and save every file first, before processing
            // any rows — if any single file is bad, abort the whole batch
            // rather than partially reconcile some files and silently skip
            // others, which would be confusing to reconstruct later.
            foreach ($uploadedFiles as $file) {
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    $fileValidationError = "\"{$file['name']}\" failed to upload. Please try again.";
                    break;
                }
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    $fileValidationError = "\"{$file['name']}\" isn't a .csv file.";
                    break;
                }
                if ($file['size'] > $MAX_FILE_BYTES) {
                    $fileValidationError = "\"{$file['name']}\" is too large (max 2MB).";
                    break;
                }
                $storedName = 'recon_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.csv';
                $storedPath = $UPLOAD_DIR . $storedName;
                if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
                    $fileValidationError = "Couldn't save \"{$file['name']}\". Please try again.";
                    break;
                }
                $storedPaths[] = ['original_name' => $file['name'], 'path' => $storedPath];
            }

            if ($fileValidationError) {
                $error = $fileValidationError . " Nothing was processed.";
                foreach ($storedPaths as $sp) { @unlink($sp['path']); }
            } else {
                // PASS 2: read every file's rows into ONE combined dataset —
                // this is the actual fix for the multi-day-closure problem:
                // a UTR present in ANY of the uploaded files protects that
                // order from the "not found" rejection below, regardless of
                // which specific file it came from.
                $csvUtrAmounts = [];
                $matched = [];
                $unmatched = [];
                $rowCount = 0;
                $headerError = null;

                $updateStmt = $pdo->prepare(
                    "UPDATE orders
                     SET status = 'APPROVED', approved_at = NOW(), bank_payment_id = :payment_id
                     WHERE utr_number = :utr AND amount = :amount AND status = 'PENDING_CLEARANCE'
                       AND canteen_id = :canteen_id AND order_date >= (CURDATE() - INTERVAL 7 DAY)"
                );

                foreach ($storedPaths as $sp) {
                    $handle = fopen($sp['path'], 'r');
                    $header = fgetcsv($handle);

                    if (!$header) {
                        $headerError = "\"{$sp['original_name']}\" appears to be empty or not a valid CSV.";
                        fclose($handle);
                        break;
                    }
                    $colIndex = array_flip(array_map('trim', $header));
                    $missing = array_diff($required, array_keys($colIndex));
                    if (!empty($missing)) {
                        $headerError = "\"{$sp['original_name']}\" is missing column(s): " . implode(', ', $missing) . ".";
                        fclose($handle);
                        break;
                    }

                    while (($row = fgetcsv($handle)) !== false) {
                        if (count($row) < count($header)) {
                            continue;
                        }
                        $rowCount++;
                        $paymentId = trim($row[$colIndex['ID']] ?? '');
                        $utr       = trim($row[$colIndex['Transaction UTR Number']] ?? '');
                        $amount    = trim($row[$colIndex['Transaction_Amount']] ?? '');

                        if ($utr === '' || $amount === '' || !is_numeric($amount)) {
                            continue;
                        }

                        $csvUtrAmounts[$utr] = $amount;

                        $updateStmt->execute([
                            ':payment_id' => $paymentId, ':utr' => $utr, ':amount' => $amount, ':canteen_id' => $canteenId,
                        ]);
                        if ($updateStmt->rowCount() > 0) {
                            $matched[] = ['utr' => $utr, 'amount' => $amount, 'payment_id' => $paymentId];
                        } else {
                            $unmatched[] = ['utr' => $utr, 'amount' => $amount, 'payment_id' => $paymentId];
                        }
                    }
                    fclose($handle);
                }

                if ($headerError) {
                    $error = $headerError . " Nothing was processed.";
                } else {
                    // ------------------------------------------------------------
                    // Rejection pass — now runs ONCE against the COMBINED dataset
                    // from every uploaded file, not per-file. Wrong amount or a
                    // UTR genuinely absent from ALL uploaded files gets rejected
                    // immediately, per the operational call.
                    // ------------------------------------------------------------
                    $pendingStmt = $pdo->prepare(
                        "SELECT id, utr_number, amount FROM orders
                         WHERE canteen_id = :canteen_id AND status = 'PENDING_CLEARANCE'
                           AND order_date >= (CURDATE() - INTERVAL 7 DAY)"
                    );
                    $pendingStmt->execute([':canteen_id' => $canteenId]);

                    $rejectStmt = $pdo->prepare(
                        "UPDATE orders SET status = 'REJECTED', rejected_at = NOW(),
                                last_recon_note = :note, last_recon_checked_at = NOW()
                         WHERE id = :id"
                    );

                    $flaggedCount = 0;
                    $flaggedOrders = [];
                    foreach ($pendingStmt->fetchAll() as $pendingOrder) {
                        $orderUtr = $pendingOrder['utr_number'];
                        if ($orderUtr === null) {
                            continue;
                        }
                        if (isset($csvUtrAmounts[$orderUtr])) {
                            $bankAmount = $csvUtrAmounts[$orderUtr];
                            if ((float) $bankAmount !== (float) $pendingOrder['amount']) {
                                $note = "Wrong amount.";
                                $rejectStmt->execute([':note' => $note, ':id' => $pendingOrder['id']]);
                                $flaggedCount++;
                                $flaggedOrders[] = ['id' => $pendingOrder['id'], 'reason' => 'Amount mismatch — rejected', 'note' => $note];
                            }
                        } else {
                            $note = "UTR number not found.";
                            $rejectStmt->execute([':note' => $note, ':id' => $pendingOrder['id']]);
                            $flaggedCount++;
                            $flaggedOrders[] = ['id' => $pendingOrder['id'], 'reason' => 'UTR not found — rejected', 'note' => $note];
                        }
                    }

                    $results = [
                        'canteen_name'    => $canteen['name'],
                        'meal_period'     => $mealPeriod,
                        'file_count'      => count($storedPaths),
                        'row_count'       => $rowCount,
                        'matched'         => $matched,
                        'unmatched'       => $unmatched,
                        'rejected_count'  => $flaggedCount,
                        'rejected_orders' => $flaggedOrders,
                    ];

                    $pdo->prepare(
                        "INSERT INTO csv_upload_log (canteen_id, meal_period, admin_id, row_count, matched_count, flagged_count)
                         VALUES (:canteen_id, :meal_period, :admin_id, :row_count, :matched_count, :flagged_count)"
                    )->execute([
                        ':canteen_id' => $canteenId, ':meal_period' => $mealPeriod, ':admin_id' => $_SESSION['admin_id'],
                        ':row_count' => $rowCount, ':matched_count' => count($matched), ':flagged_count' => $flaggedCount,
                    ]);
                }

                // Delete every uploaded file immediately after processing —
                // same rule as before, now applied to all files in the batch.
                foreach ($storedPaths as $sp) { @unlink($sp['path']); }
            }
        }
    }
}

$adminContainerMaxWidth = 960; // wide layout, since this page needs real
                                // desktop room for the two-column form +
                                // history pairing below — unlike login.php
                                // and dashboard.php, which stay narrow.
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">CSV Reconciliation</h5>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($results): ?>
    <div class="card mb-3">
        <div class="card-body">
            <h6 class="mb-2"><?= htmlspecialchars($results['canteen_name']) ?> — <?= htmlspecialchars(ucfirst($results['meal_period'])) ?> — Results</h6>
            <p class="mb-1"><?= (int) $results['file_count'] ?> file(s) &middot; <?= (int) $results['row_count'] ?> transaction rows processed together</p>
            <p class="mb-1 text-success fw-semibold"><?= count($results['matched']) ?> orders approved</p>
            <?php if ($results['rejected_count'] > 0): ?>
                <p class="mb-1 text-danger fw-semibold"><?= (int) $results['rejected_count'] ?> orders rejected (amount mismatch or UTR not found)</p>
            <?php endif; ?>
            <p class="mb-0 text-muted"><?= count($results['unmatched']) ?> rows had no matching pending order (normal — most bank transactions aren't ours)</p>
        </div>
    </div>

    <?php if (!empty($results['matched'])): ?>
        <h6 class="mb-2">Approved</h6>
        <div class="table-responsive mb-3">
            <table class="table table-sm">
                <thead><tr><th>Payment ID</th><th>UTR</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($results['matched'] as $m): ?>
                    <tr>
                        <td class="kpaw-mono small"><?= htmlspecialchars($m['payment_id']) ?></td>
                        <td class="kpaw-mono small"><?= htmlspecialchars($m['utr']) ?></td>
                        <td class="text-end kpaw-mono">&#8377;<?= htmlspecialchars($m['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <?php if (!empty($results['rejected_orders'])): ?>
        <h6 class="mb-2 text-danger">Rejected</h6>
        <div class="table-responsive mb-3">
            <table class="table table-sm">
                <thead><tr><th>Order ID</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($results['rejected_orders'] as $f): ?>
                    <tr>
                        <td class="kpaw-mono small">#<?= (int) $f['id'] ?></td>
                        <td class="small"><?= htmlspecialchars($f['reason']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <a href="/admin/csv-upload.php" class="btn btn-outline-primary w-100 mb-4">Upload Another File</a>
<?php else: ?>

    <?php foreach ($canteens as $c): ?>
        <div class="row mb-4">
            <div class="col-md-7">
                <form method="post" enctype="multipart/form-data" class="card h-100 kpaw-csv-form" data-uploaded-today="<?= htmlspecialchars(json_encode($uploadedMealsByCanteen[$c['id']])) ?>">
                    <div class="card-body">
                        <h6 class="mb-2"><?= htmlspecialchars($c['name']) ?> <span class="text-muted small">(<?= htmlspecialchars($c['brand_name']) ?>)</span></h6>
                        <?= csrf_field() ?>
                        <input type="hidden" name="canteen_id" value="<?= (int) $c['id'] ?>">

                        <div class="d-flex gap-3 mb-2">
                            <?php foreach (['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'snacks' => 'Snacks'] as $value => $label): ?>
                                <div class="form-check">
                                    <input type="radio" name="meal_period" value="<?= $value ?>" class="form-check-input kpaw-meal-radio" required>
                                    <label class="form-check-label small"><?= $label ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <p class="small text-muted mb-1">Catching up after a closure? Select all the days' CSV files together — they'll be checked as one combined set, so a payment sitting in any one file won't be mistakenly rejected.</p>
                        <input type="file" name="csv[]" accept=".csv" class="form-control mb-2" multiple required>
                        <button type="submit" class="btn btn-primary w-100">Upload &amp; Reconcile</button>
                    </div>
                </form>
            </div>

            <div class="col-md-5">
                <?php if (!empty($todayLogsByCanteen[$c['id']])): ?>
                    <div class="card h-100">
                        <div class="card-body py-2">
                            <div class="small fw-semibold mb-1">Today's uploads</div>
                            <?php foreach ($todayLogsByCanteen[$c['id']] as $log): ?>
                                <div class="small text-muted mb-1">
                                    <?= htmlspecialchars(ucfirst($log['meal_period'])) ?> —
                                    <?= date('d-m-Y g:i A', strtotime($log['uploaded_at'])) ?>
                                    (<?= (int) $log['matched_count'] ?> approved, <?= (int) $log['flagged_count'] ?> flagged)
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="small text-muted p-2">No uploads yet today.</div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <script>
        document.querySelectorAll('.kpaw-csv-form').forEach(function (form) {
            var uploadedToday = JSON.parse(form.dataset.uploadedToday || '[]');
            form.addEventListener('submit', function (e) {
                var selected = form.querySelector('.kpaw-meal-radio:checked');
                if (selected && uploadedToday.indexOf(selected.value) !== -1) {
                    var label = selected.value.charAt(0).toUpperCase() + selected.value.slice(1);
                    var ok = confirm('You already uploaded for ' + label + ' today. Upload and reconcile again?');
                    if (!ok) {
                        e.preventDefault();
                    }
                }
            });
        });
    </script>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>