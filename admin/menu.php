<?php
/**
 * Menu Manager — add, edit, or deactivate items per canteen. Full-
 * width, side-by-side canteen layout — PC-only usage, per the admin's
 * own call, same reasoning as the wide Orders dashboard.
 *
 * Deactivating (not deleting) is the only removal path — reversible
 * by design, matching the rest of this app: no destructive action
 * anywhere lacks an undo. A hidden item costs nothing operationally
 * and can be turned back on any time.
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

$MENU_IMG_DIR = __DIR__ . '/../assets/img/menu-items/';
if (!is_dir($MENU_IMG_DIR)) {
    mkdir($MENU_IMG_DIR, 0755, true);
}

/**
 * Validates and saves an uploaded item photo. Returns the public
 * path to store in image_url, or null if no file was uploaded (not
 * an error — the field is optional). Throws on a genuine problem.
 */
function kpaw_handle_item_image(array $file, string $dir): ?string
{
    if (empty($file['name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please try again.');
    }
    if ($file['size'] > 5 * 1024 * 1024) {
        throw new RuntimeException('Image is too large (max 5MB).');
    }
    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        throw new RuntimeException('That file doesn\'t look like a valid image.');
    }
    $ext = match ($imageInfo['mime']) {
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        default => null,
    };
    if ($ext === null) {
        throw new RuntimeException('Only JPG, PNG, or WEBP images are accepted.');
    }
    $filename = 'item_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) {
        throw new RuntimeException('Could not save the image. Please try again.');
    }
    return '/assets/img/menu-items/' . $filename;
}

$DAY_LABELS = ['mon' => 'Mon', 'tue' => 'Tue', 'wed' => 'Wed', 'thu' => 'Thu', 'fri' => 'Fri', 'sat' => 'Sat'];
$MEAL_LABELS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'snacks' => 'Snacks'];

function kpaw_days_to_set(array $checkedDays, array $validDays): ?string
{
    $valid = array_intersect($checkedDays, array_keys($validDays));
    return $valid ? implode(',', $valid) : null;
}

