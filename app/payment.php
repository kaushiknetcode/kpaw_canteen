<?php
/**
 * Phase 4 — Payment: UPI Generation & UTR Submission
 *
 * GET  → reads $_SESSION['pending_order'] (set by review.php, never trusts
 *        client data again), builds the upi://pay deep link + QR for the
 *        canteen's VPA and exact total, shows the UTR submission form.
 * POST → validates the UTR, creates ONE `orders` row (PENDING_CLEARANCE)
 *        + one `order_items` row per cart line inside a DB transaction,
 *        then redirects (PRG pattern) to booking-pending.php?order_id=.
 *        Redirecting-after-POST + unsetting pending_order immediately is
 *        what stops a page refresh from creating a duplicate order — once
 *        pending_order is gone, a resubmitted POST just bounces to
 *        book.php instead of re-inserting. PHP's session file lock also
 *        serializes a rapid double-click into two sequential requests
 *        rather than a race.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/meal_rules.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$pending = $_SESSION['pending_order'] ?? null;
if (!$pending) {
    header('Location: /app/book.php');
    exit;
}

// Re-check the slot is still valid — time may have passed since review.php
if (!is_slot_still_valid($pdo, $pending['meal_type'], $pending['target_date'])) {
    unset($_SESSION['pending_order']);
    flash_set('error', 'This booking window has closed. Please book again.');
    header('Location: /app/book.php');
    exit;
}

// Payment window: 10 minutes from the first time this page was opened for
// this pending order (not regenerated on every reload/POST attempt — set
// once, same pattern as `tr` below). Enforced for real in the POST handler
// below, not just shown as a cosmetic countdown — a stale QR/link that's
// sat open past its window can't be used to create an order anymore.
if (empty($pending['expires_at'])) {
    $pending['expires_at'] = time() + 600;
    $_SESSION['pending_order']['expires_at'] = $pending['expires_at'];
}

$canteenStmt = $pdo->prepare("SELECT * FROM canteens WHERE id = :id AND is_active = 1");
$canteenStmt->execute([':id' => $pending['canteen_id']]);
$canteen = $canteenStmt->fetch();

if (!$canteen || empty($canteen['upi_vpa'])) {
    // No VPA configured for this canteen yet — can't generate a payment link
    flash_set('error', 'Payment is not available for this canteen right now. Please contact admin.');
    header('Location: /app/book.php');
    exit;
}

$errors = [];

// ------------------------------------------------------------
// POST — UTR submission
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        header('Location: /app/book.php');
        exit;
    }

    if (time() > $pending['expires_at']) {
        unset($_SESSION['pending_order']);
        flash_set('error', 'Your payment session expired after 10 minutes. Please book again to get a fresh QR code.');
        header('Location: /app/book.php');
        exit;
    }

    $utr = trim($_POST['utr_number'] ?? '');

    if (!preg_match('/^\d{12}$/', $utr)) {
        $errors[] = 'Enter the 12-digit UTR / Reference number exactly as shown in your UPI app.';
    }

    // Basic financial safeguard: the same UTR should never back two
    // separate orders (accidental resubmission or attempted reuse).
    if (empty($errors)) {
        $dupStmt = $pdo->prepare("SELECT id FROM orders WHERE utr_number = :utr");
        $dupStmt->execute([':utr' => $utr]);
        if ($dupStmt->fetch()) {
            $errors[] = 'This UTR has already been submitted for another order. If you believe this is an error, contact admin.';
        }
    }

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // Generate the 6-digit serving code BEFORE the insert, with a
            // pre-check rather than relying on a DB-constraint-collision
            // retry — deliberately separate from the UTR uniqueness
            // handling below, so a rare collision here can never be
            // mistaken for (or reported as) a duplicate-UTR error. The
            // collision odds are astronomically low (up to 900,000
            // possible codes) — this loop is a formality, not something
            // expected to actually retry in practice.
            $servingCode = null;
            for ($attempt = 0; $attempt < 5; $attempt++) {
                $candidate = (string) random_int(100000, 999999);
                $existsStmt = $pdo->prepare("SELECT 1 FROM orders WHERE serving_code = :code");
                $existsStmt->execute([':code' => $candidate]);
                if (!$existsStmt->fetch()) {
                    $servingCode = $candidate;
                    break;
                }
            }
            if ($servingCode === null) {
                throw new RuntimeException('Could not generate a unique serving code after 5 attempts.');
            }

            $orderStmt = $pdo->prepare(
                "INSERT INTO orders (user_type, user_id, canteen_id, meal_type, amount, utr_number, serving_code, status, order_date)
                 VALUES (:user_type, :user_id, :canteen_id, :meal_type, :amount, :utr, :serving_code, 'PENDING_CLEARANCE', :order_date)"
            );
            $orderStmt->execute([
                ':user_type'     => $_SESSION['user_type'],
                ':user_id'       => $_SESSION['user_id'],
                ':canteen_id'    => $pending['canteen_id'],
                ':meal_type'     => $pending['meal_type'],
                ':amount'        => $pending['total'],
                ':utr'           => $utr,
                ':serving_code'  => $servingCode,
                ':order_date'    => $pending['target_date'],
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                "INSERT INTO order_items (order_id, meal_item_id, item_name, unit_price, quantity, line_total)
                 VALUES (:order_id, :meal_item_id, :item_name, :unit_price, :quantity, :line_total)"
            );
            foreach ($pending['items'] as $line) {
                $itemStmt->execute([
                    ':order_id'     => $orderId,
                    ':meal_item_id' => $line['meal_item_id'],
                    ':item_name'    => $line['name'],
                    ':unit_price'   => $line['unit_price'],
                    ':quantity'     => $line['quantity'],
                    ':line_total'   => $line['line_total'],
                ]);
            }

            $pdo->commit();

            // Only clear the cart AFTER a successful commit — if the
            // insert failed we want pending_order still there to retry.
            unset($_SESSION['pending_order']);

            header('Location: /app/booking-pending.php?order_id=' . $orderId);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // MySQL error code 1062 = duplicate entry on a unique index.
            // This is the race-condition case: two submissions of the same
            // UTR landed close enough together that the earlier app-level
            // check didn't catch it — the DB-level unique constraint on
            // orders.utr_number is the real backstop here.
            if ((int) $e->errorInfo[1] === 1062) {
                $errors[] = 'This UTR has already been submitted for another order. If you believe this is an error, contact admin.';
            } else {
                error_log('KPAW order creation failed: ' . $e->getMessage());
                $errors[] = 'Something went wrong saving your booking. Please try submitting the UTR again.';
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('KPAW order creation failed: ' . $e->getMessage());
            $errors[] = 'Something went wrong saving your booking. Please try submitting the UTR again.';
        }
    }
}

// ------------------------------------------------------------
// GET (or POST that fell through with errors) — show payment screen
// ------------------------------------------------------------
$amount = number_format((float) $pending['total'], 2, '.', '');
$note = 'KPAW ' . $canteen['brand_name'] . ' ' . ucfirst($pending['meal_type']);

// Transaction reference (tr) — a unique ID per attempted payment, per the
// NPCI UPI deep-link spec. Missing this can make PSPs treat the link as an
// unrecognized/suspicious pattern. Generated once per pending order and
// kept stable across repeated GETs of this page (not regenerated on every
// reload), so re-scanning the same QR twice is still the same tr.
if (empty($pending['tr'])) {
    $pending['tr'] = 'KPAW' . date('YmdHis') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
    $_SESSION['pending_order']['tr'] = $pending['tr'];
}

// NOTE on `pa` specifically: deliberately NOT rawurlencode()'d. VPAs only
// ever contain letters/digits/./-/_ and exactly one '@' — technically the
// '@' should be percent-encoded (%40) per URI rules, but several UPI apps
// parse `pa` more loosely and expect the literal '@' to correctly split
// username/handle. Encoding it has been observed to produce a malformed
// lookup ("receiver not accepting payments on this UPI ID") even though
// the VPA itself is completely valid and accepts payments normally when
// entered manually. Every other param is still properly encoded.
//
// The tap-to-pay button/link (previously $upiUriStatic) has been removed
// entirely — QR scanning is the only method that's actually proven
// reliable across PhonePe/GPay/Paytm in real testing against this VPA;
// the button consistently failed regardless of payload shape.
$upiUri = 'upi://pay?pa=' . $canteen['upi_vpa']
    . '&pn=' . rawurlencode($canteen['name'])
    . '&mc=5812' // Merchant Category Code: 5812 = Eating Places/Restaurants.
                 // Confirmed against YesPay Hub's own printed counter QR —
                 // this is the correct code, not a guess.
    . '&tr=' . rawurlencode($pending['tr'])
    . '&am=' . rawurlencode($amount)
    . '&cu=INR'
    . '&tn=' . rawurlencode($note);

$slot = find_slot($pdo, $pending['meal_type'], $pending['target_date']);

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-1">Pay &amp; Confirm</h5>
<p class="small text-center text-muted mb-3">
    Valid until <strong id="kpaw-expiry-clock"><?= htmlspecialchars(date('h:i:s A', $pending['expires_at'])) ?></strong>
    (<span id="kpaw-countdown" class="kpaw-mono">10:00</span> remaining)
</p>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($err) ?></div>
<?php endforeach; ?>

<div class="card mb-3">
    <div class="card-body">
        <p class="mb-1"><strong><?= htmlspecialchars($canteen['name']) ?></strong> (<?= htmlspecialchars($canteen['brand_name']) ?>)</p>
        <?php if ($slot): ?>
            <p class="mb-1 small text-muted"><?= htmlspecialchars($slot['label']) ?></p>
        <?php endif; ?>
        <p class="fw-bold mb-0 fs-3 kpaw-mono">&#8377;<?= htmlspecialchars($amount) ?></p>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body text-center">
        <h6 class="fw-bold mb-3">Scan to Pay</h6>
        <div id="kpaw-qr" class="d-flex justify-content-center mb-3"></div>
        <p class="small text-muted mb-3">Open your UPI app, scan this code, and pay &#8377;<?= htmlspecialchars($amount) ?>.</p>

        <button type="button" id="kpaw-download-qr" class="btn btn-outline-primary w-100 mb-3" disabled>
            Download QR Code
        </button>

        <details class="text-start">
            <summary class="small text-muted">On one phone? Here's how to pay without a second device</summary>
            <ol class="small text-muted mt-2 mb-0 ps-3">
                <li>Tap <strong>Download QR Code</strong> above (saves to your Photos/Downloads)</li>
                <li>Open your UPI app (PhonePe, GPay, Paytm, etc.)</li>
                <li>Tap the scan icon, then choose <strong>"scan from gallery/photo"</strong> instead of using the live camera</li>
                <li>Select the QR image you just downloaded</li>
            </ol>
        </details>
    </div>
</div>

<form method="post" action="/app/payment.php">
    <?= csrf_field() ?>
    <div class="kpaw-utr-box mb-3">
        <div class="kpaw-utr-box__label">&#9888;&#65039; Enter Your UTR Carefully</div>
        <p class="small mb-3">
            After paying, find the 12-digit UTR (also called Reference No. / Transaction ID) in your UPI app's
            payment history and enter it here. Make sure it's entered correctly — if it doesn't match your
            payment when we verify it, your order will be cancelled.
        </p>
        <div class="d-flex gap-2 mb-3">
            <input
                type="text"
                inputmode="numeric"
                pattern="\d{12}"
                maxlength="12"
                minlength="12"
                name="utr_number"
                id="utr_number"
                class="form-control form-control-lg kpaw-mono text-center"
                placeholder="Enter 12-digit UTR number"
                value="<?= htmlspecialchars($_POST['utr_number'] ?? '') ?>"
                required
            >
            <button type="button" class="btn btn-outline-primary" onclick="kpawPasteUtr()">Paste</button>
        </div>
        <script>
        function kpawPasteUtr() {
            if (navigator.clipboard && navigator.clipboard.readText) {
                navigator.clipboard.readText().then(function (text) {
                    document.getElementById('utr_number').value = text.replace(/\D/g, '').slice(0, 12);
                }).catch(function () {
                    alert('Could not read clipboard — please paste manually by long-pressing the field.');
                });
            } else {
                alert('Your browser doesn\'t support tap-to-paste — please paste manually by long-pressing the field.');
            }
        }
        </script>

        <p class="small text-muted text-center mb-2">Not sure where to find your UTR? Tap your payment app below.</p>
        <div class="d-flex justify-content-center gap-3 mb-3">
            <button type="button" class="kpaw-app-icon-btn" data-bs-toggle="modal" data-bs-target="#kpawUtrHelpPhonepe">
                <img src="/assets/img/phonepe.png" alt="PhonePe" onerror="this.parentElement.textContent='PhonePe'">
            </button>
            <button type="button" class="kpaw-app-icon-btn" data-bs-toggle="modal" data-bs-target="#kpawUtrHelpGpay">
                <img src="/assets/img/gpay.png" alt="Google Pay" onerror="this.parentElement.textContent='GPay'">
            </button>
            <button type="button" class="kpaw-app-icon-btn" data-bs-toggle="modal" data-bs-target="#kpawUtrHelpPaytm">
                <img src="/assets/img/paytm.png" alt="Paytm" onerror="this.parentElement.textContent='Paytm'">
            </button>
        </div>

        <button type="submit" class="btn btn-primary w-100">Submit UTR &amp; Confirm Order</button>
    </div>
</form>
<a href="/app/book.php" class="btn btn-link w-100 mt-2">Cancel</a>

<!-- UTR help modals — static screenshots showing where each app displays
     the UTR. Gracefully hidden until the actual screenshots are uploaded. -->
<div class="modal fade" id="kpawUtrHelpPhonepe" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Finding your UTR in PhonePe</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <img src="/assets/img/utr-help-phonepe.jpg" alt="Where to find UTR in PhonePe" class="w-100">
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="kpawUtrHelpGpay" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Finding your UTR in Google Pay</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <img src="/assets/img/utr-help-gpay.jpg" alt="Where to find UTR in Google Pay" class="w-100">
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="kpawUtrHelpPaytm" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Finding your UTR in Paytm</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <img src="/assets/img/utr-help-paytm.jpg" alt="Where to find UTR in Paytm" class="w-100">
            </div>
        </div>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
    var kpawQrContainer = document.getElementById('kpaw-qr');
    new QRCode(kpawQrContainer, {
        text: <?= json_encode($upiUri) ?>,
        width: 220,
        height: 220
    });

    var kpawDownloadBtn = document.getElementById('kpaw-download-qr');
    setTimeout(function () {
        var rawCanvas = kpawQrContainer.querySelector('canvas');
        if (!rawCanvas) {
            // qrcodejs falls back to a <table> on very old browsers that
            // can't be exported as an image — just hide the button then.
            kpawDownloadBtn.style.display = 'none';
            return;
        }

        // qrcodejs draws the QR pattern with ZERO margin around it. Real
        // QR scanners need a blank "quiet zone" border to reliably detect
        // the pattern — without it, a downloaded/cropped image can fail
        // to scan even though the code itself is perfectly valid. Redraw
        // onto a larger white canvas with real padding on all sides, and
        // use that padded version for both the on-page display and the
        // download, so what's shown and what's downloaded behave the same.
        var QUIET_ZONE = 24;
        var TEXT_STRIP_HEIGHT = 40; // extra space BELOW the quiet zone —
                                     // never inside it, so the scannable
                                     // area's margin stays untouched
        var padded = document.createElement('canvas');
        padded.width = rawCanvas.width + QUIET_ZONE * 2;
        padded.height = rawCanvas.height + QUIET_ZONE * 2 + TEXT_STRIP_HEIGHT;
        var ctx = padded.getContext('2d');
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, padded.width, padded.height);
        ctx.drawImage(rawCanvas, QUIET_ZONE, QUIET_ZONE);

        // Stamp the expiry directly into the saved image — once someone
        // downloads this, the page's own "valid until" text is gone the
        // moment they leave. Without this, a QR sitting in someone's
        // gallery from three days ago looks identical to a fresh one.
        ctx.fillStyle = '#333333';
        ctx.font = '13px Arial, sans-serif';
        ctx.textAlign = 'center';
        ctx.fillText(
            'Valid till <?= htmlspecialchars(date('d-m-Y h:iA', $pending['expires_at'])) ?>',
            padded.width / 2,
            rawCanvas.height + QUIET_ZONE * 2 + 24
        );

        padded.style.maxWidth = '100%';
        padded.style.height = 'auto';

        kpawQrContainer.innerHTML = '';
        kpawQrContainer.appendChild(padded);

        kpawDownloadBtn.disabled = false;
        kpawDownloadBtn.addEventListener('click', function () {
            var link = document.createElement('a');
            link.download = 'KPAW-<?= htmlspecialchars(preg_replace('/[^A-Za-z0-9]+/', '-', $canteen['name'])) ?>-<?= htmlspecialchars($pending['meal_type']) ?>-QR.png';
            link.href = padded.toDataURL('image/png');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }, 100);

    // Live countdown, purely a UX nudge — the real enforcement is the
    // server-side check on submission, this just shows the same deadline.
    var kpawExpiresAt = <?= json_encode($pending['expires_at'] * 1000) ?>; // ms, for JS Date
    var kpawCountdownEl = document.getElementById('kpaw-countdown');
    var kpawCountdownTimer = setInterval(function () {
        var remainingMs = kpawExpiresAt - Date.now();
        if (remainingMs <= 0) {
            clearInterval(kpawCountdownTimer);
            kpawCountdownEl.textContent = 'expired';
            kpawCountdownEl.classList.add('text-danger', 'fw-bold');
            return;
        }
        var totalSeconds = Math.floor(remainingMs / 1000);
        var minutes = Math.floor(totalSeconds / 60);
        var seconds = totalSeconds % 60;
        kpawCountdownEl.textContent = minutes + ':' + (seconds < 10 ? '0' : '') + seconds;
        if (totalSeconds <= 60) {
            kpawCountdownEl.classList.add('text-danger', 'fw-bold');
        }
    }, 1000);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>