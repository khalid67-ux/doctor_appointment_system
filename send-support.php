
<?php

require_once __DIR__ . '/vendor/autoload.php';

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php#contact');
    exit;
}

verify_csrf();

$name = trim($_POST['name'] ?? '');
$email = trim($_POST['email'] ?? '');
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

$mailConfig = require __DIR__ . '/config/mail.php';

if (
    empty($mailConfig['username']) ||
    empty($mailConfig['password']) ||
    $mailConfig['password'] === 'YOUR_GMAIL_APP_PASSWORD'
) {
    error_log('MediBook SMTP credentials have not been configured.');
    http_response_code(500);
    exit('Email service is not configured yet. Please contact the administrator.');
}

$mail = new \PHPMailer\PHPMailer\PHPMailer(true);

try {
    $mail->isSMTP();
    $mail->Host       = $mailConfig['host'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $mailConfig['username'];
    $mail->Password   = $mailConfig['password'];
    $mail->Port       = (int) $mailConfig['port'];
    $mail->SMTPSecure = $mailConfig['encryption'];

    $mail->CharSet = 'UTF-8';

    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name']
    );

    $mail->addAddress($mailConfig['to_email']);

    // Replies will go to the visitor's supplied email.
    $mail->addReplyTo($email, $name);

    $mail->Subject = 'MediBook Support: ' . $subject;

    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $safeSubject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
    $safeMessage = nl2br(
        htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
    );

    $mail->isHTML(true);
    $mail->Body = "
        <h2>New MediBook Support Message</h2>
        <p><strong>Name:</strong> {$safeName}</p>
        <p><strong>Email:</strong> {$safeEmail}</p>
        <p><strong>Subject:</strong> {$safeSubject}</p>
        <p><strong>Message:</strong><br>{$safeMessage}</p>
    ";

    $mail->AltBody =
        "New MediBook Support Message\n\n" .
        "Name: {$name}\n" .
        "Email: {$email}\n" .
        "Subject: {$subject}\n\n" .
        "Message:\n{$message}";

    $mail->send();

    header('Location: index.php?contact=success#contact');
    exit;

} catch (\Throwable $e) {
    error_log('MediBook support email failed: ' . $e->getMessage());

    http_response_code(500);
    exit(
        'Sorry, your message could not be sent. ' .
        'Please try again later or call 01889106270.'
    );
}
