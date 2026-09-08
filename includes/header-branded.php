<?php
/**
 * Branded header for the login screen ONLY. Every other page keeps using
 * the lean includes/header.php — this is the one place that gets the
 * full 3-logo ceremonial treatment (side office logos + big Ahar
 * emblem), matching how Indian Railways' own official documents flank
 * a central emblem with a ministry/department logo on each side.
 *
 * To use: in login.php, replace
 *     require_once __DIR__ . '/../includes/header.php';
 * with
 *     require_once __DIR__ . '/../includes/header-branded.php';
 * Nothing else about login.php needs to change — $flash and the
 * container div work identically to the regular header.php.
 *
 * Side logos: drop your office logo files at
 *   /assets/img/left-logo.png  and  /assets/img/right-logo.png
 * They gracefully hide (not a broken-image icon) until those files
 * exist, so this is safe to deploy before you have the actual logos.
 * Big emblem goes at /assets/img/middle-logo.png (the one you sent).
 */
$flash = function_exists('flash_get') ? flash_get() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ahar — KPAW Canteen Portal</title>

<?php
// Open Graph / Twitter Card — what WhatsApp, Facebook, etc. actually
// read to build a rich link preview. Built from the request's own
// host, never hardcoded — works unchanged on any domain, same as
// everything else in this project.
$kpawScheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$kpawHost = $_SERVER['HTTP_HOST'] ?? '';
$kpawOgTitle = 'Ahar — KPAW Canteen Portal';
$kpawOgDescription = "Book your meals at Kanchrapara Workshop's canteens — fast UPI payment, a digital token, no more queues.";
$kpawOgImage = $kpawScheme . $kpawHost . '/assets/img/middle-logo.png';
$kpawOgUrl = $kpawScheme . $kpawHost . '/auth/login.php';
?>
<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($kpawOgTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($kpawOgDescription) ?>">
<meta property="og:image" content="<?= htmlspecialchars($kpawOgImage) ?>">
<meta property="og:url" content="<?= htmlspecialchars($kpawOgUrl) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($kpawOgTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($kpawOgDescription) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($kpawOgImage) ?>">

<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#001F4E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="/assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: time() ?>" rel="stylesheet">
</head>
<body>
<div class="kpaw-login-logos">
    <img src="/assets/img/left-logo.png" alt="" class="kpaw-login-logo-side kpaw-login-logo-side--left"
         onerror="this.style.display='none'">
    <img src="/assets/img/middle-logo.png" alt="Ahar" class="kpaw-login-logo-main"
         onerror="this.style.display='none'">
    <img src="/assets/img/right-logo.png" alt="" class="kpaw-login-logo-side kpaw-login-logo-side--right"
         onerror="this.style.display='none'">
</div>
<div class="kpaw-brand-name kpaw-brand-wordmark">Ahar</div>
<p class="kpaw-brand-subtitle">Eastern Railway, Kanchrapara Workshop</p>
<p class="kpaw-brand-tagline">Canteen Portal</p>

<div class="container py-4" style="max-width: 480px;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>