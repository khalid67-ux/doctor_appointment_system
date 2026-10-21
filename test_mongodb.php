<?php

require_once __DIR__ . '/vendor/autoload.php';

try {
    $client = new MongoDB\Client(
        'mongodb://127.0.0.1:27017'
    );

    $database = $client->selectDatabase('doctor_appointment_db');

    $result = $database->command(['ping' => 1])->toArray();

    if (($result[0]->ok ?? 0) == 1) {
        echo "MongoDB connection successful!";
    } else {
        echo "MongoDB connection failed.";
    }

} catch (Throwable $e) {
    echo "Connection error: " . htmlspecialchars($e->getMessage());
}