<?php

$page_title = 'Patient Queue';
$asset_prefix = '../';

require '../includes/header.php';
require_role('doctor');

$doctor_id = get_doctor_id((int)$_SESSION['user_id']);

if (!$doctor_id) {
    flash('danger', 'Doctor profile not found.');
    header('Location: dashboard.php');
    exit;
}

verify_csrf();

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$patientsCollection = $mongoDb->selectCollection('patients');
$usersCollection = $mongoDb->selectCollection('users');


/*
|--------------------------------------------------------------------------
| Queue Status Update
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'queue_status'
) {

    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');

    $allowed_queue_statuses = [
        'Checked-in',
        'In Progress',
        'Completed',
        'No-show'
    ];

    if (
        $appointment_id <= 0 ||
        !in_array($new_status, $allowed_queue_statuses, true)
    ) {

        flash('danger', 'Invalid queue update.');
        header('Location: queue.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Get Appointment
    |--------------------------------------------------------------------------
    */

    $appointmentDocument = $appointmentsCollection->findOne([
        'appointment_id' => (int) $appointment_id,
        'doctor_id' => (int) $doctor_id,
    ]);

    $appointment = null;

    if ($appointmentDocument !== null) {
        $appointment = $appointmentDocument->getArrayCopy();

        $patientDocument = $patientsCollection->findOne([
            'patient_id' => (int) ($appointment['patient_id'] ?? 0),
        ]);

        if ($patientDocument !== null) {
            $patient = $patientDocument->getArrayCopy();

            $patientUser = $usersCollection->findOne([
                'user_id' => (int) ($patient['user_id'] ?? 0),
            ]);

            $appointment['patient_user_id'] =
                (int) ($patient['user_id'] ?? 0);

            $appointment['patient_name'] =
                $patientUser['name'] ?? 'Patient';
        }
    }

    if (!$appointment) {

        flash('danger', 'Appointment not found.');
        header('Location: queue.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Queue Transition Rules
    |--------------------------------------------------------------------------
    */

    $queue_transitions = [

        'Confirmed' => [
            'Checked-in',
            'No-show'
        ],

        'Checked-in' => [
            'In Progress',
            'No-show'
        ],

        'In Progress' => [
            'Completed'
        ]

    ];


    /*
    |--------------------------------------------------------------------------
    | Update Queue Status
    |--------------------------------------------------------------------------
    */

    try {
        $appointmentsCollection = $mongoDb->selectCollection('appointments');

        $lockedDocument = $appointmentsCollection->findOne([
            'appointment_id' => (int) $appointment_id,
            'doctor_id' => (int) $doctor_id,
        ]);

        if ($lockedDocument === null) {
            throw new RuntimeException(
                'Appointment is no longer available.'
            );
        }

        $locked = $lockedDocument->getArrayCopy();
        $old_status = $locked['status'] ?? '';

        if (
            !isset($queue_transitions[$old_status]) ||
            !in_array(
                $new_status,
                $queue_transitions[$old_status],
                true
            )
        ) {
            throw new RuntimeException(
                "Cannot change status from {$old_status} to {$new_status}."
            );
        }

        $updateResult = $appointmentsCollection->updateOne(
            [
                'appointment_id' => (int) $appointment_id,
                'doctor_id' => (int) $doctor_id,
                'status' => $old_status,
            ],
            [
                '$set' => [
                    'status' => $new_status,
                ],
            ]
        );

        if ($updateResult->getModifiedCount() !== 1) {
            throw new RuntimeException(
                'Appointment status changed. Please try again.'
            );
        }


        /*
        |--------------------------------------------------------------------------
        | Patient Notification
        |--------------------------------------------------------------------------
        */

        $patientUserId = (int) ($locked['patient_user_id']
            ?? $appointment['patient_user_id']
            ?? 0);

        if ($patientUserId <= 0) {
            $patientDocument = $patientsCollection->findOne([
                'patient_id' => (int) ($locked['patient_id'] ?? 0),
            ]);

            $patientUserId = (int) ($patientDocument['user_id'] ?? 0);
        }

        if ($patientUserId <= 0) {
            throw new RuntimeException(
                'Patient account is unavailable for notification.'
            );
        }

        $notificationDate = $locked['appointment_date'] ?? '';
        $notificationTime = $locked['start_time'] ?? '';
        $notification_message =
            'Your appointment on ' .
            date('d M Y', strtotime((string) $notificationDate)) .
            ' at ' .
            date('h:i A', strtotime((string) $notificationTime)) .
            ' is now "' . $new_status . '".';


        create_notification(
            $patientUserId,
            'queue',
            'Queue Status Updated',
            $notification_message
        );


        /*
        |--------------------------------------------------------------------------
        | Audit Log
        |--------------------------------------------------------------------------
        */

        audit(
            (int) $_SESSION['user_id'],
            "Queue status changed from {$old_status} to {$new_status}",
            'appointment',
            $appointment_id
        );

        flash(
            'success',
            'Queue status updated successfully.'
        );

    } catch (Throwable $e) {
        error_log('Queue status update error: ' . $e->getMessage());
        flash(
            'danger',
            'Unable to update queue status.'
        );
    }


    header('Location: queue.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Today's Queue
|--------------------------------------------------------------------------
*/

$today = date('Y-m-d');

$queue = [];

$queueCursor = $appointmentsCollection->find(
    [
        'doctor_id' => (int) $doctor_id,
        'appointment_date' => $today,
        'status' => [
            '$nin' => ['Cancelled', 'No-show'],
        ],
    ],
    [
        'sort' => [
            'token_no' => 1,
            'start_time' => 1,
        ],
    ]
);

foreach ($queueCursor as $appointmentDocument) {
    $q = $appointmentDocument->getArrayCopy();

    $patientDocument = $patientsCollection->findOne([
        'patient_id' => (int) ($q['patient_id'] ?? 0),
    ]);

    $patient = $patientDocument !== null
        ? $patientDocument->getArrayCopy()
        : [];

    $userDocument = $usersCollection->findOne([
        'user_id' => (int) ($patient['user_id'] ?? 0),
    ]);

    $user = $userDocument !== null
        ? $userDocument->getArrayCopy()
        : [];

    $q['patient_name'] = $user['name'] ?? 'Patient';
    $q['patient_email'] = $user['email'] ?? '';
    $q['patient_contact'] = $patient['contact'] ?? '';

    $queue[] = $q;
}

usort($queue, function ($a, $b) {
    $tokenA = $a['token_no'] ?? null;
    $tokenB = $b['token_no'] ?? null;

    $missingA = $tokenA === null || $tokenA === '';
    $missingB = $tokenB === null || $tokenB === '';

    if ($missingA !== $missingB) {
        return $missingA ? 1 : -1;
    }

    if (!$missingA && (float) $tokenA !== (float) $tokenB) {
        return (float) $tokenA <=> (float) $tokenB;
    }

    return strcmp(
        (string) ($a['start_time'] ?? ''),
        (string) ($b['start_time'] ?? '')
    );
});


/*
|--------------------------------------------------------------------------
| Queue Statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'confirmed' => 0,
    'checked_in' => 0,
    'in_progress' => 0,
    'completed' => 0,
];

$statsCursor = $appointmentsCollection->find([
    'doctor_id' => (int) $doctor_id,
    'appointment_date' => $today,
    'status' => [
        '$nin' => ['Cancelled', 'No-show'],
    ],
]);

foreach ($statsCursor as $appointmentDocument) {
    $status = $appointmentDocument['status'] ?? '';

    $stats['total']++;

    switch ($status) {
        case 'Confirmed':
            $stats['confirmed']++;
            break;

        case 'Checked-in':
            $stats['checked_in']++;
            break;

        case 'In Progress':
            $stats['in_progress']++;
            break;

        case 'Completed':
            $stats['completed']++;
            break;
    }
}


/*
|--------------------------------------------------------------------------
| Find Current Patient
|--------------------------------------------------------------------------
*/

$current_patient = null;

foreach ($queue as $q) {

    if ($q['status'] === 'In Progress') {

        $current_patient = $q;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| Find Next Patient
|--------------------------------------------------------------------------
*/

$next_patient = null;

foreach ($queue as $q) {

    if (
        in_array(
            $q['status'],
            ['Confirmed', 'Checked-in'],
            true
        )
    ) {

        $next_patient = $q;
        break;
    }
}

?>

<div class="container-fluid py-4">


    <!-- Header -->

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <span class="badge rounded-pill bg-primary-subtle text-primary mb-2">
                Doctor Portal
            </span>

            <h2 class="fw-bold mb-1">
                Patient Queue
            </h2>

            <p class="text-secondary mb-0">
                Manage today's patient queue and consultation flow.
            </p>

        </div>


        <a
            href="dashboard.php"
            class="btn btn-outline-primary"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Dashboard
        </a>

    </div>


    <!-- Queue Summary -->

    <div class="row g-3 mb-4">

        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        Today's Queue
                    </div>

                    <div class="fs-2 fw-bold">
                        <?= (int)$stats['total'] ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        Confirmed
                    </div>

                    <div class="fs-2 fw-bold text-primary">
                        <?= (int)$stats['confirmed'] ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        Checked-in
                    </div>

                    <div class="fs-2 fw-bold text-info">
                        <?= (int)$stats['checked_in'] ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        In Progress
                    </div>

                    <div class="fs-2 fw-bold text-success">
                        <?= (int)$stats['in_progress'] ?>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Current / Next -->

    <div class="row g-4 mb-4">


        <!-- Current Patient -->

        <div class="col-lg-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        Current Patient
                    </div>


                    <?php if ($current_patient): ?>

                        <div class="d-flex align-items-center gap-3">

                            <div
                                class="rounded-circle bg-success-subtle text-success d-flex align-items-center justify-content-center"
                                style="width:52px;height:52px;"
                            >
                                <i class="bi bi-person-check fs-4"></i>
                            </div>


                            <div>

                                <div class="fw-bold">
                                    <?= h($current_patient['patient_name']) ?>
                                </div>

                                <div class="text-secondary small">

                                    Token #

                                    <?= h(
                                        $current_patient['token_no']
                                    ) ?>

                                    ·

                                    <?= date(
                                        'h:i A',
                                        strtotime(
                                            $current_patient['start_time']
                                        )
                                    ) ?>

                                </div>

                            </div>

                        </div>


                        <div class="mt-3">

                            <span class="badge text-bg-success">
                                In Progress
                            </span>

                        </div>

                    <?php else: ?>

                        <div class="text-secondary py-3">
                            <i class="bi bi-person-dash me-2"></i>
                            No patient is currently in consultation.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>


        <!-- Next Patient -->

        <div class="col-lg-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        Next Patient
                    </div>


                    <?php if ($next_patient): ?>

                        <div class="d-flex align-items-center gap-3">

                            <div
                                class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                                style="width:52px;height:52px;"
                            >
                                <i class="bi bi-person fs-4"></i>
                            </div>


                            <div>

                                <div class="fw-bold">
                                    <?= h($next_patient['patient_name']) ?>
                                </div>

                                <div class="text-secondary small">

                                    Token #

                                    <?= h(
                                        $next_patient['token_no']
                                    ) ?>

                                    ·

                                    <?= date(
                                        'h:i A',
                                        strtotime(
                                            $next_patient['start_time']
                                        )
                                    ) ?>

                                </div>

                            </div>

                        </div>


                        <div class="mt-3">

                            <span class="badge text-bg-primary">
                                <?= h($next_patient['status']) ?>
                            </span>

                        </div>

                    <?php else: ?>

                        <div class="text-secondary py-3">
                            <i class="bi bi-check-circle me-2"></i>
                            No more patients in today's queue.
                        </div>

                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>


    <!-- Queue Table -->

    <div class="card border-0 shadow-sm">

        <div class="card-body p-0">


            <div class="p-4 border-bottom">

                <h5 class="fw-bold mb-1">
                    Today's Queue
                </h5>

                <p class="text-secondary mb-0">
                    <?= count($queue) ?> patient(s) in today's queue.
                </p>

            </div>


            <?php if (!$queue): ?>

                <div class="text-center py-5 px-3">

                    <div class="display-5 text-secondary mb-3">
                        <i class="bi bi-people"></i>
                    </div>

                    <h5 class="fw-bold">
                        Queue is empty
                    </h5>

                    <p class="text-secondary mb-0">
                        No active appointments are scheduled for today.
                    </p>

                </div>

            <?php else: ?>


                <div class="table-responsive">

                    <table class="table align-middle mb-0">

                        <thead class="table-light">

                            <tr>

                                <th class="px-4">
                                    Token
                                </th>

                                <th>
                                    Patient
                                </th>

                                <th>
                                    Appointment
                                </th>

                                <th>
                                    Status
                                </th>

                                <th class="text-end px-4">
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($queue as $q): ?>

                            <?php

                            $queue_status_class = 'secondary';

                            switch ($q['status']) {

                                case 'Confirmed':
                                    $queue_status_class = 'primary';
                                    break;

                                case 'Checked-in':
                                    $queue_status_class = 'info';
                                    break;

                                case 'In Progress':
                                    $queue_status_class = 'success';
                                    break;

                                case 'Completed':
                                    $queue_status_class = 'dark';
                                    break;
                            }

                            ?>


                            <tr>


                                <!-- Token -->

                                <td class="px-4">

                                    <?php if (
                                        $q['token_no'] !== null &&
                                        $q['token_no'] !== ''
                                    ): ?>

                                        <span
                                            class="badge bg-dark fs-6"
                                        >
                                            #<?= h($q['token_no']) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="text-secondary">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- Patient -->

                                <td>

                                    <div class="fw-semibold">
                                        <?= h($q['patient_name']) ?>
                                    </div>

                                    <small class="text-secondary">
                                        <?= h($q['patient_contact'] ?: 'No contact') ?>
                                    </small>

                                </td>


                                <!-- Appointment -->

                                <td>

                                    <div class="fw-semibold">

                                        <?= date(
                                            'h:i A',
                                            strtotime($q['start_time'])
                                        ) ?>

                                    </div>

                                    <small class="text-secondary">
                                        <?= h($q['appointment_ref']) ?>
                                    </small>

                                </td>


                                <!-- Status -->

                                <td>

                                    <span
                                        class="badge text-bg-<?= $queue_status_class ?>"
                                    >
                                        <?= h($q['status']) ?>
                                    </span>

                                </td>


                                <!-- Action -->

                                <td class="text-end px-4">


                                    <?php if (
                                        $q['status'] === 'Confirmed'
                                    ): ?>

                                        <form
                                            method="post"
                                            class="d-inline"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="queue_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="appointment_id"
                                                value="<?= (int)$q['appointment_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="status"
                                                value="Checked-in"
                                            >

                                            <button
                                                class="btn btn-sm btn-outline-info"
                                            >
                                                Check-in
                                            </button>

                                        </form>


                                        <form
                                            method="post"
                                            class="d-inline"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="queue_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="appointment_id"
                                                value="<?= (int)$q['appointment_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="status"
                                                value="No-show"
                                            >

                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                data-confirm="Mark this patient as no-show?"
                                            >
                                                No-show
                                            </button>

                                        </form>


                                    <?php elseif (
                                        $q['status'] === 'Checked-in'
                                    ): ?>

                                        <form
                                            method="post"
                                            class="d-inline"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="queue_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="appointment_id"
                                                value="<?= (int)$q['appointment_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="status"
                                                value="In Progress"
                                            >

                                            <button
                                                class="btn btn-sm btn-primary"
                                            >
                                                Start Consultation
                                            </button>

                                        </form>


                                        <form
                                            method="post"
                                            class="d-inline"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="queue_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="appointment_id"
                                                value="<?= (int)$q['appointment_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="status"
                                                value="No-show"
                                            >

                                            <button
                                                class="btn btn-sm btn-outline-secondary"
                                                data-confirm="Mark this patient as no-show?"
                                            >
                                                No-show
                                            </button>

                                        </form>


                                    <?php elseif (
                                        $q['status'] === 'In Progress'
                                    ): ?>

                                        <form
                                            method="post"
                                            class="d-inline"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="queue_status"
                                            >

                                            <input
                                                type="hidden"
                                                name="appointment_id"
                                                value="<?= (int)$q['appointment_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="status"
                                                value="Completed"
                                            >

                                            <button
                                                class="btn btn-sm btn-success"
                                            >
                                                Complete
                                            </button>

                                        </form>


                                    <?php else: ?>

                                        <span class="text-secondary">
                                            Completed
                                        </span>

                                    <?php endif; ?>


                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


        </div>

    </div>

</div>


<?php require '../includes/footer.php'; ?>