$canteens = $pdo->query("SELECT * FROM canteens WHERE is_active = 1 ORDER BY id")->fetchAll();
$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {

    if (isset($_POST['add_item'])) {
        $canteenId = (int) ($_POST['canteen_id'] ?? 0);
        $mealType = $_POST['meal_type'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $price = $_POST['price'] ?? '';
        $dayOfWeek = kpaw_days_to_set($_POST['day_of_week'] ?? [], $DAY_LABELS);

        if (!array_key_exists($mealType, $MEAL_LABELS)) {
            $error = "Choose a meal type.";
        } elseif ($name === '') {
            $error = "Item name is required.";
        } elseif (!is_numeric($price) || (float) $price < 0) {
            $error = "Enter a valid price.";
        } else {
            try {
                $imageUrl = !empty($_FILES['image']) ? kpaw_handle_item_image($_FILES['image'], $MENU_IMG_DIR) : null;
                $pdo->prepare(
                    "INSERT INTO meal_items (canteen_id, meal_type, name, price, image_url, day_of_week, is_active)
                     VALUES (:cid, :mt, :name, :price, :img, :dow, 1)"
                )->execute([
                    ':cid' => $canteenId, ':mt' => $mealType, ':name' => $name,
                    ':price' => $price, ':img' => $imageUrl, ':dow' => $dayOfWeek,
                ]);
                $success = "Added {$name}.";
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    } elseif (isset($_POST['update_item'])) {
        $itemId = (int) ($_POST['item_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = $_POST['price'] ?? '';
        $dayOfWeek = kpaw_days_to_set($_POST['day_of_week'] ?? [], $DAY_LABELS);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || !is_numeric($price) || (float) $price < 0) {
            $error = "Enter a valid name and price.";
        } else {
            try {
                $newImageUrl = !empty($_FILES['image']) ? kpaw_handle_item_image($_FILES['image'], $MENU_IMG_DIR) : null;

                if ($newImageUrl !== null) {
                    $pdo->prepare(
                        "UPDATE meal_items SET name = :name, price = :price, day_of_week = :dow, is_active = :active, image_url = :img WHERE id = :id"
                    )->execute([
                        ':name' => $name, ':price' => $price, ':dow' => $dayOfWeek,
                        ':active' => $isActive, ':img' => $newImageUrl, ':id' => $itemId,
                    ]);
                } else {
                    $pdo->prepare(
                        "UPDATE meal_items SET name = :name, price = :price, day_of_week = :dow, is_active = :active WHERE id = :id"
                    )->execute([
                        ':name' => $name, ':price' => $price, ':dow' => $dayOfWeek,
                        ':active' => $isActive, ':id' => $itemId,
                    ]);
                }
                $success = "Item updated.";
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }
    }
}

$itemsByCanteen = [];
$itemStmt = $pdo->prepare("SELECT * FROM meal_items WHERE canteen_id = :cid ORDER BY meal_type, name");
foreach ($canteens as $c) {
    $itemStmt->execute([':cid' => $c['id']]);
    $itemsByCanteen[$c['id']] = $itemStmt->fetchAll();
}

$adminContainerMaxWidth = 1400;
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Menu Manager</h5>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row g-4">
<?php foreach ($canteens as $c): ?>
    <div class="col-6">
        <h6 class="kpaw-menu-canteen-title mb-3"><?= htmlspecialchars($c['name']) ?> <span class="fw-normal text-muted">(<?= htmlspecialchars($c['brand_name']) ?>)</span></h6>

        <?php foreach ($MEAL_LABELS as $mealKey => $mealLabel):
            $mealItems = array_filter($itemsByCanteen[$c['id']], fn($it) => $it['meal_type'] === $mealKey);
        ?>
            <div class="kpaw-menu-section mb-3">
                <div class="kpaw-menu-section__title"><?= $mealLabel ?></div>

                <?php if (!$mealItems): ?>
                    <p class="small text-muted mb-0">Nothing added yet for <?= strtolower($mealLabel) ?>.</p>
                <?php endif; ?>

                <?php foreach ($mealItems as $item): ?>
                    <div class="kpaw-menu-item-card <?= !$item['is_active'] ? 'kpaw-menu-item-card--inactive' : '' ?>">
                        <?php if (!empty($item['image_url'])): ?>
                            <img src="<?= htmlspecialchars($item['image_url']) ?>" alt="" class="kpaw-menu-item-card__img">
                        <?php else: ?>
                            <div class="kpaw-menu-item-card__img kpaw-menu-item-card__img--placeholder">&#127860;</div>
                        <?php endif; ?>

                        <div class="kpaw-menu-item-card__info">
                            <div class="kpaw-menu-item-card__name">
                                <?= htmlspecialchars($item['name']) ?>
                                <?php if (!$item['is_active']): ?><span class="badge bg-secondary ms-1">Hidden</span><?php endif; ?>
                            </div>
                            <div class="kpaw-menu-item-card__meta">
                                <span class="kpaw-mono">&#8377;<?= number_format((float) $item['price'], 2) ?></span>
                                &middot;
                                <?= $item['day_of_week'] ? implode(', ', array_map(fn($d) => $DAY_LABELS[$d] ?? $d, explode(',', $item['day_of_week']))) : 'Every day' ?>
                            </div>
                        </div>

                        <div class="kpaw-menu-item-card__actions">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                    onclick="document.getElementById('edit-item-<?= (int) $item['id'] ?>').classList.toggle('d-none')">
                                Edit
                            </button>
                        </div>

                        <form method="post" enctype="multipart/form-data" id="edit-item-<?= (int) $item['id'] ?>" class="kpaw-menu-item-edit d-none">
                            <?= csrf_field() ?>
                            <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                            <div class="row g-2">
                                <div class="col-8"><input type="text" name="name" value="<?= htmlspecialchars($item['name']) ?>" class="form-control form-control-sm" required></div>
                                <div class="col-4"><input type="number" step="0.01" min="0" name="price" value="<?= htmlspecialchars($item['price']) ?>" class="form-control form-control-sm" required></div>
                            </div>
                            <label class="form-label small mt-2 mb-1">Days available (leave all unchecked for every day)</label>
                            <?php $itemDays = $item['day_of_week'] ? explode(',', $item['day_of_week']) : []; ?>
                            <div class="d-flex flex-wrap gap-3 mb-2">
                                <?php foreach ($DAY_LABELS as $val => $label): ?>
                                    <div class="form-check">
                                        <input type="checkbox" name="day_of_week[]" value="<?= $val ?>" class="form-check-input" id="day-<?= (int) $item['id'] ?>-<?= $val ?>" <?= in_array($val, $itemDays, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label small" for="day-<?= (int) $item['id'] ?>-<?= $val ?>"><?= $label ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <label class="form-label small mt-2 mb-1"><?= !empty($item['image_url']) ? 'Replace photo' : 'Add a photo' ?> (JPG/PNG/WEBP, up to 5MB)</label>
                            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm">
                            <div class="form-check mt-2">
                                <input type="checkbox" name="is_active" id="active-<?= (int) $item['id'] ?>" class="form-check-input" <?= $item['is_active'] ? 'checked' : '' ?>>
                                <label class="form-check-label small" for="active-<?= (int) $item['id'] ?>">Visible to employees</label>
                            </div>
                            <button type="submit" name="update_item" value="1" class="btn btn-primary btn-sm mt-2">Save Changes</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>

        <div class="kpaw-menu-add-card">
            <div class="fw-bold small mb-2">Add a new item to <?= htmlspecialchars($c['name']) ?></div>
            <form method="post" enctype="multipart/form-data">
                <?= csrf_field() ?>
                <input type="hidden" name="canteen_id" value="<?= (int) $c['id'] ?>">
                <div class="row g-2">
                    <div class="col-4">
                        <select name="meal_type" class="form-select form-select-sm" required>
                            <option value="">Which meal?</option>
                            <?php foreach ($MEAL_LABELS as $val => $label): ?>
                                <option value="<?= $val ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-5"><input type="text" name="name" placeholder="What's it called?" class="form-control form-control-sm" required></div>
                    <div class="col-3"><input type="number" step="0.01" min="0" name="price" placeholder="Price" class="form-control form-control-sm" required></div>
                </div>
                <label class="form-label small mt-2 mb-1">Available on (leave unchecked for every day)</label>
                <div class="d-flex flex-wrap gap-3 mb-2">
                    <?php foreach ($DAY_LABELS as $val => $label): ?>
                        <div class="form-check">
                            <input type="checkbox" name="day_of_week[]" value="<?= $val ?>" class="form-check-input" id="new-day-<?= (int) $c['id'] ?>-<?= $val ?>">
                            <label class="form-check-label small" for="new-day-<?= (int) $c['id'] ?>-<?= $val ?>"><?= $label ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <label class="form-label small mt-2 mb-1">Photo — optional (JPG/PNG/WEBP, up to 5MB)</label>
                <input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm">
                <button type="submit" name="add_item" value="1" class="btn btn-success btn-sm w-100 mt-2">Add This Item</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>