<?php
/**
 * Login rate-limiting, backed by the login_attempts table.
 */

const RATE_LIMIT_MAX_ATTEMPTS = 5;
const RATE_LIMIT_WINDOW_MINUTES = 15;

function record_login_attempt(PDO $pdo, string $identifier, bool $success): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO login_attempts (identifier, ip_address, success) VALUES (:identifier, :ip, :success)"
    );
    $stmt->execute([
        ':identifier' => $identifier,
        ':ip'         => $_SERVER['REMOTE_ADDR'] ?? null,
        ':success'    => $success ? 1 : 0,
    ]);
}

function is_rate_limited(PDO $pdo, string $identifier): bool
{
    // RATE_LIMIT_WINDOW_MINUTES is a fixed constant, never user input — safe to interpolate directly.
    $minutes = RATE_LIMIT_WINDOW_MINUTES;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM login_attempts
         WHERE identifier = :identifier
           AND success = 0
           AND attempted_at >= (NOW() - INTERVAL {$minutes} MINUTE)"
    );
    $stmt->execute([':identifier' => $identifier]);
    return (int) $stmt->fetchColumn() >= RATE_LIMIT_MAX_ATTEMPTS;
}
