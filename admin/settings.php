<?php
/**
 * Admin Settings — two controls, both finally getting a real UI:
 *
 * 1. Notice board — the employee dashboard already reads and displays
 *    app_settings.notice_message; this just adds the missing admin UI
 *    for it, plus a genuinely new piece: auto-expiry (1-7 days), so a
 *    notice doesn't have to be manually cleared later.
 *
 * 2. Booking pause — bookings_are_stopped() is already fully wired
 *    into book.php and blocks booking correctly; this adds the
 *    missing toggle to actually set it.
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

function kpaw_set_setting(PDO $pdo, string $key, string $value): void
{
    $pdo->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([':k' => $key, ':v' => $value]);
}

function kpaw_get_setting(PDO $pdo, string $key): ?string
{
    $stmt = $pdo->prepare("SELECT setting_value FROM app_settings WHERE setting_key = :k");
    $stmt->execute([':k' => $key]);
    $val = $stmt->fetchColumn();
    return $val === false ? null : $val;
}

$success = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {

    if (isset($_POST['save_notice'])) {
        $message = trim($_POST['notice_message'] ?? '');
        $daysRaw = $_POST['notice_days'] ?? '';
        $isForever = $daysRaw === 'forever';
        $days = $isForever ? 0 : (int) $daysRaw;

        if ($message === '') {
            $error = "Enter a notice message.";
        } elseif (!$isForever && ($days < 1 || $days > 7)) {
            $error = "Choose how many days (1-7), or Forever.";
        } else {
            kpaw_set_setting($pdo, 'notice_message', $message);
            if ($isForever) {
                // No expiry at all — only "Clear Notice Now" removes it.
                kpaw_set_setting($pdo, 'notice_expires_at', '');
                $success = "Notice set — will stay up until you clear it.";
            } else {
                $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));
                kpaw_set_setting($pdo, 'notice_expires_at', $expiresAt);
                $success = "Notice set — will automatically stop showing after " . date('d-m-Y', strtotime($expiresAt)) . ".";
            }
        }
    } elseif (isset($_POST['clear_notice'])) {
        kpaw_set_setting($pdo, 'notice_message', '');
        kpaw_set_setting($pdo, 'notice_expires_at', '');
        $success = "Notice cleared.";
    } elseif (isset($_POST['toggle_bookings'])) {
        $current = kpaw_get_setting($pdo, 'bookings_stopped') === '1';
        kpaw_set_setting($pdo, 'bookings_stopped', $current ? '0' : '1');
        $success = $current ? "Bookings resumed." : "Bookings paused — employees will see a message and can't book.";
    }
}

$noticeMessage = kpaw_get_setting($pdo, 'notice_message') ?? '';
$noticeExpiresAt = kpaw_get_setting($pdo, 'notice_expires_at') ?? '';
$noticeIsLive = $noticeMessage !== '' && ($noticeExpiresAt === '' || strtotime($noticeExpiresAt) > time());
$bookingsStopped = kpaw_get_setting($pdo, 'bookings_stopped') === '1';

require_once __DIR__ . '/../includes/admin-header.php';
?>
<h5 class="mb-3">Settings</h5>

<?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <h6 class="mb-2">Notice Board</h6>
        <p class="small text-muted">Shows as a banner on every employee's dashboard until it expires or you clear it.</p>

        <?php if ($noticeIsLive): ?>
            <div class="alert alert-warning">
                <strong>Currently showing:</strong> <?= htmlspecialchars($noticeMessage) ?>
                <div class="small mt-1">Expires <?= htmlspecialchars(date('d-m-Y g:i A', strtotime($noticeExpiresAt))) ?></div>
            </div>
            <form method="post" class="mb-3">
                <?= csrf_field() ?>
                <button type="submit" name="clear_notice" value="1" class="btn btn-outline-danger btn-sm">Clear Notice Now</button>
            </form>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <label class="form-label small mb-1"><?= $noticeIsLive ? 'Replace with a new notice' : 'Set a notice' ?></label>
            <textarea name="notice_message" class="form-control mb-2" rows="2" required></textarea>
            <label class="form-label small mb-1">Show for how many days?</label>
            <select name="notice_days" class="form-select form-select-sm mb-2" style="max-width: 200px;" required>
                <?php for ($d = 1; $d <= 7; $d++): ?>
                    <option value="<?= $d ?>"><?= $d ?> day<?= $d > 1 ? 's' : '' ?></option>
                <?php endfor; ?>
                <option value="forever">Forever (until cleared)</option>
            </select>
            <button type="submit" name="save_notice" value="1" class="btn btn-primary btn-sm">Save Notice</button>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h6 class="mb-2">Bookings</h6>
        <p class="small text-muted">Pausing stops every employee from booking anywhere — they'll see "Bookings are currently paused by the admin" wherever they try.</p>
        <p class="mb-2">
            Status:
            <span class="badge bg-<?= $bookingsStopped ? 'danger' : 'success' ?>"><?= $bookingsStopped ? 'Paused' : 'Active' ?></span>
        </p>
        <form method="post" onsubmit="return confirm('<?= $bookingsStopped ? 'Resume bookings?' : 'Pause bookings for everyone right now?' ?>');">
            <?= csrf_field() ?>
            <button type="submit" name="toggle_bookings" value="1" class="btn <?= $bookingsStopped ? 'btn-success' : 'btn-danger' ?> btn-sm">
                <?= $bookingsStopped ? 'Resume Bookings' : 'Pause Bookings' ?>
            </button>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/admin-footer.php'; ?>