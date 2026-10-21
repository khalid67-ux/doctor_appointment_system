<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/auth.php';


/*
|--------------------------------------------------------------------------
| JSON Response
|--------------------------------------------------------------------------
*/

function notification_response(
    bool $success,
    int $count = 0,
    string $message = '',
    int $statusCode = 200
): void {

    http_response_code($statusCode);

    echo json_encode([
        'success' => $success,
        'count'   => $count,
        'message' => $message
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    exit;
}


/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['user_id'])) {

    notification_response(
        false,
        0,
        'Authentication required.',
        401
    );
}


$user_id = (int)$_SESSION['user_id'];

if ($user_id <= 0) {

    notification_response(
        false,
        0,
        'Invalid user session.',
        401
    );
}


/*
|--------------------------------------------------------------------------
| Get Unread Notification Count
|--------------------------------------------------------------------------
*/

try {

    $notificationsCollection = $mongoDb->selectCollection('notifications');

    $count = $notificationsCollection->countDocuments([
        'user_id' => $user_id,
        'is_read' => 0
    ]);


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    notification_response(
        true,
        $count,
        'Notification count loaded successfully.'
    );


} catch (Throwable $e) {

    error_log(
        'Notifications API Error: ' .
        $e->getMessage()
    );

    notification_response(
        false,
        0,
        'Unable to load notifications right now.',
        500
    );
}