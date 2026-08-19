<?php
/**
 * Shared header. Include after bootstrap.php + flash.php.
 * Mobile-first Bootstrap 5, per the original blueprint.
 */
$flash = function_exists('flash_get') ? flash_get() : null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>KPAW Canteen Portal</title>
<link rel="manifest" href="/manifest.json">
<meta name="theme-color" content="#0d6efd">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 480px;">
    <div class="text-center mb-4">
        <h3 class="mb-1">KPAW Canteen Portal</h3>
        <p class="text-muted small mb-0">Loco Canteen (Annapurna) &middot; Carriage Canteen (Zaika)</p>
    </div>
    <?php if ($flash): ?>
        <div class="alert alert-<?= $flash['type'] === 'error' ? 'danger' : 'success' ?>">
            <?= htmlspecialchars($flash['message']) ?>
        </div>
    <?php endif; ?>
