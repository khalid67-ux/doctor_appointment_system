<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/mongodb.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}


function redirect_by_role(string $role): void
{
    $map = [
        'patient' => 'patient/dashboard.php',
        'doctor'  => 'doctor/dashboard.php',
        'admin'   => 'admin/dashboard.php'
    ];

    header('Location: ' . ($map[$role] ?? 'index.php'));
    exit;
}


function flash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message
    ];
}


function show_flash(): void
{
    if (!empty($_SESSION['flash'])) {

        $f = $_SESSION['flash'];

        echo '<div class="alert alert-' .
            h($f['type']) .
            ' alert-dismissible fade show" role="alert">' .
            h($f['message']) .
            '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' .
            '</div>';

        unset($_SESSION['flash']);
    }
}


function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}


function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        h(csrf_token()) .
        '">';
}


function verify_csrf(): void
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        $token = $_POST['csrf_token'] ?? '';

        if (
            !hash_equals(
                $_SESSION['csrf_token'] ?? '',
                $token
            )
        ) {

            http_response_code(419);

            exit(
                'Invalid form token. Please refresh and try again.'
            );
        }
    }
}


function audit(
    int $userId,
    string $action,
    string $entityType,
    int|string|null $entityId = null
): void {

    global $mongoDb;

    $collection = $mongoDb->selectCollection('audit_logs');

    $lastLog = $collection->findOne(
        [],
        [
            'sort' => ['log_id' => -1],
            'projection' => ['log_id' => 1]
        ]
    );

    $nextLogId = $lastLog !== null
        ? ((int) $lastLog['log_id']) + 1
        : 1;

    $collection->insertOne([
        'log_id' => $nextLogId,
        'user_id' => $userId,
        'action' => $action,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'created_at' => date('Y-m-d H:i:s')
    ]);
}


