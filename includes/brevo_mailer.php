<?php

if (!function_exists('sendMailViaBrevo')) {
    function sendMailViaBrevo(
        string $toEmail,
        string $toName,
        string $subject,
        string $html,
        ?string $replyToEmail = null,
        ?string $replyToName = null
    ): bool {
        $apiKey = getenv('BREVO_API_KEY');
        if (!$apiKey) {
            error_log('BREVO_API_KEY is missing');
            return false;
        }

        $mailConfig = require __DIR__ . '/../config/mail.php';

        $payload = [
            'sender'      => [
                'name'  => $mailConfig['from_name'],
                'email' => $mailConfig['from_email'],
            ],
            'to'          => [['email' => $toEmail, 'name' => $toName]],
            'subject'     => $subject,
            'htmlContent' => $html,
        ];

        if ($replyToEmail) {
            $payload['replyTo'] = [
                'email' => $replyToEmail,
                'name'  => $replyToName ?: $replyToEmail,
            ];
        }

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
        ]);

        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code < 200 || $code >= 300) {
            error_log("Brevo mail error HTTP $code: $res");
            return false;
        }

        return true;
    }
}
