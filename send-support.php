<?php

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

require_once __DIR__ . '/includes/brevo_mailer.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#contact');
    exit;
}

verify_csrf();

$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$subject = trim($_POST['subject'] ?? '');
$message = trim($_POST['message'] ?? '');

if (
    $name === '' ||
    strlen($name) > 100 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    strlen($email) > 254 ||
    $subject === '' ||
    strlen($subject) > 150 ||
    $message === '' ||
    strlen($message) > 3000
) {
    http_response_code(422);
    exit('Invalid form information. Please go back and check your entries.');
}

if (!function_exists('sendMailViaBrevo')) {
    error_log('sendMailViaBrevo() function was not found. Check the require_once path.');
    http_response_code(500);
    exit('Email service is not configured yet. Please contact the administrator.');
}

$mailConfig = require __DIR__ . '/config/mail.php';

$safeName    = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail   = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$html = "
    <h2>New MediBook Support Message</h2>
    <p><strong>Name:</strong> {$safeName}</p>
    <p><strong>Email:</strong> {$safeEmail}</p>
    <p><strong>Subject:</strong> {$safeSubject}</p>
    <p><strong>Message:</strong><br>{$safeMessage}</p>
";

try {
    $sent = sendMailViaBrevo(
        $mailConfig['to_email'],
        'MediBook Support',
        'MediBook Support: ' . $subject,
        $html,
        $email,   // Reply-To: ভিজিটরের ইমেইল
        $name
    );
} catch (\Throwable $e) {
    error_log('MediBook support email failed: ' . $e->getMessage());
    $sent = false;
}

if ($sent) {
    header('Location: index.php?contact=success#contact');
    exit;
}

http_response_code(500);
exit(
    'Sorry, your message could not be sent. ' .
    'Please try again later or call 01889106270.'
);
