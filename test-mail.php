<?php
/**
 * TEMPORARY DIAGNOSTIC — delete after use.
 * Tests email delivery via Gmail SMTP (PHPMailer), isolated from the app.
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/mailer.php';

$to = $_GET['to'] ?? '';

if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    die('Usage: test-mail.php?to=your_real_email@example.com');
}

if (GMAIL_USER === '' || GMAIL_APP_PASSWORD === '') {
    die('GMAIL_USER / GMAIL_APP_PASSWORD are not set in .env yet.');
}

$result = send_email($to, 'KPAW Gmail SMTP test', 'If you received this, Gmail SMTP is working correctly.');

echo "<pre>";
echo "send_email() result: " . var_export($result, true) . "\n";
echo "</pre>";
