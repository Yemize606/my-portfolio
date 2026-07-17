<?php
// ============================================================
//  notify/sms.php
//  Sends SMS via Termii API (Nigerian gateway).
//
//  SETUP:
//  1. Register at https://termii.com
//  2. Get your API key from the dashboard
//  3. Register a sender ID (e.g. "LASUHealth") — takes 24–48h
//  4. Fill in TERMII_API_KEY and TERMII_SENDER_ID below
// ============================================================

// Set these as environment variables (Render dashboard > Environment).
define('TERMII_API_KEY',   getenv('TERMII_API_KEY')   ?: '');
define('TERMII_SENDER_ID', getenv('TERMII_SENDER_ID') ?: 'LASUHealth');   // max 11 chars, approved by Termii
define('TERMII_BASE_URL',  'https://api.ng.termii.com/api/sms/send');

/**
 * Send an SMS via Termii.
 *
 * @param string $phone   Phone number with country code, e.g. +2348012345678
 * @param string $message SMS body (max 160 chars for single SMS)
 * @return bool           true on success, false on failure
 */
function sendSMS(string $phone, string $message): bool {

    // Normalise phone: remove spaces and ensure +234 format
    $phone = preg_replace('/\s+/', '', $phone);
    if (str_starts_with($phone, '0')) {
        $phone = '+234' . substr($phone, 1);
    }
    if (!str_starts_with($phone, '+')) {
        $phone = '+' . $phone;
    }

    $payload = json_encode([
        'to'        => $phone,
        'from'      => TERMII_SENDER_ID,
        'sms'       => $message,
        'type'      => 'plain',
        'channel'   => 'generic',
        'api_key'   => TERMII_API_KEY,
    ]);

    $ch = curl_init(TERMII_BASE_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log('Termii cURL error: ' . $curlErr);
        return false;
    }

    $data = json_decode($response, true);

    // Termii returns "ok" in the message field on success
    if ($httpCode === 200 && isset($data['message']) && strtolower($data['message']) === 'successfully sent') {
        return true;
    }

    error_log('Termii SMS failed (' . $httpCode . '): ' . $response);
    return false;
}
