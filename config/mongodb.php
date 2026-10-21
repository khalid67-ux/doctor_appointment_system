
<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $mongoClient = new MongoDB\Client(
        'mongodb://127.0.0.1:27017'
    );

    $mongoDb = $mongoClient->selectDatabase(
        'doctor_appointment_db'
    );

    $mongoDb->command(['ping' => 1]);

} catch (Throwable $e) {
    error_log(
        'MongoDB Connection Error: ' . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'MongoDB connection failed. '
        . 'Please check whether MongoDB is running.'
    );
}
