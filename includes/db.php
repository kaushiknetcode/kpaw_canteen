<?php
/**
 * KPAW Canteen Portal — Database Connection
 * Phase 0 deliverable: a single, reusable PDO connection.
 * Every query in this app should use prepared statements against $pdo —
 * never string-concatenated SQL (see Phase 10 security checklist).
 */

require_once __DIR__ . '/config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    // Never echo raw DB errors to the browser in production — log instead.
    error_log('KPAW DB connection failed: ' . $e->getMessage());
    die('Database connection error. Please try again later.');
}
