<?php
/**
 * Admin section header. Deliberately separate from includes/header.php —
 * the admin dashboard needs its own nav/identity treatment, not the
 * Ahar logo row built for the employee-facing app. Still loads the
 * same assets/css/app.css, so colors, type, and components (.card,
 * .btn, .kpaw-password-wrap, etc.) stay visually consistent throughout.
 */
$isLoggedIn = !empty($_SESSION['admin_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Ahar</title>
<meta name="theme-color" content="#001F4E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="/assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: time() ?>" rel="stylesheet">
</head>
<body>
<header class="kpaw-admin-bar">
    <div class="kpaw-admin-bar__brand">
        <span class="kpaw-admin-bar__brand-text kpaw-brand-wordmark">Ahar</span>
        <span class="kpaw-admin-bar__tag"><?= ($isLoggedIn && $_SESSION['admin_role'] === 'receptionist') ? 'Receptionist' : 'Admin' ?></span>
    </div>
    <?php if ($isLoggedIn): ?>
        <div class="kpaw-admin-bar__identity">
            <?= htmlspecialchars($_SESSION['admin_name']) ?>
            <div>
                <span class="kpaw-admin-bar__role"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $_SESSION['admin_role']))) ?></span>
                <a href="<?= $_SESSION['admin_role'] === 'receptionist' ? '/counter/logout.php' : '/admin/logout.php' ?>" class="kpaw-admin-bar__logout">Log Out</a>
            </div>
        </div>
    <?php endif; ?>
</header>
<div class="container py-4" style="max-width: <?= (int) ($adminContainerMaxWidth ?? 480) ?>px;">
<?php
// "Back to Dashboard" at the TOP of every page, not the bottom — same
// link for every admin/receptionist page via this shared header,
// pointing at the right destination per role, hidden only on that
// exact dashboard page itself (no point linking to where you already are).
if ($isLoggedIn):
    $isReceptionist = $_SESSION['admin_role'] === 'receptionist';
    $dashboardPath = $isReceptionist ? '/counter/dashboard.php' : '/admin/dashboard.php';
    $dashboardLabel = $isReceptionist ? 'Back to Counter' : 'Back to Dashboard';
    if (($_SERVER['SCRIPT_NAME'] ?? '') !== $dashboardPath): ?>
        <a href="<?= $dashboardPath ?>" class="btn btn-link px-0 mb-2"><?= $dashboardLabel ?></a>
    <?php endif;
endif; ?>