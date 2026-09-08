<?php
require_once __DIR__ . '/../includes/bootstrap.php';

unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role'], $_SESSION['admin_canteen_id']);
session_regenerate_id(true);

header('Location: /admin/login.php');
exit;