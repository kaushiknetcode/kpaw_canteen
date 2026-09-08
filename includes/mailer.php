<?php
/**
 * Email delivery via Gmail SMTP (PHPMailer), not PHP's built-in mail().
 * Switched from mail() because emails sent from a throwaway
 * *.hostingersite.com subdomain were being silently dropped by Gmail —
 * mail() returned true but nothing arrived (no SPF/DKIM, no sending
 * history for that domain). Gmail SMTP actually authenticates as a real
 * sender, so it delivers reliably.
 *
 * Requires GMAIL_USER and GMAIL_APP_PASSWORD in .env — see .env.example.
 * GMAIL_APP_PASSWORD is a 16-character App Password from the Gmail
 * account's Google Account -> Security -> 2-Step Verification ->
 * App Passwords page (NOT the regular Gmail login password).
 */

require_once __DIR__ . '/PHPMailer/Exception.php';
require_once __DIR__ . '/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

function send_email(string $to, string $subject, string $htmlBody): bool
{
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = GMAIL_USER;
        $mail->Password   = GMAIL_APP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        $mail->setFrom(GMAIL_USER, 'KPAW Canteen Portal');
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        return $mail->send();
    } catch (PHPMailerException $e) {
        error_log('Mail send failed: ' . $mail->ErrorInfo);
        return false;
    }
}

function send_otp_email(string $to, string $name, string $otp): bool
{
    $subject = "Your KPAW Canteen Portal verification code";
    $body = "<p>Hi " . htmlspecialchars($name) . ",</p>"
          . "<p>Your verification code is: <strong style='font-size:22px;letter-spacing:2px;'>{$otp}</strong></p>"
          . "<p>This code expires in 10 minutes. If you didn't request this, you can ignore this email.</p>";
    return send_email($to, $subject, $body);
}

function send_password_reset_email(string $to, string $name, string $resetLink): bool
{
    $subject = "Reset your KPAW Canteen Portal password";
    $safeLink = htmlspecialchars($resetLink);
    $body = "<p>Hi " . htmlspecialchars($name) . ",</p>"
          . "<p>Click the link below to reset your password. This link expires in 30 minutes.</p>"
          . "<p><a href='{$safeLink}'>Reset Password</a></p>"
          . "<p>If you didn't request this, you can ignore this email.</p>";
    return send_email($to, $subject, $body);
}
