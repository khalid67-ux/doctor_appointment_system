<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Waitlist Processor
|--------------------------------------------------------------------------
|
| Run from CLI / Cron:
|
| php api/process_waitlist.php
|
*/

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';


/*
|--------------------------------------------------------------------------
| CLI Protection
|--------------------------------------------------------------------------
|
| This script is intended for scheduled/background execution.
|
*/

if (PHP_SAPI !== 'cli') {

    http_response_code(403);

    exit(
        "This script can only be executed from CLI/cron.\n"
    );
}


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$processed = 0;
$notified  = 0;
$skipped   = 0;
$errors    = 0;


/*
|--------------------------------------------------------------------------
| Load Waiting List
|--------------------------------------------------------------------------
*/

try {
    $waitlistsCollection = $mongoDb->selectCollection('waitlists');
    $patientsCollection = $mongoDb->selectCollection('patients');
    $usersCollection = $mongoDb->selectCollection('users');

    $rows = [];

    $cursor = $waitlistsCollection->find(
        ['status' => 'Waiting'],
        ['sort' => ['created_at' => 1]]
    );

    foreach ($cursor as $waitlist) {
        $w = (array) $waitlist;

        $patient = $patientsCollection->findOne([
            'patient_id' => (int) ($w['patient_id'] ?? 0)
        ]);

        if (!$patient) {
            continue;
        }

        $user = $usersCollection->findOne([
            'user_id' => (int) ($patient->user_id ?? 0)
        ]);

        if (!$user) {
            continue;
        }

        $w['patient_user'] = (int) ($user->user_id ?? 0);
        $rows[] = $w;
    }

} catch (Throwable $e) {
    error_log(
        'Waitlist Processor Load Error: ' . $e->getMessage()
    );

    exit("Unable to load waitlist records.\n");
}


/*
|--------------------------------------------------------------------------
| Process Each Waitlist
|--------------------------------------------------------------------------
*/

foreach ($rows as $w) {

    $processed++;

    $waitlist_id = (int)($w['waitlist_id'] ?? 0);
    $doctor_id   = (int)($w['doctor_id'] ?? 0);
    $patient_user = (int)($w['patient_user'] ?? 0);

    $preferred_date = trim(
        (string)($w['preferred_date'] ?? '')
    );

    $preferred_time = trim(
        (string)($w['preferred_time'] ?? '')
    );


    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */

    if (
        $waitlist_id <= 0 ||
        $doctor_id <= 0 ||
        $patient_user <= 0 ||
        !valid_date($preferred_date)
    ) {

        $skipped++;

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Ignore Past Dates
    |--------------------------------------------------------------------------
    */

    if ($preferred_date < date('Y-m-d')) {

        $skipped++;

        continue;
    }


    /*
    |--------------------------------------------------------------------------
    | Determine Available Time
    |--------------------------------------------------------------------------
    */

    $available_time = null;


    try {

        /*
        |--------------------------------------------------------------------------
        | Preferred Time Requested
        |--------------------------------------------------------------------------
        |
        | Check the exact requested time.
        |
        */

        if ($preferred_time !== '') {

            if (
                doctor_is_available(
                    $doctor_id,
                    $preferred_date,
                    $preferred_time
                )
            ) {

                $available_time = $preferred_time;
            }

        }


        /*
        |--------------------------------------------------------------------------
        | No Preferred Time
        |--------------------------------------------------------------------------
        |
        | Find the first available slot for the requested date.
        |
        */

        else {

            $available_slots = generate_slots(
                $doctor_id,
                $preferred_date
            );

            if (is_array($available_slots)) {

                foreach ($available_slots as $slot) {

                    if (!is_array($slot)) {
                        continue;
                    }

                    /*
                    | The generated slot may use start_time.
                    */

                    $slot_available = $slot['available'] ?? false;

                    if (!$slot_available) {
                        continue;
                    }

                    $slot_time = trim(
                        (string)(
                            $slot['start_time']
                            ?? $slot['time']
                            ?? ''
                        )
                    );

                    if ($slot_time === '') {
                        continue;
                    }

                    $available_time = $slot_time;

                    break;
                }
            }
        }


        /*
        |--------------------------------------------------------------------------
        | No Available Slot
        |--------------------------------------------------------------------------
        */

        if ($available_time === null) {

            $skipped++;

            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Create Notification
        |--------------------------------------------------------------------------
        */

        $message =
            "A slot may now be available on " .
            $preferred_date .
            " at " .
            $available_time .
            ". Please open the doctor page and book it.";


        create_notification(
            $patient_user,
            'waitlist',
            'Waitlist Slot Available',
            $message
        );


        /*
        |--------------------------------------------------------------------------
        | Mark Waitlist As Notified
        |--------------------------------------------------------------------------
        */

        $updateResult = $waitlistsCollection->updateOne(
            [
                'waitlist_id' => $waitlist_id,
                'status' => 'Waiting'
            ],
            [
                '$set' => [
                    'status' => 'Notified'
                ]
            ]
        );


        /*
        |--------------------------------------------------------------------------
        | Count Notification
        |--------------------------------------------------------------------------
        */

        if ($updateResult->getModifiedCount() > 0) {
            $notified++;
        } else {
            $skipped++;
        }

    } catch (Throwable $e) {

        $errors++;

        error_log(
            'Waitlist Processor Error for ID ' .
            $waitlist_id .
            ': ' .
            $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Final CLI Output
|--------------------------------------------------------------------------
*/

echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "        WAITLIST PROCESSOR RESULT        " . PHP_EOL;
echo "========================================" . PHP_EOL;
echo "Processed : {$processed}" . PHP_EOL;
echo "Notified  : {$notified}" . PHP_EOL;
echo "Skipped   : {$skipped}" . PHP_EOL;
echo "Errors    : {$errors}" . PHP_EOL;
echo "========================================" . PHP_EOL;