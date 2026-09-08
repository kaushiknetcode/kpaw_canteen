<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/flash.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}

// Time-aware greeting — proper boundaries, not just "before noon = morning"
// (which would wrongly call 2 AM "morning" since 2 < 12).
$hour = (int) date('G');
if ($hour >= 5 && $hour < 12) {
    $greeting = 'Good morning';
} elseif ($hour >= 12 && $hour < 17) {
    $greeting = 'Good afternoon';
} elseif ($hour >= 17 && $hour < 21) {
    $greeting = 'Good evening';
} else {
    $greeting = 'Good night'; // 9 PM – 5 AM
}

// Admin notice board — now set via admin/settings.php, which also
// writes notice_expires_at. Treat an expired notice as if it were
// never set, without needing to actually clear the row.
$stmt = $pdo->prepare("SELECT setting_key, setting_value FROM app_settings WHERE setting_key IN ('notice_message', 'notice_expires_at')");
$stmt->execute();
$noticeSettings = [];
foreach ($stmt->fetchAll() as $row) {
    $noticeSettings[$row['setting_key']] = $row['setting_value'];
}
$noticeMessage = trim((string) ($noticeSettings['notice_message'] ?? ''));
$noticeExpiresAt = $noticeSettings['notice_expires_at'] ?? '';
if ($noticeMessage !== '' && $noticeExpiresAt !== '' && strtotime($noticeExpiresAt) <= time()) {
    $noticeMessage = ''; // expired — don't show it
}

require_once __DIR__ . '/../includes/header.php';
?>
<h4 class="mb-1"><?= htmlspecialchars($greeting) ?>, <?= htmlspecialchars($_SESSION['full_name']) ?>!</h4>
<p class="text-muted mb-3">
    Logged in as <?= $_SESSION['user_type'] === 'employee' ? 'an employee' : 'a guest' ?>.
</p>

<?php if ($noticeMessage !== ''): ?>
    <div class="kpaw-notice-board mb-4">
        <div class="kpaw-notice-board__icon">&#9888;&#65039;</div>
        <div>
            <div class="kpaw-notice-board__label">Notice</div>
            <div class="kpaw-notice-board__text"><?= nl2br(htmlspecialchars($noticeMessage)) ?></div>
        </div>
    </div>
<?php endif; ?>

<a href="/app/book.php" class="btn btn-primary btn-lg w-100 mb-2">Book a Meal</a>
<a href="/app/my-bookings.php" class="btn btn-outline-primary w-100 mb-4">My Bookings</a>

<div class="row g-2 mb-4">
    <div class="col-6">
        <a href="https://www.google.com/maps/dir/?api=1&destination=22.937887,88.4446068" target="_blank" rel="noopener" class="kpaw-direction-card">
            <div class="kpaw-direction-card__title">Loco Canteen</div>
            <div class="kpaw-direction-card__subtitle">Annapurna</div>
            <div class="kpaw-direction-card__cta">&#128205; Directions</div>
        </a>
    </div>
    <div class="col-6">
        <a href="https://www.google.com/maps/dir/?api=1&destination=22.935070,88.442791" target="_blank" rel="noopener" class="kpaw-direction-card">
            <div class="kpaw-direction-card__title">Carriage Canteen</div>
            <div class="kpaw-direction-card__subtitle">Zaika</div>
            <div class="kpaw-direction-card__cta">&#128205; Directions</div>
        </a>
    </div>
</div>

<a href="/auth/logout.php" class="btn btn-outline-danger btn-sm w-100">Log Out</a>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>