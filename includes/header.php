<?php
/**
 * Shared header. Include after bootstrap.php + flash.php.
 * Mobile-first Bootstrap 5, per the original blueprint.
 * Themed via assets/css/app.css — see that file for the design tokens.
 */
$flash = function_exists('flash_get') ? flash_get() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ahar — KPAW Canteen Portal</title>
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/assets/img/icon-192.png">
<meta name="theme-color" content="#001F4E">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="/assets/css/app.css?v=<?= @filemtime(__DIR__ . '/../assets/css/app.css') ?: time() ?>" rel="stylesheet">
</head>
<body>
<header class="kpaw-header-bar">
    <h3 class="kpaw-header-bar__title kpaw-brand-wordmark">Ahar</h3>
</header>
<div class="container py-4" style="max-width: 480px;">
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>