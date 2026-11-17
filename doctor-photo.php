<?php

require_once __DIR__ . '/config/mongodb.php';

$doctorId = (int) ($_GET['id'] ?? 0);

if ($doctorId <= 0) {
    http_response_code(404);
    exit;
}

$photo = $mongoDb
    ->selectCollection('doctor_photos')
    ->findOne(['doctor_id' => $doctorId]);

$allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
$mime = $photo['mime'] ?? '';

if (
    $photo === null ||
    !isset($photo['data']) ||
    !in_array($mime, $allowedTypes, true)
) {
    http_response_code(404);
    exit;
}

$bytes = $photo['data']->getData();

header('Content-Type: ' . $mime);
header('Content-Length: ' . strlen($bytes));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');

echo $bytes;