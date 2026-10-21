<?php

$page_title = 'Manage Appointments';
$asset_prefix = '../';

require_once '../config/mongodb.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

require_role('doctor');

$valid_transitions = [
    'Requested'   => ['Confirmed', 'Cancelled'],
    'Confirmed'   => ['Completed', 'Cancelled'],
    'Completed'   => [],
    'Cancelled'   => [],
    'Checked-in'  => ['Completed', 'Cancelled'],
    'In Progress' => ['Completed', 'Cancelled'],
    'No-show'     => [],
];

$doctor_id = get_doctor_id((int)$_SESSION['user_id']);

if (!$doctor_id) {
    flash('danger', 'Doctor profile not found.');
    header('Location: dashboard.php');
    exit;
}

verify_csrf();


/*
|--------------------------------------------------------------------------
| Status Update
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'update_status'
) {

    $appointment_id = (int)($_POST['appointment_id'] ?? 0);
    $new_status = trim($_POST['status'] ?? '');

    $appointmentsCollection = $mongoDb->selectCollection('appointments');

    $appointment = $appointmentsCollection->findOne([
        'appointment_id' => $appointment_id,
        'doctor_id' => (int) $doctor_id
    ]);

    if ($appointment === null) {
        flash('danger', 'Appointment not found.');
        header('Location: appointments.php');
        exit;
    }

    $allowed_statuses = appointment_statuses();

    if (
        $appointment_id <= 0 ||
        !in_array($new_status, $allowed_statuses, true)
    ) {

        flash('danger', 'Invalid appointment or status.');
        header('Location: appointments.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Update Appointment - MongoDB
    |--------------------------------------------------------------------------
    */

    try {
        $old_status = $appointment['status'];

        if ($old_status === $new_status) {
            flash('info', 'Appointment is already marked as ' . $new_status . '.');
            header('Location: appointments.php');
            exit;
        }

        if (!in_array($new_status, $valid_transitions[$old_status] ?? [], true)) {
            flash('warning', "Cannot change status from {$old_status} to {$new_status}.");
            header('Location: appointments.php');
            exit;
        }

        $token_no = $appointment['token_no'] ?? null;

        if (
            $new_status === 'Confirmed' &&
            $old_status === 'Requested' &&
            ($token_no === null || $token_no === '')
        ) {
            $token_no = generate_appointment_token(
                $doctor_id,
                $appointment['appointment_date']
            );
        }

        $updateData = ['status' => $new_status];

        if ($new_status === 'Confirmed' && $old_status === 'Requested') {
            $updateData['token_no'] = $token_no;
        }

        $result = $appointmentsCollection->updateOne(
            [
                'appointment_id' => $appointment_id,
                'doctor_id' => (int) $doctor_id,
                'status' => $old_status
            ],
            ['$set' => $updateData]
        );

        if ($result->getModifiedCount() !== 1) {
            throw new RuntimeException('Appointment status could not be updated.');
        }

        audit(
            (int) $_SESSION['user_id'],
            "Appointment status changed from {$old_status} to {$new_status}",
            'appointment',
            $appointment_id
        );

        flash(
            'success',
            $new_status === 'Confirmed' && $old_status === 'Requested'
                ? "Appointment confirmed successfully. Token #{$token_no} assigned."
                : 'Appointment status updated successfully.'
        );

    } catch (Throwable $e) {
        error_log('Appointment status update failed: ' . $e->getMessage());
        flash('danger', 'Unable to update appointment status.');
    }

    header('Location: appointments.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$filter = $_GET['filter'] ?? 'all';

$allowed_filters = [
    'all',
    'today',
    'upcoming',
    'past'
];

if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'all';
}


/*
|--------------------------------------------------------------------------
| Load Appointments - MongoDB
|--------------------------------------------------------------------------
*/

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$patientsCollection = $mongoDb->selectCollection('patients');
$usersCollection = $mongoDb->selectCollection('users');

$todayDate = date('Y-m-d');
$currentTime = date('H:i:s');

$appointmentFilter = [
    'doctor_id' => (int) $doctor_id
];

