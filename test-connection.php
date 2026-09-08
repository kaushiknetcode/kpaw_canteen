<?php
/**
 * Phase 0 acceptance test.
 * Delete this file once Phase 0 is signed off — it's not part of the app.
 */
require_once __DIR__ . '/includes/bootstrap.php';

echo "Server time (should be IST): " . date('Y-m-d H:i:s') . "<br>";

try {
    $stmt = $pdo->query("SELECT COUNT(*) AS canteen_count FROM canteens");
    $result = $stmt->fetch();
    echo "DB connected. Canteens found: " . $result['canteen_count'];
} catch (PDOException $e) {
    echo "DB query failed — did you run sql/migrations/001_phase1_schema.sql yet?";
}
