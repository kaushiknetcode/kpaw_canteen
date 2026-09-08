<?php
/**
 * Schedule Manager — two related tools on one page:
 *
 * 1. Weekly availability grid: 7 days x 3 meals, each independently
 *    on/off. This IS the "half day / full day" feature — toggle
 *    Saturday's lunch+snacks on to make it a full day, or toggle a
 *    normal weekday's lunch off to cut it to breakfast-only. No SQL
 *    needed for this anymore.
 *
 * 2. Holidays: specific one-off dates fully blocked (a real holiday,
 *    not a recurring weekly pattern) — separate from the grid above,
 *    exactly as documented when weekly_availability was first built.
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

$DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
         'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];
$MEALS = ['breakfast' => 'Breakfast', 'lunch' => 'Lunch', 'snacks' => 'Snacks'];

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {

    if (isset($_POST['save_schedule'])) {
        $updateStmt = $pdo->prepare(
            "UPDATE weekly_availability SET is_available = :val WHERE day_of_week = :day AND meal_type = :meal"
        );
        foreach ($DAYS as $dayKey => $dayLabel) {
            foreach ($MEALS as $mealKey => $mealLabel) {
                $checked = isset($_POST['avail'][$dayKey][$mealKey]) ? 1 : 0;
                $updateStmt->execute([':val' => $checked, ':day' => $dayKey, ':meal' => $mealKey]);
            }
        }
        $success = "Weekly schedule saved.";
    } elseif (isset($_POST['add_holiday'])) {
        $date = $_POST['holiday_date'] ?? '';
        $reason = trim($_POST['reason'] ?? '');
        $blocksAll = isset($_POST['blocks_all_meals']) ? 1 : 0;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = "Choose a valid date.";
        } else {
            try {
                $pdo->prepare(
                    "INSERT INTO holidays (holiday_date, reason, blocks_all_meals) VALUES (:d, :r, :b)"
                )->execute([':d' => $date, ':r' => $reason !== '' ? $reason : null, ':b' => $blocksAll]);
                $success = "Holiday added.";
            } catch (PDOException $e) {
                $error = $e->getCode() === '23000' ? "That date is already marked as a holiday." : "Something went wrong.";
            }
        }
    } elseif (isset($_POST['delete_holiday'])) {
        $pdo->prepare("DELETE FROM holidays WHERE id = :id")->execute([':id' => (int) ($_POST['holiday_id'] ?? 0)]);
        $success = "Holiday removed.";
    }
}

$availability = [];
$availStmt = $pdo->query("SELECT * FROM weekly_availability");
foreach ($availStmt->fetchAll() as $row) {
    $availability[$row['day_of_week']][$row['meal_type']] = (bool) $row['is_available'];
}

$holidays = $pdo->query("SELECT * FROM holidays ORDER BY holiday_date")->fetchAll();

$adminContainerMaxWidth = 800;
require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Weekly Schedule</h5>
<p class="small text-muted">Turn any day + meal combination on or off — this is how you'd open a specific Saturday to all three meals, or cut a normal day down to breakfast only.</p>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="post">
    <?= csrf_field() ?>
    <div class="table-responsive">
        <table class="table table-bordered align-middle text-center">
            <thead>
                <tr><th class="text-start">Day</th><?php foreach ($MEALS as $label): ?><th><?= $label ?></th><?php endforeach; ?></tr>
            </thead>
            <tbody>
                <?php foreach ($DAYS as $dayKey => $dayLabel): ?>
                    <tr>
                        <td class="text-start fw-semibold"><?= $dayLabel ?></td>
                        <?php foreach ($MEALS as $mealKey => $mealLabel): ?>
                            <td>
                                <input type="checkbox" class="form-check-input" name="avail[<?= $dayKey ?>][<?= $mealKey ?>]"
                                       <?= !empty($availability[$dayKey][$mealKey]) ? 'checked' : '' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <button type="submit" name="save_schedule" value="1" class="btn btn-primary w-100">Save Weekly Schedule</button>
</form>

<h5 class="mb-3 mt-5">Holidays</h5>
<p class="small text-muted">Blocks one specific date entirely — separate from the weekly pattern above.</p><?php foreach ($holidays as $h): ?>
    <div class="card mb-2">
        <div class="card-body py-2 d-flex justify-content-between align-items-center">
            <div>
                <span class="fw-semibold kpaw-mono"><?= htmlspecialchars(date('d-m-Y', strtotime($h['holiday_date']))) ?></span>
                <?php if ($h['reason']): ?><span class="small text-muted ms-2"><?= htmlspecialchars($h['reason']) ?></span><?php endif; ?>
            </div>
            <form method="post" onsubmit="return confirm('Remove this holiday?');">
                <?= csrf_field() ?>
                <input type="hidden" name="holiday_id" value="<?= (int) $h['id'] ?>">
                <button type="submit" name="delete_holiday" value="1" class="btn btn-sm btn-outline-danger">Remove</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$holidays): ?><p class="small text-muted">No holidays set.</p><?php endif; ?>

<div class="card mt-3">
    <div class="card-body">
        <div class="fw-bold small mb-2">Add Holiday</div>
        <form method="post">
            <?= csrf_field() ?>
            <div class="row g-2">
                <div class="col-5"><input type="date" name="holiday_date" class="form-control form-control-sm" required></div>
                <div class="col-7"><input type="text" name="reason" placeholder="Reason (optional)" class="form-control form-control-sm"></div>
            </div>
            <input type="hidden" name="blocks_all_meals" value="1">
            <button type="submit" name="add_holiday" value="1" class="btn btn-success btn-sm w-100 mt-2">Add Holiday</button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>