if ($filter === 'today') {
    $appointmentFilter['appointment_date'] = $todayDate;
} elseif ($filter === 'upcoming') {
    $appointmentFilter['$or'] = [
        [
            'appointment_date' => ['$gt' => $todayDate]
        ],
        [
            'appointment_date' => $todayDate,
            'start_time' => ['$gte' => $currentTime]
        ]
    ];
} elseif ($filter === 'past') {
    $appointmentFilter['$or'] = [
        [
            'appointment_date' => ['$lt' => $todayDate]
        ],
        [
            'appointment_date' => $todayDate,
            'start_time' => ['$lt' => $currentTime]
        ]
    ];
}

$appointments = [];

$appointmentCursor = $appointmentsCollection->find(
    $appointmentFilter,
    [
        'sort' => [
            'appointment_date' => -1,
            'start_time' => 1
        ]
    ]
);

foreach ($appointmentCursor as $appointment) {
    $a = $appointment->getArrayCopy();

    $patient = $patientsCollection->findOne([
        'patient_id' => (int) ($a['patient_id'] ?? 0)
    ]);

    if ($patient === null) {
        continue;
    }

    $patientData = $patient->getArrayCopy();

    $user = $usersCollection->findOne([
        'user_id' => (int) ($patientData['user_id'] ?? 0)
    ]);

    $userData = $user !== null
        ? $user->getArrayCopy()
        : [];

    $appointments[] = array_merge($a, [
        'patient_name' => $userData['name'] ?? 'Unknown Patient',
        'patient_email' => $userData['email'] ?? '',
        'patient_contact' => $patientData['contact'] ?? ''
    ]);
}


