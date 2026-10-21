<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

/*
|--------------------------------------------------------------------------
| JSON Response Helper
|--------------------------------------------------------------------------
*/
function favorite_response(
    bool $success,
    string $message,
    array $data = [],
    int $statusCode = 200
): void {
    http_response_code($statusCode);

    echo json_encode(
        array_merge(
            [
                'success' => $success,
                'message' => $message
            ],
            $data
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| Patient Authentication
|--------------------------------------------------------------------------
*/
if (
    empty($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'patient'
) {
    favorite_response(false, 'Patient access required.', [], 403);
}

/*
|--------------------------------------------------------------------------
| POST Request Only
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    favorite_response(false, 'Invalid request method.', [], 405);
}

/*
|--------------------------------------------------------------------------
| CSRF Protection
|--------------------------------------------------------------------------
*/
$csrfToken = trim(
    $_POST['csrf_token']
    ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')
);

if (
    empty($_SESSION['csrf_token']) ||
    empty($csrfToken) ||
    !hash_equals(
        (string) $_SESSION['csrf_token'],
        $csrfToken
    )
) {
    favorite_response(
        false,
        'Security token expired. Please refresh the page.',
        [],
        419
    );
}

/*
|--------------------------------------------------------------------------
| Patient
|--------------------------------------------------------------------------
*/
try {
    $patient_id = get_patient_id((int) $_SESSION['user_id']);
} catch (Throwable $e) {
    error_log('Favorite get_patient_id Error: ' . $e->getMessage());

    favorite_response(
        false,
        'Unable to verify patient profile.',
        [],
        500
    );
}

if (!$patient_id) {
    favorite_response(false, 'Patient profile not found.', [], 403);
}

$patient_id = (int) $patient_id;

/*
|--------------------------------------------------------------------------
| Doctor ID
|--------------------------------------------------------------------------
*/
$doctor_id = filter_input(
    INPUT_POST,
    'doctor_id',
    FILTER_VALIDATE_INT
);

$doctor_id = $doctor_id ?: 0;

if ($doctor_id <= 0) {
    favorite_response(false, 'Invalid doctor.', [], 422);
}

/*
|--------------------------------------------------------------------------
| MongoDB Collections
|--------------------------------------------------------------------------
*/
$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');
$favoritesCollection = $mongoDb->selectCollection('favorites');

/*
|--------------------------------------------------------------------------
| Verify Doctor
|--------------------------------------------------------------------------
*/
$doctor = $doctorsCollection->findOne([
    'doctor_id' => $doctor_id,
    'verification_status' => 'Approved'
]);

if (!$doctor) {
    favorite_response(
        false,
        'Doctor not found or unavailable.',
        [],
        404
    );
}

$doctorUserId = (int) ($doctor->user_id ?? 0);

$doctorUser = $usersCollection->findOne([
    'user_id' => $doctorUserId,
    'status' => 'active'
]);

if (!$doctorUser) {
    favorite_response(
        false,
        'Doctor not found or unavailable.',
        [],
        404
    );
}

/*
|--------------------------------------------------------------------------
| Toggle Favorite
|--------------------------------------------------------------------------
*/
try {
    $existingFavorite = $favoritesCollection->findOne([
        'patient_id' => $patient_id,
        'doctor_id' => $doctor_id
    ]);

    /*
    |--------------------------------------------------------------------------
    | Remove Favorite
    |--------------------------------------------------------------------------
    */
    if ($existingFavorite) {
        $deleteResult = $favoritesCollection->deleteOne([
            '_id' => $existingFavorite->_id,
            'patient_id' => $patient_id,
            'doctor_id' => $doctor_id
        ]);

        if ($deleteResult->getDeletedCount() !== 1) {
            favorite_response(
                false,
                'Favorite status could not be updated. Please try again.',
                [],
                409
            );
        }

        $isFavorite = false;
        $action = 'Removed doctor from favorites';
        $message = 'Doctor removed from favorites.';
    } else {
        /*
        |--------------------------------------------------------------------------
        | Add Favorite
        |--------------------------------------------------------------------------
        */
        $favoritesCollection->insertOne([
            'patient_id' => $patient_id,
            'doctor_id' => $doctor_id,
            'created_at' => new MongoDB\BSON\UTCDateTime()
        ]);

        $isFavorite = true;
        $action = 'Added doctor to favorites';
        $message = 'Doctor added to favorites.';
    }

    /*
    |--------------------------------------------------------------------------
    | Audit
    |--------------------------------------------------------------------------
    */
    if (function_exists('audit')) {
        audit(
            (int) $_SESSION['user_id'],
            $action,
            'doctor',
            $doctor_id
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Final Response
    |--------------------------------------------------------------------------
    */
    favorite_response(
        true,
        $message,
        [
            'favorite' => $isFavorite,
            'doctor_id' => $doctor_id
        ]
    );

} catch (MongoDB\Driver\Exception\BulkWriteException $e) {
    error_log('Favorite API MongoDB Error: ' . $e->getMessage());

    favorite_response(
        false,
        'Favorite status could not be updated. Please try again.',
        [],
        409
    );

} catch (Throwable $e) {
    error_log('Favorite API Error: ' . $e->getMessage());

    favorite_response(
        false,
        'Unable to update favorite right now.',
        [],
        500
    );
}
