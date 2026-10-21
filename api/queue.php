<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';


/*
|--------------------------------------------------------------------------
| JSON Response
|--------------------------------------------------------------------------
*/

function queue_response(
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
| Authentication
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['user_id'])) {

    queue_response(
        false,
        'Login required.',
        [],
        401
    );
}


$user_id = (int)$_SESSION['user_id'];
$role    = (string)($_SESSION['role'] ?? '');


/*
|--------------------------------------------------------------------------
| Appointment ID
|--------------------------------------------------------------------------
*/

$appointment_id = filter_input(
    INPUT_GET,
    'appointment_id',
    FILTER_VALIDATE_INT
);

$appointment_id = $appointment_id ?: 0;

if ($appointment_id <= 0) {

    queue_response(
        false,
        'Invalid appointment.',
        [],
        422
    );
}


/*
|--------------------------------------------------------------------------
| Get Appointment
|--------------------------------------------------------------------------
*/

try {

    $appointmentsCollection = $mongoDb->selectCollection('appointments');
    $doctorsCollection = $mongoDb->selectCollection('doctors');
    $patientsCollection = $mongoDb->selectCollection('patients');
    $usersCollection = $mongoDb->selectCollection('users');

    $appointmentDoc = $appointmentsCollection->findOne([
        'appointment_id' => $appointment_id
    ]);

    if (!$appointmentDoc) {
        queue_response(false, 'Appointment not found.', [], 404);
    }

    $appointment = (array) $appointmentDoc;

    $doctor = $doctorsCollection->findOne([
        'doctor_id' => (int) ($appointment['doctor_id'] ?? 0)
    ]);

    $patient = $patientsCollection->findOne([
        'patient_id' => (int) ($appointment['patient_id'] ?? 0)
    ]);

    if (!$doctor || !$patient) {
        queue_response(false, 'Appointment not found.', [], 404);
    }

    $doctorUser = $usersCollection->findOne([
        'user_id' => (int) ($doctor->user_id ?? 0)
    ]);

    $patientUser = $usersCollection->findOne([
        'user_id' => (int) ($patient->user_id ?? 0)
    ]);

    if (!$doctorUser || !$patientUser) {
        queue_response(false, 'Appointment not found.', [], 404);
    }

    $appointment['doctor_user_id'] = (int) $doctorUser->user_id;
    $appointment['doctor_name'] = (string) ($doctorUser->name ?? '');
    $appointment['patient_name'] = (string) ($patientUser->name ?? '');


    /*
    |--------------------------------------------------------------------------
    | Role Based Access Control
    |--------------------------------------------------------------------------
    */

    if ($role === 'patient') {

        $patient_id = get_patient_id($user_id);

        if (
            !$patient_id ||
            (int)$appointment['patient_id'] !== (int)$patient_id
        ) {

            queue_response(
                false,
                'Access denied.',
                [],
                403
            );
        }

    } elseif ($role === 'doctor') {

        if (
            (int)$appointment['doctor_user_id'] !== $user_id
        ) {

            queue_response(
                false,
                'Access denied.',
                [],
                403
            );
        }

    } elseif ($role !== 'admin') {

        queue_response(
            false,
            'Access denied.',
            [],
            403
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Basic Queue Data
    |--------------------------------------------------------------------------
    */

    $doctor_id = (int)$appointment['doctor_id'];
    $date      = $appointment['appointment_date'];
    $token     = $appointment['token_no'] !== null
        ? (int)$appointment['token_no']
        : null;

    $current_token = null;
    $ahead = 0;


    /*
    |--------------------------------------------------------------------------
    | Current Token
    |--------------------------------------------------------------------------
    |
    | The current token is the highest token currently being served.
    |
    */

    $currentDoc = $appointmentsCollection->find(
        [
            'doctor_id' => $doctor_id,
            'appointment_date' => $date,
            'status' => 'In Progress',
            'token_no' => ['$ne' => null]
        ],
        [
            'sort' => ['token_no' => -1],
            'limit' => 1,
            'projection' => ['token_no' => 1]
        ]
    )->toArray();

    $currentValue = !empty($currentDoc)
        ? ($currentDoc[0]->token_no ?? null)
        : null;

    if ($currentValue !== null) {
        $current_token = (int) $currentValue;
    }


    /*
    |--------------------------------------------------------------------------
    | Determine Queue Position
    |--------------------------------------------------------------------------
    */

    if ($token !== null) {

        /*
        |--------------------------------------------------------------------------
        | Tokens Ahead
        |--------------------------------------------------------------------------
        |
        | Checked-in patients and the currently served patient are counted.
        |
        */

        if (
            !in_array(
                $appointment['status'],
                [
                    'Completed',
                    'Cancelled',
                    'No-show'
                ],
                true
            )
        ) {

            $ahead = $appointmentsCollection->countDocuments([
                'doctor_id' => $doctor_id,
                'appointment_date' => $date,
                'token_no' => [
                    '$ne' => null,
                    '$lt' => $token
                ],
                'status' => [
                    '$in' => ['Checked-in', 'In Progress']
                ]
            ]);
        }
    }


    /*
    |--------------------------------------------------------------------------
    | Queue Message
    |--------------------------------------------------------------------------
    */

    switch ($appointment['status']) {

        case 'In Progress':

            $message =
                'You are currently being served.';

            break;


        case 'Completed':

            $message =
                'Appointment completed.';

            break;


        case 'Cancelled':

            $message =
                'Appointment cancelled.';

            break;


        case 'No-show':

            $message =
                'Appointment marked as no-show.';

            break;


        case 'Requested':

            $message =
                'Appointment is waiting for confirmation.';

            break;


        case 'Confirmed':

            if ($token === null) {

                $message =
                    'Your appointment is confirmed. Token has not been assigned yet.';

            } else {

                $currentText = $current_token !== null
                    ? (string)$current_token
                    : 'Not started';

                $message =
                    "Your token: {$token} · " .
                    "Current token: {$currentText} · " .
                    "Ahead: {$ahead}";
            }

            break;


        case 'Checked-in':

            if ($token === null) {

                $message =
                    'You are checked in, but a token has not been assigned yet.';

            } else {

                $currentText = $current_token !== null
                    ? (string)$current_token
                    : 'Not started';

                $message =
                    "Your token: {$token} · " .
                    "Current token: {$currentText} · " .
                    "Ahead: {$ahead}";
            }

            break;


        default:

            $message =
                'Queue information is currently unavailable.';

            break;
    }


    /*
    |--------------------------------------------------------------------------
    | Response
    |--------------------------------------------------------------------------
    */

    queue_response(
        true,
        $message,
        [
            'appointment_id' => (int)$appointment['appointment_id'],
            'appointment_ref' => $appointment['appointment_ref'],
            'status' => $appointment['status'],
            'token' => $token,
            'current_token' => $current_token,
            'ahead' => $ahead,
            'appointment_date' => $date,
            'start_time' => $appointment['start_time'],
            'doctor_id' => $doctor_id
        ]
    );


} catch (Throwable $e) {

    error_log(
        'Queue API Error: ' .
        $e->getMessage()
    );

    queue_response(
        false,
        'Unable to load queue information right now.',
        [],
        500
    );
}