function create_notification(
    int $userId,
    string $type,
    string $title,
    string $message
): void {
    global $mongoDb;

    $collection = $mongoDb->selectCollection('notifications');

    $last = $collection->findOne(
        [],
        ['sort' => ['notification_id' => -1]]
    );

    $nextId = $last !== null
        ? ((int)$last['notification_id']) + 1
        : 1;

    $collection->insertOne([
        'notification_id' => $nextId,
        'user_id' => $userId,
        'type' => $type,
        'title' => $title,
        'message' => $message,
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}


function get_doctor_id(int $userId): ?int
{
    global $mongoDb;

    $doctor = $mongoDb
        ->selectCollection('doctors')
        ->findOne(
            ['user_id' => $userId],
            ['projection' => ['doctor_id' => 1]]
        );

    return $doctor !== null && isset($doctor['doctor_id'])
        ? (int)$doctor['doctor_id']
        : null;
}


function get_patient_id(int $userId): ?int
{
    global $mongoDb;

    $patient = $mongoDb
        ->selectCollection('patients')
        ->findOne(
            ['user_id' => $userId],
            ['projection' => ['patient_id' => 1]]
        );

    if ($patient === null) {
        return null;
    }

    return isset($patient['patient_id'])
        ? (int) $patient['patient_id']
        : null;
}


function appointment_statuses(): array
{
    return [
        'Requested',
        'Confirmed',
        'Checked-in',
        'In Progress',
        'Completed',
        'Cancelled',
        'No-show'
    ];
}


function setting(
    string $key,
    string $default = ''
): string {
    global $mongoDb;

    $document = $mongoDb
        ->selectCollection('system_settings')
        ->findOne(['setting_key' => $key]);

    return $document !== null && isset($document['setting_value'])
        ? (string)$document['setting_value']
        : $default;
}


function valid_date(string $date): bool
{
    $d = DateTime::createFromFormat(
        'Y-m-d',
        $date
    );

    return $d &&
        $d->format('Y-m-d') === $date;
}


function valid_time(string $time): bool
{
    return (bool)preg_match(
        '/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/',
        $time
    );
}


function doctor_is_available(
    int $doctorId,
    string $date,
    string $time
): bool {

    global $mongoDb, $pdo;

    if (
        !valid_date($date) ||
        !valid_time($time) ||
        $date < date('Y-m-d')
    ) {
        return false;
    }

    $day = strtolower(
        date('l', strtotime($date))
    );

    if (isset($mongoDb)) {
        $schedules = $mongoDb->selectCollection('schedules');
        $leaveDays = $mongoDb->selectCollection('leave_days');
        $breaks = $mongoDb->selectCollection('breaks');
        $appointments = $mongoDb->selectCollection('appointments');

        $schedule = $schedules->findOne([
            'doctor_id' => $doctorId,
            'day_of_week' => $day,
            'start_time' => ['$lte' => $time],
            'end_time' => ['$gt' => $time]
        ]);

        if (!$schedule) {
            return false;
        }

        if ($leaveDays->findOne([
            'doctor_id' => $doctorId,
            'leave_date' => $date
        ])) {
            return false;
        }

        if ($breaks->findOne([
            'doctor_id' => $doctorId,
            '$or' => [
                ['break_date' => null],
                ['break_date' => $date]
            ],
            'start_time' => ['$lte' => $time],
            'end_time' => ['$gt' => $time]
        ])) {
            return false;
        }

        return $appointments->findOne([
            'doctor_id' => $doctorId,
            'appointment_date' => $date,
            'start_time' => $time,
            'status' => ['$nin' => ['Cancelled', 'No-show']]
        ]) === null;
    }


    /*
    |--------------------------------------------------------------------------
    | Check Doctor Schedule
    |--------------------------------------------------------------------------
    */

    $st = $pdo->prepare("
        SELECT schedule_id
        FROM schedules
        WHERE doctor_id = ?
          AND LOWER(day_of_week) = ?
          AND start_time <= ?
          AND end_time > ?
        LIMIT 1
    ");

    $st->execute([
        $doctorId,
        $day,
        $time,
        $time
    ]);

    if (!$st->fetch()) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Check Leave
    |--------------------------------------------------------------------------
    */

    $st = $pdo->prepare("
        SELECT 1
        FROM leave_days
        WHERE doctor_id = ?
          AND leave_date = ?
        LIMIT 1
    ");

    $st->execute([
        $doctorId,
        $date
    ]);

    if ($st->fetch()) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Check Break
    |--------------------------------------------------------------------------
    */

    $st = $pdo->prepare("
        SELECT 1
        FROM breaks
        WHERE doctor_id = ?
          AND (
                break_date IS NULL
                OR break_date = ?
          )
          AND start_time <= ?
          AND end_time > ?
        LIMIT 1
    ");

    $st->execute([
        $doctorId,
        $date,
        $time,
        $time
    ]);

    if ($st->fetch()) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Check Existing Appointment
    |--------------------------------------------------------------------------
    */

    $st = $pdo->prepare("
        SELECT 1
        FROM appointments
        WHERE doctor_id = ?
          AND appointment_date = ?
          AND start_time = ?
          AND status NOT IN (
              'Cancelled',
              'No-show'
          )
        LIMIT 1
    ");

    $st->execute([
        $doctorId,
        $date,
        $time
    ]);

    return !$st->fetch();
}


function generate_slots(
    int $doctorId,
    string $date
): array {

    global $mongoDb;

    if (
        !valid_date($date) ||
        $date < date('Y-m-d')
    ) {
        return [];
    }

    $day = strtolower(
        date('l', strtotime($date))
    );

    $schedules = $mongoDb->selectCollection('schedules');
    $leaveDays = $mongoDb->selectCollection('leave_days');
    $breaks = $mongoDb->selectCollection('breaks');
    $appointments = $mongoDb->selectCollection('appointments');

    if ($leaveDays->findOne([
        'doctor_id' => $doctorId,
        'leave_date' => $date
    ])) {
        return [];
    }

    $scheduleCursor = $schedules->find(
        [
            'doctor_id' => $doctorId,
            'day_of_week' => $day
        ],
        ['sort' => ['start_time' => 1]]
    );

    $slots = [];

    foreach ($scheduleCursor as $scheduleDocument) {
        $schedule = $scheduleDocument->getArrayCopy();
        $duration = max(5, (int) ($schedule['slot_duration'] ?? 0));

        $start = new DateTime(
            "$date {$schedule['start_time']}"
        );

        $end = new DateTime(
            "$date {$schedule['end_time']}"
        );

        while ($start < $end) {
            $slotEnd = (clone $start)
                ->modify("+$duration minutes");

            if ($slotEnd > $end) {
                break;
            }

            $time = $start->format('H:i:s');
            $slotEndTime = $slotEnd->format('H:i:s');

            $hasBreak = $breaks->findOne([
                'doctor_id' => $doctorId,
                '$or' => [
                    ['break_date' => null],
                    ['break_date' => $date]
                ],
                'start_time' => ['$lt' => $slotEndTime],
                'end_time' => ['$gt' => $time]
            ]) !== null;

            $hasBooking = $appointments->findOne([
                'doctor_id' => $doctorId,
                'appointment_date' => $date,
                'start_time' => $time,
                'status' => ['$nin' => ['Cancelled', 'No-show']]
            ]) !== null;

            $slots[] = [
                'time' => $start->format('H:i'),
                'available' => !$hasBreak && !$hasBooking,
                'branch_id' => $schedule['branch_id'] ?? null
            ];

            $start = $slotEnd;
        }
    }

    return $slots;
}


function ensure_patient_profile(int $userId): int
{
    global $pdo;

    $id = get_patient_id($userId);

    if ($id) {
        return $id;
    }

    $pdo->prepare(
        'INSERT INTO patients(user_id)
         VALUES(?)'
    )->execute([$userId]);

    return (int)$pdo->lastInsertId();
}


function ensure_doctor_profile(int $userId): int
{
    global $pdo;

    $id = get_doctor_id($userId);

    if ($id) {
        return $id;
    }

    $pdo->prepare(
        "INSERT INTO doctors
        (user_id, verification_status)
        VALUES (?, 'Pending')"
    )->execute([$userId]);

    return (int)$pdo->lastInsertId();
}


function safe_back(string $fallback): void
{
    header('Location: ' . $fallback);
    exit;
}


/*
|--------------------------------------------------------------------------
| Automatic Waitlist Notification Processing
|--------------------------------------------------------------------------
|
| When an appointment is cancelled, this function finds the oldest
| matching waiting-list request and changes:
|
| Waiting -> Notified
|
| Then the patient receives a notification that the slot is available.
|
|--------------------------------------------------------------------------
*/

function process_waitlist_for_slot(
    int $doctorId,
    string $appointmentDate,
    string $startTime
): bool {

    global $pdo;


    /*
    |--------------------------------------------------------------------------
    | Find Matching Waitlist Request
    |--------------------------------------------------------------------------
    |
    | Priority:
    |
    | 1. Exact requested time
    | 2. Any-time request (preferred_time IS NULL)
    | 3. Older request first
    |
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare("
        SELECT
            w.waitlist_id,
            w.patient_id,
            w.preferred_time,
            p.user_id AS patient_user_id,
            u.name AS patient_name
        FROM waitlists w

        JOIN patients p
            ON p.patient_id = w.patient_id

        JOIN users u
            ON u.user_id = p.user_id

        WHERE w.doctor_id = ?
          AND w.preferred_date = ?
          AND w.status = 'Waiting'

          AND (
                w.preferred_time = ?
                OR w.preferred_time IS NULL
          )

        ORDER BY

            CASE
                WHEN w.preferred_time = ?
                THEN 0
                ELSE 1
            END,

            w.created_at ASC,
            w.waitlist_id ASC

        LIMIT 1

        FOR UPDATE
    ");


    $stmt->execute([
        $doctorId,
        $appointmentDate,
        $startTime,
        $startTime
    ]);


    $waitlist = $stmt->fetch();


    /*
    |--------------------------------------------------------------------------
    | No Matching Waitlist
    |--------------------------------------------------------------------------
    */

    if (!$waitlist) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Change Waiting -> Notified
    |--------------------------------------------------------------------------
    */

    $update = $pdo->prepare("
        UPDATE waitlists
        SET status = 'Notified'
        WHERE waitlist_id = ?
          AND status = 'Waiting'
    ");

    $update->execute([
        (int)$waitlist['waitlist_id']
    ]);


    /*
    |--------------------------------------------------------------------------
    | Make Sure Update Happened
    |--------------------------------------------------------------------------
    */

    if ($update->rowCount() !== 1) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Prepare Notification
    |--------------------------------------------------------------------------
    */

    $formattedDate = date(
        'd M Y',
        strtotime($appointmentDate)
    );

    $formattedTime = date(
        'h:i A',
        strtotime($startTime)
    );


    $message =
        'A slot is now available for your requested appointment on ' .
        $formattedDate .
        ' at ' .
        $formattedTime .
        '. Please book the appointment as soon as possible.';


    /*
    |--------------------------------------------------------------------------
    | Create Patient Notification
    |--------------------------------------------------------------------------
    */

    create_notification(
        (int)$waitlist['patient_user_id'],
        'waitlist',
        'Appointment Slot Available',
        $message
    );


    return true;
}

function generate_appointment_token(
    int $doctorId,
    string $appointmentDate
): int {

    global $mongoDb;

    $appointment = $mongoDb->selectCollection('appointments')->findOne(
        [
            'doctor_id' => $doctorId,
            'appointment_date' => $appointmentDate,
            'token_no' => ['$ne' => null],
            'status' => ['$nin' => ['Cancelled', 'No-show']]
        ],
        [
            'sort' => ['token_no' => -1],
            'projection' => ['token_no' => 1]
        ]
    );

    return $appointment !== null
        ? ((int) $appointment['token_no']) + 1
        : 1;
}

function create_appointment_reminder(
    int $appointmentId
): bool {

    global $pdo;

    $stmt = $pdo->prepare("
        SELECT
            a.appointment_id,
            a.appointment_ref,
            a.appointment_date,
            a.start_time,
            a.status,

            p.user_id AS patient_user_id,

            d.user_id AS doctor_user_id,

            u.name AS doctor_name

        FROM appointments a

        JOIN patients p
            ON p.patient_id = a.patient_id

        JOIN doctors d
            ON d.doctor_id = a.doctor_id

        JOIN users u
            ON u.user_id = d.user_id

        WHERE a.appointment_id = ?

        LIMIT 1
    ");

    $stmt->execute([
        $appointmentId
    ]);

    $appointment = $stmt->fetch();

    if (!$appointment) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Only Active Appointments
    |--------------------------------------------------------------------------
    */

    if (
        !in_array(
            $appointment['status'],
            ['Requested', 'Confirmed', 'Checked-in'],
            true
        )
    ) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Appointment Date & Time
    |--------------------------------------------------------------------------
    */

    $appointmentDateTime = strtotime(
        $appointment['appointment_date'] .
        ' ' .
        $appointment['start_time']
    );

    if (!$appointmentDateTime) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Reminder Time
    |--------------------------------------------------------------------------
    */

    $reminderTime = $appointmentDateTime - (24 * 3600);

    /*
    |--------------------------------------------------------------------------
    | Do Not Create Reminder For Past Reminder Time
    |--------------------------------------------------------------------------
    */

    if ($reminderTime <= time()) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Prevent Duplicate Reminder
    |--------------------------------------------------------------------------
    */

    $check = $pdo->prepare("
        SELECT notification_id

        FROM notifications

        WHERE user_id = ?

          AND type = 'reminder'

          AND title = 'Appointment Reminder'

          AND message LIKE ?

        LIMIT 1
    ");

    $check->execute([
        (int)$appointment['patient_user_id'],
        '%' . $appointment['appointment_ref'] . '%'
    ]);

    if ($check->fetch()) {
        return false;
    }


    /*
    |--------------------------------------------------------------------------
    | Reminder Message
    |--------------------------------------------------------------------------
    */

    $formattedDate = date(
        'd M Y',
        $appointmentDateTime
    );

    $formattedTime = date(
        'h:i A',
        $appointmentDateTime
    );

    $message =
        'Reminder: Your appointment ' .
        $appointment['appointment_ref'] .
        ' with Dr. ' .
        $appointment['doctor_name'] .
        ' is scheduled for ' .
        $formattedDate .
        ' at ' .
        $formattedTime .
        '.';


    /*
    |--------------------------------------------------------------------------
    | Create Patient Notification
    |--------------------------------------------------------------------------
    */

    create_notification(
        (int)$appointment['patient_user_id'],
        'reminder',
        'Appointment Reminder',
        $message
    );


    return true;
}

?>