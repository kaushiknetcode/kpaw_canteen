<?php
/**
 * KPAW Canteen Portal — Config Loader
 * Reads key=value pairs from .env into environment/constants.
 * No Composer dependency — keeps things simple for shared hosting.
 */

function kpaw_load_env(string $path): void
{
    if (!file_exists($path)) {
        die('Missing .env file. Copy .env.example to .env and fill in your credentials.');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $_ENV[$key] = $value;
        putenv("$key=$value");
    }
}

kpaw_load_env(__DIR__ . '/../.env');

define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
define('DB_NAME', $_ENV['DB_NAME'] ?? '');
define('DB_USER', $_ENV['DB_USER'] ?? '');
define('DB_PASS', $_ENV['DB_PASS'] ?? '');

define('SMTP_HOST', $_ENV['SMTP_HOST'] ?? '');
define('SMTP_PORT', $_ENV['SMTP_PORT'] ?? 587);
define('SMTP_USER', $_ENV['SMTP_USER'] ?? '');
define('SMTP_PASS', $_ENV['SMTP_PASS'] ?? '');

define('GMAIL_USER', $_ENV['GMAIL_USER'] ?? '');
define('GMAIL_APP_PASSWORD', $_ENV['GMAIL_APP_PASSWORD'] ?? '');
