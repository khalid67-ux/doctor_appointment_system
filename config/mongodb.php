<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $uri = getenv('MONGODB_URI') ?: 'mongodb+srv://medibook:23w5m923dt@cluster0.dum4bn7.mongodb.net/?retryWrites=true&w=majority';

    $mongoClient = new MongoDB\Client($uri);

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
