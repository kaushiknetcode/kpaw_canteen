<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/meal_rules.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

$meal_type   = $_GET['meal_type'] ?? null;
$target_date = $_GET['date'] ?? null;
$canteen_id  = isset($_GET['canteen_id']) ? (int) $_GET['canteen_id'] : null;

// Emoji fallback per meal type, used when a menu item has no photo yet
$MEAL_EMOJI = ['breakfast' => '🍳', 'lunch' => '🍽️', 'snacks' => '🥪'];

require_once __DIR__ . '/../includes/header.php';
?>
<h5 class="mb-3">Book a Meal</h5>

<?php if (bookings_are_stopped($pdo)): ?>
    <div class="alert alert-warning">Bookings are currently paused by the admin. Please check back later.</div>

<?php else:
    $selectedSlot = ($meal_type && $target_date) ? find_slot($pdo, $meal_type, $target_date) : null;

    if ($meal_type && $target_date):
        if (!$selectedSlot): ?>
            <div class="alert alert-danger">This booking window has just closed. Please start again.</div>
            <a href="/app/book.php" class="btn btn-secondary w-100">Start Over</a>
            <?php
        else: ?>
            <div class="alert alert-primary d-flex justify-content-between align-items-center">
                <div>
                    <strong><?= htmlspecialchars($selectedSlot['label']) ?></strong><br>
                    <span class="small">
                        <?= htmlspecialchars(format_date_with_day($target_date)) ?> &middot;
                        Serving <?= date('g:i A', strtotime($selectedSlot['serve_start'])) ?>&ndash;<?= date('g:i A', strtotime($selectedSlot['serve_end'])) ?>
                        <br>
                        Booking closes <?= date('g:i A', strtotime($selectedSlot['closes_at'])) ?>
                        &middot; <span class="kpaw-countdown fw-semibold" data-closes="<?= htmlspecialchars($selectedSlot['closes_at']) ?>"></span>
                    </span>
                </div>
                <a href="/app/book.php" class="btn btn-sm btn-outline-primary">Change</a>
            </div>
        <?php endif;
    endif;

    if ($selectedSlot):

        // Step 3: canteen chosen -> photo grid + cart
        if ($canteen_id): ?>
            <?php
            $stmt = $pdo->prepare(
                "SELECT * FROM meal_items WHERE canteen_id = :cid AND meal_type = :mt AND is_active = 1 ORDER BY price"
            );
            $stmt->execute([':cid' => $canteen_id, ':mt' => $meal_type]);
            $items = $stmt->fetchAll();

            if (!$items): ?>
                <div class="alert alert-warning">No menu items available for this canteen right now.</div>
                <a href="/app/book.php" class="btn btn-secondary w-100">Start Over</a>
            <?php else: ?>
                <h6 class="mb-3">Choose your items</h6>
                <div class="row row-cols-2 g-3 mb-4" id="kpawMenuGrid">
                    <?php foreach ($items as $it): ?>
                        <div class="col">
                            <div class="card h-100 text-center" data-item-id="<?= (int) $it['id'] ?>" data-price="<?= (float) $it['price'] ?>">
                                <div class="ratio ratio-1x1 bg-light d-flex align-items-center justify-content-center" style="font-size: 2.5rem;">
                                    <?php if (!empty($it['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($it['image_url']) ?>" alt="<?= htmlspecialchars($it['name']) ?>" class="w-100 h-100" style="object-fit: cover;">
                                    <?php else: ?>
                                        <span><?= $MEAL_EMOJI[$meal_type] ?? '🍴' ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="card-body p-2">
                                    <p class="card-text small mb-1"><?= htmlspecialchars($it['name']) ?></p>
                                    <p class="card-text small text-muted mb-2">&#8377;<?= number_format((float) $it['price'], 2) ?></p>
                                    <div class="d-flex align-items-center justify-content-center">
                                        <button type="button" class="btn btn-sm btn-outline-secondary kpaw-qty-minus">&minus;</button>
                                        <span class="mx-2 kpaw-qty-display">0</span>
                                        <button type="button" class="btn btn-sm btn-outline-secondary kpaw-qty-plus">+</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <form method="post" action="/app/review.php" id="kpawCartForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="meal_type" value="<?= htmlspecialchars($meal_type) ?>">
                    <input type="hidden" name="target_date" value="<?= htmlspecialchars($target_date) ?>">
                    <input type="hidden" name="canteen_id" value="<?= (int) $canteen_id ?>">
                    <input type="hidden" name="cart_json" id="kpawCartJson" value="{}">

                    <div class="position-sticky bottom-0 bg-white border-top pt-2 pb-1">
                        <div class="d-flex justify-content-between mb-2">
                            <span id="kpawCartCount">0 items</span>
                            <span id="kpawCartTotal" class="fw-semibold">&#8377;0.00</span>
                        </div>
                        <button type="submit" id="kpawReviewBtn" class="btn btn-primary w-100" disabled>Review Order</button>
                    </div>
                </form>

                <script>
                    const kpawCart = {}; // item_id -> { qty, price }

                    function kpawUpdateSummary() {
                        let count = 0, total = 0;
                        for (const id in kpawCart) {
                            count += kpawCart[id].qty;
                            total += kpawCart[id].qty * kpawCart[id].price;
                        }
                        document.getElementById('kpawCartCount').textContent = count + (count === 1 ? ' item' : ' items');
                        document.getElementById('kpawCartTotal').textContent = '₹' + total.toFixed(2);
                        document.getElementById('kpawReviewBtn').disabled = count === 0;
                        document.getElementById('kpawCartJson').value = JSON.stringify(
                            Object.fromEntries(Object.entries(kpawCart).map(([id, v]) => [id, v.qty]))
                        );
                    }

                    document.querySelectorAll('#kpawMenuGrid .card').forEach(function (card) {
                        const itemId = card.dataset.itemId;
                        const price = parseFloat(card.dataset.price);
                        const display = card.querySelector('.kpaw-qty-display');
                        const maxQty = 10;

                        function setQty(qty) {
                            qty = Math.max(0, Math.min(maxQty, qty));
                            display.textContent = qty;
                            if (qty > 0) {
                                kpawCart[itemId] = { qty, price };
                            } else {
                                delete kpawCart[itemId];
                            }
                            kpawUpdateSummary();
                        }

                        card.querySelector('.kpaw-qty-plus').addEventListener('click', function () {
                            setQty((kpawCart[itemId] ? kpawCart[itemId].qty : 0) + 1);
                        });
                        card.querySelector('.kpaw-qty-minus').addEventListener('click', function () {
                            setQty((kpawCart[itemId] ? kpawCart[itemId].qty : 0) - 1);
                        });
                    });
                </script>
            <?php endif; ?>

        <?php
        // Step 2: meal slot chosen -> pick a canteen
        else:
            $canteens = $pdo->query("SELECT * FROM canteens WHERE is_active = 1 ORDER BY id")->fetchAll(); ?>
            <h6 class="mb-3">Choose a canteen</h6>
            <?php foreach ($canteens as $c):
                $url = '/app/book.php?' . http_build_query([
                    'meal_type' => $meal_type, 'date' => $target_date, 'canteen_id' => $c['id'],
                ]); ?>
                <a href="<?= htmlspecialchars($url) ?>" class="btn btn-outline-primary w-100 mb-2">
                    <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['brand_name']) ?>)
                </a>
            <?php endforeach;
        endif;

    // Step 1: choose a meal slot
    elseif (!$meal_type):
        $slots = get_available_meal_slots($pdo);
        if (!$slots): ?>
            <div class="alert alert-info">No meals are currently bookable &mdash; check back later.</div>
        <?php else: ?>
            <h6 class="mb-3">Choose a meal</h6>
            <?php foreach ($slots as $s):
                $url = '/app/book.php?' . http_build_query(['meal_type' => $s['meal_type'], 'date' => $s['target_date']]); ?>
                <a href="<?= htmlspecialchars($url) ?>" class="btn btn-outline-primary w-100 mb-2 text-start">
                    <div><?= htmlspecialchars($s['label']) ?></div>
                    <div class="small text-muted">
                        <?= htmlspecialchars(format_date_with_day($s['target_date'])) ?> &middot;
                        closes <?= date('g:i A', strtotime($s['closes_at'])) ?>
                        &middot; <span class="kpaw-countdown" data-closes="<?= htmlspecialchars($s['closes_at']) ?>"></span>
                    </div>
                </a>
            <?php endforeach;
        endif;
    endif;
endif; ?>
<a href="/app/dashboard.php" class="btn btn-link w-100 mt-2">Back to Dashboard</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>