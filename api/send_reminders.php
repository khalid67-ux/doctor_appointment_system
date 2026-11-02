<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Appointment Reminder Processor
|--------------------------------------------------------------------------
|
| Run from CLI / Cron:
|
| php api/send_reminders.php
|
*/

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';


/*
|--------------------------------------------------------------------------
| CLI Protection
|--------------------------------------------------------------------------
*/

if (PHP_SAPI !== 'cli') {

    http_response_code(403);

    exit(
        "This script can only be executed from CLI/cron.\n"
    );
}


/*
|--------------------------------------------------------------------------
| Reminder Window
|--------------------------------------------------------------------------
*/

$hours = max(
    1,
    (int)setting('reminder_hours', '24')
);

$from = date('Y-m-d H:i:s');

$to = date(
    'Y-m-d H:i:s',
    strtotime("+{$hours} hours")
);


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$appointmentsFound = 0;
$patientReminders  = 0;
$doctorReminders   = 0;
$skipped            = 0;
$errors             = 0;


/*
|--------------------------------------------------------------------------
| Find Upcoming Appointments
|--------------------------------------------------------------------------
*/

try {
    $appointmentsCollection = $mongoDb->selectCollection('appointments');
    $patientsCollection = $mongoDb->selectCollection('patients');
    $doctorsCollection = $mongoDb->selectCollection('doctors');
    $usersCollection = $mongoDb->selectCollection('users');

    $appointments = [];

    $fromTimestamp = strtotime($from);
    $toTimestamp = strtotime($to);

    $cursor = $appointmentsCollection->find([
        'status' => ['$in' => ['Requested', 'Confirmed']]
    ], [
        'sort' => [
            'appointment_date' => 1,
            'start_time' => 1
        ]
    ]);

    foreach ($cursor as $doc) {
        $a = (array) $doc;

        $appointmentDate = (string) ($a['appointment_date'] ?? '');
        $startTime = (string) ($a['start_time'] ?? '');

        $appointmentTimestamp = strtotime(
            $appointmentDate . ' ' . $startTime
        );

        if (
            $appointmentTimestamp === false ||
            $appointmentTimestamp < $fromTimestamp ||
            $appointmentTimestamp > $toTimestamp
        ) {
            continue;
        }

        $patient = $patientsCollection->findOne([
            'patient_id' => (int) ($a['patient_id'] ?? 0)
        ]);

        $doctor = $doctorsCollection->findOne([
            'doctor_id' => (int) ($a['doctor_id'] ?? 0)
        ]);

        if (!$patient || !$doctor) {
            continue;
        }

        $a['patient_user'] = (int) ($patient->user_id ?? 0);
        $a['doctor_user'] = (int) ($doctor->user_id ?? 0);

        $appointments[] = $a;
    }

    $appointmentsFound = count($appointments);

} catch (Throwable $e) {
    error_log(
        'Reminder Processor Load Error: ' . $e->getMessage()
    );

    exit("Unable to load upcoming appointments.\n");
}


/*
|--------------------------------------------------------------------------
| Process Appointments
|--------------------------------------------------------------------------
*/

foreach ($appointments as $appointment) {

    $appointment_id = (int)(
        $appointment['appointment_id'] ?? 0
    );

    $appointment_ref = trim(
        (string)(
            $appointment['appointment_ref'] ?? ''
        )
    );

    $appointment_date = trim(
        (string)(
            $appointment['appointment_date'] ?? ''
        )
    );

    $start_time = trim(
        (string)(
            $appointment['start_time'] ?? ''
        )
    );

    $patient_user = (int)(
        $appointment['patient_user'] ?? 0
    );

    $doctor_user = (int)(
        $appointment['doctor_user'] ?? 0
    );


    /*
    |--------------------------------------------------------------------------
    | Validate Record
    |--------------------------------------------------------------------------
    */

    if (
        $appointment_id <= 0 ||
        $appointment_ref === '' ||
        $patient_user <= 0 ||
        $doctor_user <= 0
    ) {

        $skipped++;

        continue;
    }


    try {

        $displayTime = substr(
            $start_time,
            0,
            5
        );

        $notificationsCollection = $mongoDb->selectCollection('notifications');


        /*
        |--------------------------------------------------------------------------
        | Patient Reminder
        |--------------------------------------------------------------------------
        */

        $patientExists = $notificationsCollection->findOne([
            'user_id' => $patient_user,
            'type' => 'reminder',
            'title' => 'Appointment Reminder',
            'message' => [
                '$regex' => preg_quote($appointment_ref, '/')
            ]
        ]);

        if (!$patientExists) {

            create_notification(
                $patient_user,
                'reminder',
                'Appointment Reminder',
                "Reminder: appointment {$appointment_ref} " .
                "is scheduled for {$appointment_date} " .
                "at {$displayTime}."
            );

            $patientReminders++;
        }


        /*
        |--------------------------------------------------------------------------
        | Doctor Reminder
        |--------------------------------------------------------------------------
        */

        $doctorExists = $notificationsCollection->findOne([
            'user_id' => $doctor_user,
            'type' => 'reminder',
            'title' => 'Upcoming Appointment',
            'message' => [
                '$regex' => preg_quote($appointment_ref, '/')
            ]
        ]);

        if (!$doctorExists) {

            create_notification(
                $doctor_user,
                'reminder',
                'Upcoming Appointment',
                "Appointment {$appointment_ref} " .
                "is scheduled for {$appointment_date} " .
                "at {$displayTime}."
            );

            $doctorReminders++;
        }

    } catch (Throwable $e) {

        $errors++;

        error_log(
            'Reminder Processor Error for appointment ' .
            $appointment_id .
            ': ' .
            $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Final CLI Result
|--------------------------------------------------------------------------
*/

$totalCreated =
    $patientReminders +
    $doctorReminders;


echo PHP_EOL;
echo "========================================" . PHP_EOL;
echo "       APPOINTMENT REMINDER RESULT       " . PHP_EOL;
echo "========================================" . PHP_EOL;
echo "Window     : {$hours} hour(s)" . PHP_EOL;
echo "Appointments: {$appointmentsFound}" . PHP_EOL;
echo "Patient    : {$patientReminders}" . PHP_EOL;
echo "Doctor     : {$doctorReminders}" . PHP_EOL;
echo "Total      : {$totalCreated}" . PHP_EOL;
echo "Skipped    : {$skipped}" . PHP_EOL;
echo "Errors     : {$errors}" . PHP_EOL;
echo "========================================" . PHP_EOL;