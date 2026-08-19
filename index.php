<?php
/**
 * Redirects to the dashboard if logged in, otherwise to login.
 */
require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['user_id'])) {
    header('Location: /app/dashboard.php');
} else {
    header('Location: /auth/login.php');
}
exit;

