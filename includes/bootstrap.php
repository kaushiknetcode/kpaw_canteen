<?php
/**
 * KPAW Canteen Portal — Bootstrap
 * Include this ONE file at the top of every page (app/, counter/, admin/, auth/).
 * It sets the correct timezone (critical — every cutoff/expiry rule in
 * Phase 3 and Phase 6 depends on this being right), opens the DB connection,
 * starts a secure session, and auto-logs-in a returning user via their
 * "Remember Me" cookie if one is present and valid.
 */

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Auto-login via "Remember Me" cookie if there's no active session yet
if (empty($_SESSION['user_id']) && !empty($_COOKIE['remember_token'])) {
    $parts = explode(':', $_COOKIE['remember_token'], 3);
    if (count($parts) === 3) {
        [$type, $id, $token] = $parts;
        $table = $type === 'employee' ? 'users' : ($type === 'guest' ? 'guests' : null);
        if ($table && ctype_digit($id)) {
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id = :id AND remember_token = :token");
            $stmt->execute([':id' => $id, ':token' => $token]);
            $user = $stmt->fetch();
            if ($user) {
                session_regenerate_id(true);
                $_SESSION['user_type'] = $type;
                $_SESSION['user_id'] = (int) $user['id'];
                $_SESSION['full_name'] = $user['full_name'];
            }
        }
    }
}

