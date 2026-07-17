<?php
// ============================================================
//  notify/mailer.php  — PHPMailer + Gmail App Password
//
//  SETUP:
//  1. Go to myaccount.google.com → Security → App Passwords
//  2. Generate a password for "LASU Health Center"
//  3. Paste your Gmail and App Password below
//  4. Make sure PHPMailer files are at:
//     C:\xampp\htdocs\lasu\vendor\phpmailer\src\
// ============================================================

require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ── Your Gmail credentials ───────────────────────────────────
// Set these as environment variables (Render dashboard > Environment).
// NEVER hardcode real credentials here — this file is committed to Git.
define('GMAIL_ADDRESS',      getenv('GMAIL_ADDRESS')      ?: '');
define('GMAIL_APP_PASSWORD', getenv('GMAIL_APP_PASSWORD') ?: '');
define('SENDER_NAME',        getenv('SENDER_NAME')        ?: 'LASU Health Center');

/**
 * Send an HTML email via Gmail SMTP.
 * Automatically tries port 587 first, then falls back to 465.
 */
function sendEmail(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainText = ''
): bool {
    // Try port 587 (TLS) first
    if (_trySend($toEmail, $toName, $subject, $htmlBody, $plainText, 587, PHPMailer::ENCRYPTION_STARTTLS)) {
        return true;
    }
    // Fallback to port 465 (SSL)
    error_log('PHPMailer: port 587 failed, trying 465...');
    return _trySend($toEmail, $toName, $subject, $htmlBody, $plainText, 465, PHPMailer::ENCRYPTION_SMTPS);
}

function _trySend(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlBody,
    string $plainText,
    int    $port,
    string $encryption
): bool {
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host        = 'smtp.gmail.com';
        $mail->SMTPAuth    = true;
        $mail->Username    = GMAIL_ADDRESS;
        $mail->Password    = GMAIL_APP_PASSWORD;
        $mail->SMTPSecure  = $encryption;
        $mail->Port        = $port;
        $mail->CharSet     = 'UTF-8';
        $mail->Timeout     = 20;

        // Disable SSL verification for local XAMPP environment
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->setFrom(GMAIL_ADDRESS, SENDER_NAME);
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo(GMAIL_ADDRESS, SENDER_NAME);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = $plainText ?: strip_tags($htmlBody);

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log("PHPMailer port {$port} failed to {$toEmail}: " . $mail->ErrorInfo);
        return false;
    }
}
