
<?php

require_once __DIR__ . '/config/mongodb.php';

try {
    $collections = iterator_to_array(
        $mongoDb->listCollectionNames(),
        false
    );

    echo '<h2>MediBook MongoDB Configuration Test</h2>';
    echo '<p>Connection: SUCCESS</p>';

    echo '<p>Database: '
        . htmlspecialchars($mongoDb->getDatabaseName())
        . '</p>';

    echo '<p>Collections: '
        . count($collections)
        . '</p>';

} catch (Throwable $e) {
    http_response_code(500);

    echo 'Test failed: '
        . htmlspecialchars($e->getMessage());
}