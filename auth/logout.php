<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (!empty($_SESSION['user_type']) && !empty($_SESSION['user_id'])) {
    $table = $_SESSION['user_type'] === 'employee' ? 'users' : 'guests';
    $pdo->prepare("UPDATE {$table} SET remember_token = NULL WHERE id = :id")
        ->execute([':id' => $_SESSION['user_id']]);
}

$_SESSION = [];
session_destroy();
setcookie('remember_token', '', time() - 3600, '/');

header('Location: /auth/login.php');
exit;
