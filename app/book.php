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

    // ------------------------------------------------------------
    // Step 1: no canteen chosen yet -> canteen cards (+ Menu Chart)
    // ------------------------------------------------------------
    if (!$canteen_id): ?>
        <h6 class="mb-3">Choose a canteen</h6>
        <?php
        $canteens = $pdo->query("SELECT * FROM canteens WHERE is_active = 1 ORDER BY id")->fetchAll();
        foreach ($canteens as $c):
            $selectUrl = '/app/book.php?canteen_id=' . (int) $c['id'];
            $chartSlug = stripos($c['name'], 'loco') !== false ? 'loco' : 'carriage';
        ?>
            <div class="kpaw-canteen-card mb-3">
                <div class="kpaw-canteen-card__name"><?= htmlspecialchars($c['name']) ?></div>
                <div class="kpaw-canteen-card__brand"><?= htmlspecialchars($c['brand_name']) ?></div>
                <div class="d-flex gap-2 mt-3">
                    <a href="<?= htmlspecialchars($selectUrl) ?>" class="btn btn-primary flex-grow-1">Select</a>
                    <button type="button" class="btn kpaw-btn-chart" data-bs-toggle="modal" data-bs-target="#kpawMenuChart<?= ucfirst($chartSlug) ?>">
                        &#128203; Menu Chart
                    </button>
                </div>
            </div>
        <?php endforeach; ?>

        <!-- Menu chart modals — static reference images, no booking data involved -->
        <div class="modal fade" id="kpawMenuChartLoco" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Loco Canteen — Menu Chart</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0">
                        <img src="/assets/img/menu-loco.jpeg" alt="Loco Canteen menu chart" class="w-100">
                    </div>
                </div>
            </div>
        </div>
        <div class="modal fade" id="kpawMenuChartCarriage" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Carriage Canteen — Menu Chart</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0">
                        <img src="/assets/img/menu-carriage.jpeg" alt="Carriage Canteen menu chart" class="w-100">
                    </div>
                </div>
            </div>
        </div>

    <?php
    // ------------------------------------------------------------
    // Step 2: canteen chosen, no meal yet -> meal tabs
    // ------------------------------------------------------------
    elseif (!$meal_type || !$target_date):
        $canteenStmt = $pdo->prepare("SELECT * FROM canteens WHERE id = :id AND is_active = 1");
        $canteenStmt->execute([':id' => $canteen_id]);
        $canteen = $canteenStmt->fetch();

        if (!$canteen): ?>
            <div class="alert alert-danger">That canteen isn't available right now.</div>
            <a href="/app/book.php" class="btn btn-secondary w-100">Start Over</a>
        <?php else:
            $slots = get_available_meal_slots($pdo); // unchanged — same function/logic as before
        ?>
            <div class="kpaw-canteen-banner mb-3">
                <?= htmlspecialchars($canteen['name']) ?> <span class="text-muted">(<?= htmlspecialchars($canteen['brand_name']) ?>)</span>
                <a href="/app/book.php" class="float-end small">Change</a>
            </div>

            <?php if (!$slots): ?>
                <div class="alert alert-info">No meals are currently bookable &mdash; check back later.</div>
            <?php else: ?>
                <h6 class="mb-3">Choose a meal</h6>
                <div class="kpaw-meal-tabs">
                    <?php foreach ($slots as $s):
                        $url = '/app/book.php?' . http_build_query([
                            'canteen_id' => $canteen_id, 'meal_type' => $s['meal_type'], 'date' => $s['target_date'],
                        ]); ?>
                        <a href="<?= htmlspecialchars($url) ?>" class="kpaw-meal-tab">
                            <div class="kpaw-meal-tab__name"><?= htmlspecialchars(ucfirst($s['meal_type'])) ?></div>
                            <div class="kpaw-meal-tab__date"><?= htmlspecialchars(format_date_with_day($s['target_date'])) ?></div>
                            <div class="kpaw-meal-tab__meta">
                                Serving <?= htmlspecialchars(date('g:i', strtotime($s['serve_start']))) ?>&ndash;<?= htmlspecialchars(date('g:i A', strtotime($s['serve_end']))) ?>
                            </div>
                            <div class="kpaw-meal-tab__closes">
                                Booking closes <?= date('g:i A', strtotime($s['closes_at'])) ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif;
        endif;

    else:
        // ------------------------------------------------------------
        // Step 3: canteen + meal + date all chosen -> validate + items
        // ------------------------------------------------------------
        $selectedSlot = find_slot($pdo, $meal_type, $target_date); // unchanged

        if (!$selectedSlot): ?>
            <div class="alert alert-danger">This booking window has just closed. Please start again.</div>
            <a href="/app/book.php" class="btn btn-secondary w-100">Start Over</a>
        <?php else: ?>
            <div class="kpaw-meal-info-card mb-3">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="kpaw-meal-info-card__title"><?= htmlspecialchars($selectedSlot['label']) ?></div>
                        <div class="kpaw-meal-info-card__date"><?= htmlspecialchars(format_date_with_day($target_date)) ?></div>
                    </div>
                    <a href="/app/book.php?canteen_id=<?= (int) $canteen_id ?>" class="kpaw-pill-outline">Change</a>
                </div>
                <div class="d-flex gap-2 mt-2">
                    <div class="kpaw-meal-info-chip">
                        <div class="kpaw-meal-info-chip__label">Serving</div>
                        <div class="kpaw-meal-info-chip__value kpaw-mono">
                            <?= date('g:i', strtotime($selectedSlot['serve_start'])) ?>&ndash;<?= date('g:i A', strtotime($selectedSlot['serve_end'])) ?>
                        </div>
                    </div>
                    <div class="kpaw-meal-info-chip kpaw-meal-info-chip--urgent">
                        <div class="kpaw-meal-info-chip__label">Closes in</div>
                        <div class="kpaw-meal-info-chip__value kpaw-mono kpaw-countdown" data-closes="<?= htmlspecialchars($selectedSlot['closes_at']) ?>"></div>
                    </div>
                </div>
            </div>

            <?php
            // Filter to today's day-of-week rotation: an item with a
            // specific day set only shows on that day; an item with
            // day_of_week left NULL shows every day (unaffected by this
            // change at all — existing items keep working exactly as
            // before until someone explicitly sets a day on them).
            $dayAbbr = strtolower(date('D', strtotime($target_date))); // 'mon', 'tue', ...
            $stmt = $pdo->prepare(
                "SELECT * FROM meal_items
                 WHERE canteen_id = :cid AND meal_type = :mt AND is_active = 1
                   AND (day_of_week IS NULL OR FIND_IN_SET(:day, day_of_week) > 0)
                 ORDER BY price"
            );
            $stmt->execute([':cid' => $canteen_id, ':mt' => $meal_type, ':day' => $dayAbbr]);
            $items = $stmt->fetchAll();

            if (!$items): ?>
                <div class="alert alert-warning">No menu items available for this canteen right now.</div>
                <a href="/app/book.php" class="btn btn-secondary w-100">Start Over</a>
            <?php else: ?>
                <h6 class="mb-3">Choose your items</h6>
                <div class="row row-cols-2 g-3 mb-5 pb-5" id="kpawMenuGrid">
                    <?php foreach ($items as $it): ?>
                        <div class="col">
                            <div class="kpaw-item-card" data-item-id="<?= (int) $it['id'] ?>" data-price="<?= (float) $it['price'] ?>">
                                <div class="kpaw-item-card__photo">
                                    <?php if (!empty($it['image_url'])): ?>
                                        <img src="<?= htmlspecialchars($it['image_url']) ?>" alt="<?= htmlspecialchars($it['name']) ?>"
                                            onerror="this.replaceWith(Object.assign(document.createElement('span'), {textContent: '<?= $MEAL_EMOJI[$meal_type] ?? '🍴' ?>'}))">
                                    <?php else: ?>
                                        <span><?= $MEAL_EMOJI[$meal_type] ?? '🍴' ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="kpaw-item-card__body">
                                    <p class="kpaw-item-card__name"><?= htmlspecialchars($it['name']) ?></p>
                                    <p class="kpaw-item-card__price kpaw-mono">&#8377;<?= number_format((float) $it['price'], 2) ?></p>

                                    <button type="button" class="kpaw-item-card__add">+ Add</button>

                                    <div class="kpaw-item-card__stepper d-none">
                                        <button type="button" class="kpaw-item-card__stepper-btn kpaw-qty-minus">&minus;</button>
                                        <span class="kpaw-qty-display kpaw-mono">0</span>
                                        <button type="button" class="kpaw-item-card__stepper-btn kpaw-item-card__stepper-btn--plus kpaw-qty-plus">+</button>
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

                    <div class="kpaw-cart-bar">
                        <div>
                            <div class="kpaw-cart-bar__count" id="kpawCartCount">0 items</div>
                            <div class="kpaw-cart-bar__total kpaw-mono" id="kpawCartTotal">&#8377;0.00</div>
                        </div>
                        <button type="submit" id="kpawReviewBtn" class="kpaw-cart-bar__btn" disabled>Review Order</button>
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

                    document.querySelectorAll('#kpawMenuGrid .kpaw-item-card').forEach(function (card) {
                        const itemId = card.dataset.itemId;
                        const price = parseFloat(card.dataset.price);
                        const display = card.querySelector('.kpaw-qty-display');
                        const addBtn = card.querySelector('.kpaw-item-card__add');
                        const stepper = card.querySelector('.kpaw-item-card__stepper');
                        const maxQty = 10;

                        function setQty(qty) {
                            qty = Math.max(0, Math.min(maxQty, qty));
                            display.textContent = qty;
                            if (qty > 0) {
                                kpawCart[itemId] = { qty, price };
                                card.classList.add('kpaw-item-card--active');
                                addBtn.classList.add('d-none');
                                stepper.classList.remove('d-none');
                            } else {
                                delete kpawCart[itemId];
                                card.classList.remove('kpaw-item-card--active');
                                addBtn.classList.remove('d-none');
                                stepper.classList.add('d-none');
                            }
                            kpawUpdateSummary();
                        }

                        addBtn.addEventListener('click', function () { setQty(1); });
                        card.querySelector('.kpaw-qty-plus').addEventListener('click', function () {
                            setQty((kpawCart[itemId] ? kpawCart[itemId].qty : 0) + 1);
                        });
                        card.querySelector('.kpaw-qty-minus').addEventListener('click', function () {
                            setQty((kpawCart[itemId] ? kpawCart[itemId].qty : 0) - 1);
                        });
                    });
                </script>
            <?php endif; ?>
        <?php endif;
    endif;
endif; ?>
<a href="/app/dashboard.php" class="btn btn-link w-100 mt-2">Back to Dashboard</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>