/*
|--------------------------------------------------------------------------
| Statistics - MongoDB
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'today' => 0,
    'requested' => 0,
    'confirmed' => 0,
    'completed' => 0
];

$todayDate = date('Y-m-d');

$statisticsCursor = $mongoDb
    ->selectCollection('appointments')
    ->find([
        'doctor_id' => (int) $doctor_id
    ]);

foreach ($statisticsCursor as $appointment) {
    $stats['total']++;

    $appointmentData = $appointment->getArrayCopy();

    if (($appointmentData['appointment_date'] ?? '') === $todayDate) {
        $stats['today']++;
    }

    $status = $appointmentData['status'] ?? '';

    if ($status === 'Requested') {
        $stats['requested']++;
    } elseif ($status === 'Confirmed') {
        $stats['confirmed']++;
    } elseif ($status === 'Completed') {
        $stats['completed']++;
    }
}

require '../includes/header.php';
?>

<div class="container-fluid py-4">


    <!-- Page Header -->

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <span class="badge rounded-pill bg-primary-subtle text-primary mb-2">
                Doctor Portal
            </span>

            <h2 class="fw-bold mb-1">
                Appointment Management
            </h2>

            <p class="text-secondary mb-0">
                View and manage your patient appointments.
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


    <!-- Statistics -->

    <div class="row g-3 mb-4">

        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="text-secondary small mb-2">
                        Total Appointments
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

                    <div class="text-secondary small mb-2">
                        Today's Appointments
                    </div>

                    <div class="fs-2 fw-bold text-primary">
                        <?= (int)$stats['today'] ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="text-secondary small mb-2">
                        Requested
                    </div>

                    <div class="fs-2 fw-bold text-warning">
                        <?= (int)$stats['requested'] ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="text-secondary small mb-2">
                        Completed
                    </div>

                    <div class="fs-2 fw-bold text-success">
                        <?= (int)$stats['completed'] ?>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Filters -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body p-3">

            <div class="d-flex flex-wrap gap-2">

                <a
                    href="appointments.php?filter=all"
                    class="btn <?= $filter === 'all'
                        ? 'btn-primary'
                        : 'btn-outline-primary' ?>"
                >
                    All
                </a>


                <a
                    href="appointments.php?filter=today"
                    class="btn <?= $filter === 'today'
                        ? 'btn-primary'
                        : 'btn-outline-primary' ?>"
                >
                    Today
                </a>


                <a
                    href="appointments.php?filter=upcoming"
                    class="btn <?= $filter === 'upcoming'
                        ? 'btn-primary'
                        : 'btn-outline-primary' ?>"
                >
                    Upcoming
                </a>


                <a
                    href="appointments.php?filter=past"
                    class="btn <?= $filter === 'past'
                        ? 'btn-primary'
                        : 'btn-outline-primary' ?>"
                >
                    Past
                </a>

            </div>

        </div>

    </div>


    <!-- Appointment Table -->

    <div class="card border-0 shadow-sm">

        <div class="card-body p-0">


            <div class="p-4 border-bottom">

                <h5 class="fw-bold mb-1">
                    Appointments
                </h5>

                <p class="text-secondary mb-0">
                    <?= count($appointments) ?> appointment(s) found.
                </p>

            </div>


            <?php if (!$appointments): ?>

                <div class="text-center py-5 px-3">

                    <div class="display-5 text-secondary mb-3">
                        <i class="bi bi-calendar-x"></i>
                    </div>

                    <h5 class="fw-bold">
                        No appointments found
                    </h5>

                    <p class="text-secondary mb-0">
                        There are no appointments in this filter.
                    </p>

                </div>

            <?php else: ?>

                <div class="table-responsive">

                    <table class="table align-middle mb-0">

                        <thead class="table-light">

                            <tr>

                                <th class="px-4">
                                    Appointment
                                </th>

                                <th>
                                    Patient
                                </th>

                                <th>
                                    Date & Time
                                </th>

                                <th>
                                    Token
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

                        <?php foreach ($appointments as $a): ?>

                            <?php

                            $status_class = 'secondary';

                            switch ($a['status']) {

                                case 'Requested':
                                    $status_class = 'warning';
                                    break;

                                case 'Confirmed':
                                    $status_class = 'primary';
                                    break;

                                case 'Checked-in':
                                    $status_class = 'info';
                                    break;

                                case 'In Progress':
                                    $status_class = 'dark';
                                    break;

                                case 'Completed':
                                    $status_class = 'success';
                                    break;

                                case 'Cancelled':
                                    $status_class = 'danger';
                                    break;

                                case 'No-show':
                                    $status_class = 'secondary';
                                    break;
                            }

                            ?>


                            <tr>

                                <td class="px-4">

                                    <div class="fw-semibold">
                                        <?= h($a['appointment_ref']) ?>
                                    </div>

                                    <small class="text-secondary">
                                        ID #<?= (int)$a['appointment_id'] ?>
                                    </small>

                                </td>


                                <td>

                                    <div class="fw-semibold">
                                        <?= h($a['patient_name']) ?>
                                    </div>

                                    <small class="text-secondary">
                                        <?= h($a['patient_email']) ?>
                                    </small>

                                </td>


                                <td>

                                    <div class="fw-semibold">

                                        <?= date(
                                            'd M Y',
                                            strtotime($a['appointment_date'])
                                        ) ?>

                                    </div>

                                    <small class="text-secondary">

                                        <?= date(
                                            'h:i A',
                                            strtotime($a['start_time'])
                                        ) ?>

                                    </small>

                                </td>


                                <td>

                                    <?php if (
                                        $a['token_no'] !== null &&
                                        $a['token_no'] !== ''
                                    ): ?>

                                        <span class="badge bg-dark">
                                            #<?= h($a['token_no']) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="text-secondary">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <span
                                        class="badge text-bg-<?= $status_class ?>"
                                    >
                                        <?= h($a['status']) ?>
                                    </span>

                                </td>


                                <td class="text-end px-4">

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#appointmentModal<?= (int)$a['appointment_id'] ?>"
                                    >
                                        <i class="bi bi-eye me-1"></i>
                                        Manage
                                    </button>

                                </td>

                            </tr>


                            <!-- Appointment Modal -->

                            <div
                                class="modal fade"
                                id="appointmentModal<?= (int)$a['appointment_id'] ?>"
                                tabindex="-1"
                                aria-hidden="true"
                            >

                                <div class="modal-dialog modal-dialog-centered">

                                    <div class="modal-content border-0 shadow">


                                        <div class="modal-header">

                                            <div>

                                                <h5 class="modal-title fw-bold">
                                                    Appointment Details
                                                </h5>

                                                <small class="text-secondary">
                                                    <?= h($a['appointment_ref']) ?>
                                                </small>

                                            </div>


                                            <button
                                                type="button"
                                                class="btn-close"
                                                data-bs-dismiss="modal"
                                            ></button>

                                        </div>


                                        <div class="modal-body">


                                            <div class="row g-3">


                                                <div class="col-12">

                                                    <div class="p-3 bg-light rounded-3">

                                                        <div class="small text-secondary">
                                                            Patient
                                                        </div>

                                                        <div class="fw-bold">
                                                            <?= h($a['patient_name']) ?>
                                                        </div>

                                                    </div>

                                                </div>


                                                <div class="col-md-6">

                                                    <div class="small text-secondary">
                                                        Date
                                                    </div>

                                                    <div class="fw-semibold">

                                                        <?= date(
                                                            'd M Y',
                                                            strtotime($a['appointment_date'])
                                                        ) ?>

                                                    </div>

                                                </div>


                                                <div class="col-md-6">

                                                    <div class="small text-secondary">
                                                        Time
                                                    </div>

                                                    <div class="fw-semibold">

                                                        <?= date(
                                                            'h:i A',
                                                            strtotime($a['start_time'])
                                                        ) ?>

                                                    </div>

                                                </div>


                                                <div class="col-md-6">

                                                    <div class="small text-secondary">
                                                        Contact
                                                    </div>

                                                    <div class="fw-semibold">

                                                        <?= h(
                                                            $a['patient_contact']
                                                            ?: 'Not provided'
                                                        ) ?>

                                                    </div>

                                                </div>


                                                <div class="col-md-6">

                                                    <div class="small text-secondary">
                                                        Token
                                                    </div>

                                                    <div class="fw-semibold">

                                                        <?= $a['token_no'] !== null
                                                            ? '#' . h($a['token_no'])
                                                            : 'Not assigned'
                                                        ?>

                                                    </div>

                                                </div>


                                                <div class="col-12">

                                                    <hr>

                                                    <div class="small text-secondary mb-1">
                                                        Current Status
                                                    </div>

                                                    <span
                                                        class="badge text-bg-<?= $status_class ?>"
                                                    >
                                                        <?= h($a['status']) ?>
                                                    </span>

                                                </div>

                                            </div>


                                            <!-- Status Form -->

                                            <?php

                                            $next_statuses =
                                                $valid_transitions[$a['status']]
                                                ?? [];

                                            ?>


                                            <?php if ($next_statuses): ?>

                                                <div class="mt-4">

                                                    <h6 class="fw-bold mb-3">
                                                        Update Appointment Status
                                                    </h6>


                                                    <form method="post">

                                                        <?= csrf_field() ?>


                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="update_status"
                                                        >


                                                        <input
                                                            type="hidden"
                                                            name="appointment_id"
                                                            value="<?= (int)$a['appointment_id'] ?>"
                                                        >


                                                        <div class="mb-3">

                                                            <select
                                                                name="status"
                                                                class="form-select"
                                                                required
                                                            >

                                                                <option value="">
                                                                    Select new status
                                                                </option>


                                                                <?php foreach (
                                                                    $next_statuses
                                                                    as $next_status
                                                                ): ?>

                                                                    <option
                                                                        value="<?= h($next_status) ?>"
                                                                    >
                                                                        <?= h($next_status) ?>
                                                                    </option>

                                                                <?php endforeach; ?>

                                                            </select>

                                                        </div>


                                                        <button
                                                            type="submit"
                                                            class="btn btn-primary w-100"
                                                        >
                                                            <i class="bi bi-check2-circle me-1"></i>
                                                            Update Status
                                                        </button>

                                                    </form>

                                                </div>

                                            <?php else: ?>

                                                <div class="alert alert-light border mt-4 mb-0">

                                                    <i class="bi bi-info-circle me-1"></i>

                                                    This appointment has reached a final status
                                                    and cannot be changed.

                                                </div>

                                            <?php endif; ?>


                                        </div>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


        </div>

    </div>

</div>


<?php require '../includes/footer.php'; ?>