<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';


/*
|--------------------------------------------------------------------------
| JSON RESPONSE
|--------------------------------------------------------------------------
*/

function json_response(
    bool $success,
    array $data = [],
    string $message = '',
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
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| MAIN
|--------------------------------------------------------------------------
*/

try {

    /*
    |--------------------------------------------------------------------------
    | REQUEST PARAMETERS
    |--------------------------------------------------------------------------
    */

    $doctor_id = filter_input(
        INPUT_GET,
        'doctor_id',
        FILTER_VALIDATE_INT
    );

    $branch_id = filter_input(
        INPUT_GET,
        'branch_id',
        FILTER_VALIDATE_INT
    );

    $date = trim(
        $_GET['date'] ?? date('Y-m-d')
    );


    $doctor_id = $doctor_id ?: 0;
    $branch_id = $branch_id ?: 0;


    /*
    |--------------------------------------------------------------------------
    | VALIDATE DOCTOR
    |--------------------------------------------------------------------------
    */

    if ($doctor_id <= 0) {

        json_response(
            false,
            [
                'slots' => [],
                'count' => 0
            ],
            'Invalid doctor.',
            400
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VALIDATE DATE
    |--------------------------------------------------------------------------
    */

    if (!valid_date($date)) {

        json_response(
            false,
            [
                'slots' => [],
                'count' => 0
            ],
            'Invalid appointment date.',
            400
        );
    }


    /*
    |--------------------------------------------------------------------------
    | PREVENT PAST DATE
    |--------------------------------------------------------------------------
    */

    $today = date('Y-m-d');

    if ($date < $today) {

        json_response(
            true,
            [
                'doctor_id' => $doctor_id,
                'branch_id' => $branch_id,
                'date'      => $date,
                'count'     => 0,
                'slots'     => []
            ],
            'No slots are available for a past date.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY DOCTOR
    |--------------------------------------------------------------------------
    */

    $doctorsCollection = $mongoDb->selectCollection('doctors');
    $usersCollection = $mongoDb->selectCollection('users');

    $doctor = $doctorsCollection->findOne([
        'doctor_id' => $doctor_id,
        'verification_status' => 'Approved'
    ]);

    if ($doctor) {
        $doctorUser = $usersCollection->findOne([
            'user_id' => (int) ($doctor->user_id ?? 0),
            'status' => 'active'
        ]);

        if (!$doctorUser) {
            $doctor = null;
        }
    }


    if (!$doctor) {

        json_response(
            false,
            [
                'slots' => [],
                'count' => 0
            ],
            'Doctor is not available.',
            404
        );
    }


    /*
    |--------------------------------------------------------------------------
    | VERIFY BRANCH
    |--------------------------------------------------------------------------
    |
    | A selected branch must belong to the selected doctor.
    |
    */

    if ($branch_id > 0) {

        $doctorBranchesCollection = $mongoDb->selectCollection('doctor_branches');
        $branchesCollection = $mongoDb->selectCollection('branches');
        $clinicsCollection = $mongoDb->selectCollection('clinics');

        $assignment = $doctorBranchesCollection->findOne([
            'doctor_id' => $doctor_id,
            'branch_id' => $branch_id
        ]);

        $branch = $branchesCollection->findOne([
            'branch_id' => $branch_id
        ]);

        $clinic = $branch
            ? $clinicsCollection->findOne([
                'clinic_id' => (int) ($branch->clinic_id ?? 0)
            ])
            : null;

        if (!$assignment || !$branch || !$clinic) {

            json_response(
                false,
                [
                    'slots' => [],
                    'count' => 0
                ],
                'Invalid clinic branch.',
                400
            );
        }
    }


    /*
    |--------------------------------------------------------------------------
    | GENERATE SLOTS
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | Do NOT hardcode any day/time here.
    |
    | generate_slots() reads the doctor's actual schedules
    | from the database.
    |
    | It handles:
    |
    | - day of week
    | - start time
    | - end time
    | - slot duration
    | - branch
    | - breaks
    | - leave days
    | - existing appointments
    |
    */

    $slots = generate_slots(
        $doctor_id,
        $date
    );


    if (!is_array($slots)) {
        $slots = [];
    }


    /*
    |--------------------------------------------------------------------------
    | FILTER BY SELECTED BRANCH
    |--------------------------------------------------------------------------
    */

    if ($branch_id > 0) {

        $slots = array_values(
            array_filter(
                $slots,
                static function ($slot) use ($branch_id): bool {

                    if (!is_array($slot)) {
                        return false;
                    }

                    return (int)(
                        $slot['branch_id'] ?? 0
                    ) === $branch_id;
                }
            )
        );
    }


    /*
    |--------------------------------------------------------------------------
    | CLEAN + NORMALIZE SLOTS
    |--------------------------------------------------------------------------
    */

    $cleanSlots = [];

    foreach ($slots as $slot) {

        if (!is_array($slot)) {
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Current generate_slots() returns:
        |
        | time
        | available
        | branch_id
        |--------------------------------------------------------------------------
        */

        $time = trim(
            (string)(
                $slot['time'] ?? ''
            )
        );


        if ($time === '') {
            continue;
        }


        /*
        |--------------------------------------------------------------------------
        | Normalize time
        |--------------------------------------------------------------------------
        |
        | Example:
        |
        | 10:00:00 → 10:00
        |
        */

        $time = substr(
            $time,
            0,
            5
        );


        /*
        |--------------------------------------------------------------------------
        | Branch
        |--------------------------------------------------------------------------
        */

        $slotBranchId = (int)(
            $slot['branch_id'] ?? 0
        );


        /*
        |--------------------------------------------------------------------------
        | Availability
        |--------------------------------------------------------------------------
        */

        $available = isset(
            $slot['available']
        )
            ? (bool)$slot['available']
            : true;

        // Automatically lock past slots for today's date.
        $today = date('Y-m-d');

        if ($date === $today) {
            $currentTime = date('H:i');

            if ($time <= $currentTime) {
                $available = false;
            }
        }


        /*
        |--------------------------------------------------------------------------
        | UNIQUE SLOT KEY
        |--------------------------------------------------------------------------
        |
        | Prevent duplicate slots if multiple schedules overlap.
        |
        */

        $uniqueKey =
            $slotBranchId . '|' . $time;


        if (isset($cleanSlots[$uniqueKey])) {
            continue;
        }


        $cleanSlots[$uniqueKey] = [
            'time' => $time,

            'start_time' =>
                $time . ':00',

            'end_time' => '',

            'branch_id' =>
                $slotBranchId,

            'available' =>
                $available
        ];
    }


    /*
    |--------------------------------------------------------------------------
    | RESET ARRAY INDEXES
    |--------------------------------------------------------------------------
    */

    $cleanSlots = array_values(
        $cleanSlots
    );


    /*
    |--------------------------------------------------------------------------
    | SORT BY TIME
    |--------------------------------------------------------------------------
    |
    | This guarantees that future schedules are shown
    | chronologically even when multiple schedules exist.
    |
    */

    usort(
        $cleanSlots,
        static function (
            array $a,
            array $b
        ): int {

            return strcmp(
                $a['time'],
                $b['time']
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | FINAL RESPONSE
    |--------------------------------------------------------------------------
    */

    json_response(
        true,
        [
            'doctor_id' =>
                $doctor_id,

            'branch_id' =>
                $branch_id,

            'date' =>
                $date,

            'count' =>
                count($cleanSlots),

            'slots' =>
                $cleanSlots
        ],
        count($cleanSlots) > 0
            ? 'Available slots loaded successfully.'
            : 'No available slots for the selected date.'
    );


} catch (Throwable $e) {

    /*
    |--------------------------------------------------------------------------
    | LOG SERVER ERROR
    |--------------------------------------------------------------------------
    */

    error_log(
        'Slots API Error: ' .
        $e->getMessage()
    );


    /*
    |--------------------------------------------------------------------------
    | SAFE CLIENT RESPONSE
    |--------------------------------------------------------------------------
    */

    json_response(
        false,
        [
            'slots' => [],
            'count' => 0
        ],
        'Unable to load appointment slots right now.',
        500
    );
}