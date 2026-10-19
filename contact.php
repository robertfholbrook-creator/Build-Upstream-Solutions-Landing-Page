<?php
/*
 * Build Upstream Solutions: contact form handler.
 * Hostinger runs this file. It emails each inquiry to Rob with PHP mail() and keeps a
 * backup copy in inquiries-log.csv one folder above public_html, so nothing is lost if
 * an email lands in spam.
 *
 * The page's script posts here with "Accept: application/json" and gets JSON back.
 * Without JavaScript, the browser posts normally and is redirected back to the page.
 */

const TO_EMAIL   = 'rob@buildupstreamsolutions.com';
const FROM_EMAIL = 'no-reply@buildupstreamsolutions.com';
const SITE_NAME  = 'Build Upstream Solutions';
const TYPES      = ['A property owner or developer', 'A public organization', 'Something else'];

date_default_timezone_set('America/New_York');

$wantsJson = strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function finish($ok, $status, $error = '')
{
    global $wantsJson;
    if ($wantsJson) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($ok ? ['ok' => true] : ['ok' => false, 'error' => $error]);
    } else {
        header('Location: ./' . ($ok ? '?sent=1' : '?error=1') . '#contact', true, 303);
    }
    exit;
}

function field($key, $max)
{
    $value = trim((string) ($_POST[$key] ?? ''));
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

// Values that go into mail headers must be one line.
function oneLine($value)
{
    return trim(preg_replace('/[\r\n\t]+/', ' ', $value));
}

// Stop spreadsheet apps from running a cell as a formula.
function csvSafe($value)
{
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    finish(false, 405, 'Method not allowed.');
}

// Honeypot: people never see this field, bots fill it in. Pretend it worked.
if (field('website', 200) !== '') {
    finish(true, 200);
}

$name         = oneLine(field('name', 100));
$email        = oneLine(field('email', 200));
$organization = oneLine(field('organization', 150));
$type         = oneLine(field('type', 60));
$message      = field('message', 5000);

if (!in_array($type, TYPES, true)) {
    $type = 'Not given';
}
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    finish(false, 422, 'Please fill in your name, a valid email address and your message.');
}

$subject = 'Website inquiry from ' . $name;
if (function_exists('mb_encode_mimeheader')) {
    $subject = mb_encode_mimeheader($subject, 'UTF-8', 'Q');
}

$body = "New inquiry from the Build Upstream Solutions website\n\n"
    . "Name: {$name}\n"
    . "Email: {$email}\n"
    . 'Organization: ' . ($organization !== '' ? $organization : 'Not given') . "\n"
    . "Reaching out as: {$type}\n\n"
    . "What they want to change:\n{$message}\n\n"
    . 'Sent ' . date('Y-m-d H:i T') . "\n";

$headers = [
    'From: ' . SITE_NAME . ' <' . FROM_EMAIL . '>',
    'Reply-To: ' . str_replace(['"', '<', '>'], '', $name) . ' <' . $email . '>',
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
];

$sent = @mail(TO_EMAIL, $subject, $body, implode("\r\n", $headers), '-f' . FROM_EMAIL);

// Backup copy outside public_html, so it can't be opened from the web.
$logFile = dirname(__DIR__) . '/inquiries-log.csv';
$handle = @fopen($logFile, 'a');
if ($handle) {
    if (flock($handle, LOCK_EX)) {
        fputcsv($handle, array_map('csvSafe', [
            date('c'), $name, $email, $organization, $type, $message, $sent ? 'emailed' : 'email failed',
        ]));
        flock($handle, LOCK_UN);
    }
    fclose($handle);
}

if (!$sent) {
    finish(false, 500, 'The message could not be sent.');
}
finish(true, 200);
