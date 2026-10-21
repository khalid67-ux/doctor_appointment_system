
<?php

require_once __DIR__ . '/mongodb_helpers.php';

try {
    $count = mongoCollection('appointments')->countDocuments([]);

    echo '<h2>MediBook MongoDB Helper Test</h2>';
    echo '<p>Helper: SUCCESS</p>';
    echo '<p>Appointments found: ' . $count . '</p>';

} catch (Throwable $e) {
    http_response_code(500);

    echo 'Test failed: '
        . htmlspecialchars($e->getMessage());
}
