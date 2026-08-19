<?php
/**
 * TEMPORARY STUB — Phase 3 replaces this with the real booking dashboard.
 * Exists now only so Phase 2's login flow can be tested end-to-end.
 */
require_once __DIR__ . '/../includes/bootstrap.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Dashboard — KPAW Canteen Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5" style="max-width: 480px;">
    <h4>Welcome, <?= htmlspecialchars($_SESSION['full_name']) ?>!</h4>
    <p class="text-muted">
        Logged in as <?= $_SESSION['user_type'] === 'employee' ? 'an employee' : 'a guest' ?>.
    </p>
    <a href="/app/book.php" class="btn btn-primary w-100 mb-2">Book a Meal</a>
    <a href="/auth/logout.php" class="btn btn-outline-danger w-100">Log Out</a>
</div>
</body>
</html>