<?php

$page_title = 'Live Queue';
$asset_prefix = '../';

require '../includes/header.php';
require_role('patient');

$patient_id = get_patient_id((int)$_SESSION['user_id']);

if (!$patient_id) {
    flash('danger', 'Patient profile not found.');
    header('Location: dashboard.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Appointment ID
|--------------------------------------------------------------------------
*/

$appointment_id = (int)($_GET['id'] ?? 0);

if ($appointment_id <= 0) {
    flash('warning', 'Invalid appointment.');
    header('Location: appointments.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Get Patient Appointment
|--------------------------------------------------------------------------
*/

$appointmentDoc = $mongoDb->appointments->findOne([
    'appointment_id' => $appointment_id,
    'patient_id' => (int) $patient_id
]);

$appointment = null;

if ($appointmentDoc) {
    $appointment = (array) $appointmentDoc;

    $doctor = $mongoDb->doctors->findOne([
        'doctor_id' => (int) ($appointment['doctor_id'] ?? 0)
    ]);

    if ($doctor) {
        $doctor = (array) $doctor;

        $doctorUser = $mongoDb->users->findOne([
            'user_id' => (int) ($doctor['user_id'] ?? 0)
        ]);

        $specialization = $mongoDb->specializations->findOne([
            'specialization_id' => (int) ($doctor['specialization_id'] ?? 0)
        ]);

        $appointment['doctor_name'] = $doctorUser
            ? ((array) $doctorUser)['name'] ?? 'Doctor'
            : 'Doctor';

        $appointment['specialization'] = $specialization
            ? ((array) $specialization)['name'] ?? 'General'
            : 'General';
    } else {
        $appointment['doctor_name'] = 'Doctor';
        $appointment['specialization'] = 'General';
    }

    $branch = $mongoDb->branches->findOne([
        'branch_id' => (int) ($appointment['branch_id'] ?? 0)
    ]);

    $appointment['branch_name'] = $branch
        ? ((array) $branch)['branch_name'] ?? 'Branch'
        : 'Branch';

    $clinic = null;

    if ($branch) {
        $branchData = (array) $branch;

        $clinic = $mongoDb->clinics->findOne([
            'clinic_id' => (int) ($branchData['clinic_id'] ?? 0)
        ]);
    }

    $appointment['clinic_name'] = $clinic
        ? ((array) $clinic)['name'] ?? 'Clinic'
        : 'Clinic';
}


if (!$appointment) {
    flash('danger', 'Appointment not found.');
    header('Location: appointments.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Only Queue-Eligible Appointments
|--------------------------------------------------------------------------
*/

$queue_statuses = [
    'Confirmed',
    'Checked-in',
    'In Progress'
];

if (!in_array($appointment['status'], $queue_statuses, true)) {
    flash(
        'warning',
        'Live queue is only available for active appointments.'
    );

    header('Location: appointments.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Queue Information
|--------------------------------------------------------------------------
*/

$queue = [];

if (
    $appointment['token_no'] !== null &&
    $appointment['token_no'] !== ''
) {
    $queueDocs = $mongoDb->appointments->find(
        [
            'doctor_id' => (int) $appointment['doctor_id'],
            'appointment_date' => $appointment['appointment_date'],
            'status' => [
                '$nin' => ['Cancelled', 'No-show']
            ],
            'token_no' => ['$ne' => null]
        ],
        [
            'sort' => ['token_no' => 1]
        ]
    );

    foreach ($queueDocs as $queueDoc) {
        $q = (array) $queueDoc;

        $patient = $mongoDb->patients->findOne([
            'patient_id' => (int) ($q['patient_id'] ?? 0)
        ]);

        $patientName = 'Patient';

        if ($patient) {
            $patientData = (array) $patient;

            $patientUser = $mongoDb->users->findOne([
                'user_id' => (int) ($patientData['user_id'] ?? 0)
            ]);

            if ($patientUser) {
                $patientName = ((array) $patientUser)['name']
                    ?? 'Patient';
            }
        }

        $queue[] = [
            'appointment_id' => (int) ($q['appointment_id'] ?? 0),
            'token_no' => $q['token_no'] ?? null,
            'status' => $q['status'] ?? '',
            'start_time' => $q['start_time'] ?? '00:00:00',
            'patient_name' => $patientName
        ];
    }
}


/*
|--------------------------------------------------------------------------
| Calculate Queue Position
|--------------------------------------------------------------------------
*/

$my_token = (int)$appointment['token_no'];

$position = null;
$patients_ahead = 0;
$current_patient = null;
$completed_before_me = 0;

foreach ($queue as $index => $q) {

    $token = (int)$q['token_no'];

    if ($q['status'] === 'In Progress') {
        $current_patient = $q;
    }

    if (
        $token < $my_token &&
        !in_array(
            $q['status'],
            ['Completed'],
            true
        )
    ) {
        $patients_ahead++;
    }

    if (
        $token === $my_token &&
        (int)$q['appointment_id'] === $appointment_id
    ) {
        $position = $index + 1;
    }
}


/*
|--------------------------------------------------------------------------
| Determine Display State
|--------------------------------------------------------------------------
*/

$is_my_turn = $appointment['status'] === 'In Progress';

$is_completed = $appointment['status'] === 'Completed';

$status_message = '';

if ($appointment['status'] === 'Confirmed') {

    $status_message =
        'Please wait for your turn and check in when you arrive.';

} elseif ($appointment['status'] === 'Checked-in') {

    $status_message =
        'You are checked in. Please wait for your consultation.';

} elseif ($appointment['status'] === 'In Progress') {

    $status_message =
        'Your consultation is currently in progress.';
}


/*
|--------------------------------------------------------------------------
| Current Token
|--------------------------------------------------------------------------
*/

$current_token = null;

if ($current_patient) {
    $current_token = (int)$current_patient['token_no'];
}


/*
|--------------------------------------------------------------------------
| Estimated Patients Ahead
|--------------------------------------------------------------------------
*/

$estimated_wait = $patients_ahead;


/*
|--------------------------------------------------------------------------
| Recent Queue
|--------------------------------------------------------------------------
*/

$recent_queue = array_slice($queue, 0, 10);

?>

<div class="container-fluid py-4">


    <!-- Header -->

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <span class="badge rounded-pill bg-primary-subtle text-primary mb-2">
                Patient Portal
            </span>

            <h2 class="fw-bold mb-1">
                Live Queue
            </h2>

            <p class="text-secondary mb-0">
                Track your appointment queue in real time.
            </p>

        </div>


        <a
            href="appointments.php"
            class="btn btn-outline-primary"
        >
            <i class="bi bi-arrow-left me-1"></i>
            My Appointments
        </a>

    </div>


    <!-- Appointment Summary -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body p-4">

            <div class="row g-4 align-items-center">


                <div class="col-lg-7">

                    <div class="small text-secondary mb-2">
                        Appointment
                    </div>

                    <h4 class="fw-bold mb-1">
                        Dr. <?= h($appointment['doctor_name']) ?>
                    </h4>

                    <div class="text-secondary mb-3">
                        <?= h($appointment['specialization'] ?? 'General') ?>
                    </div>


                    <div class="d-flex flex-wrap gap-2">

                        <span class="badge text-bg-light border">
                            <i class="bi bi-calendar3 me-1"></i>
                            <?= h($appointment['appointment_date']) ?>
                        </span>

                        <span class="badge text-bg-light border">
                            <i class="bi bi-clock me-1"></i>
                            <?= date(
                                'h:i A',
                                strtotime($appointment['start_time'])
                            ) ?>
                        </span>

                        <span class="badge text-bg-light border">
                            <i class="bi bi-building me-1"></i>
                            <?= h($appointment['clinic_name'] ?? 'Clinic') ?>
                        </span>

                        <span class="badge text-bg-light border">
                            <?= h($appointment['branch_name'] ?? 'Branch') ?>
                        </span>

                    </div>

                </div>


                <div class="col-lg-5 text-lg-end">

                    <div class="small text-secondary mb-2">
                        Appointment Reference
                    </div>

                    <div class="fs-5 fw-bold">
                        <?= h($appointment['appointment_ref']) ?>
                    </div>

                    <div class="mt-2">

                        <span class="badge text-bg-primary">
                            <?= h($appointment['status']) ?>
                        </span>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Main Queue Cards -->

    <div class="row g-4 mb-4">


        <!-- My Token -->

        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4 text-center">

                    <div class="small text-secondary mb-2">
                        My Token
                    </div>

                    <div class="display-4 fw-bold text-primary">

                        <?php if ($my_token > 0): ?>

                            #<?= h($my_token) ?>

                        <?php else: ?>

                            —

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>


        <!-- Current Token -->

        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4 text-center">

                    <div class="small text-secondary mb-2">
                        Current Token
                    </div>

                    <div class="display-4 fw-bold text-success">

                        <?php if ($current_token): ?>

                            #<?= h($current_token) ?>

                        <?php else: ?>

                            —

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>


        <!-- Patients Ahead -->

        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4 text-center">

                    <div class="small text-secondary mb-2">
                        Patients Ahead
                    </div>

                    <div class="display-4 fw-bold text-info">

                        <?= h($patients_ahead) ?>

                    </div>

                </div>

            </div>

        </div>


        <!-- Position -->

        <div class="col-md-6 col-xl-3">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4 text-center">

                    <div class="small text-secondary mb-2">
                        Queue Position
                    </div>

                    <div class="display-4 fw-bold">

                        <?php if ($position): ?>

                            #<?= h($position) ?>

                        <?php else: ?>

                            —

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Status Banner -->

    <?php if ($is_my_turn): ?>

        <div class="alert alert-success border-0 shadow-sm mb-4">

            <div class="d-flex align-items-center gap-3">

                <div class="fs-2">
                    <i class="bi bi-person-check"></i>
                </div>

                <div>

                    <div class="fw-bold">
                        It's your turn!
                    </div>

                    <div>
                        Your consultation is currently in progress.
                    </div>

                </div>

            </div>

        </div>

    <?php else: ?>

        <div class="alert alert-primary border-0 shadow-sm mb-4">

            <div class="d-flex align-items-center gap-3">

                <div class="fs-2">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <div>

                    <div class="fw-bold">
                        Queue Status
                    </div>

                    <div>
                        <?= h($status_message) ?>
                    </div>

                </div>

            </div>

        </div>

    <?php endif; ?>


    <!-- Queue Table -->

    <div class="card border-0 shadow-sm">

        <div class="card-body p-0">


            <div class="p-4 border-bottom">

                <div class="d-flex justify-content-between align-items-center">

                    <div>

                        <h5 class="fw-bold mb-1">
                            Today's Queue
                        </h5>

                        <p class="text-secondary mb-0">
                            Current appointment queue for this doctor.
                        </p>

                    </div>


                    <button
                        type="button"
                        class="btn btn-sm btn-outline-primary"
                        onclick="window.location.reload();"
                    >
                        <i class="bi bi-arrow-clockwise me-1"></i>
                        Refresh
                    </button>

                </div>

            </div>


            <?php if (!$recent_queue): ?>

                <div class="text-center py-5">

                    <div class="display-5 text-secondary mb-3">
                        <i class="bi bi-people"></i>
                    </div>

                    <h5 class="fw-bold">
                        Queue information unavailable
                    </h5>

                    <p class="text-secondary mb-0">
                        Your token may not have been generated yet.
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
                                    Time
                                </th>

                                <th>
                                    Status
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php foreach ($recent_queue as $q): ?>

                            <?php

                            $is_me =
                                (int)$q['appointment_id']
                                === $appointment_id;

                            $status_class = 'secondary';

                            if ($q['status'] === 'Confirmed') {
                                $status_class = 'primary';
                            }

                            if ($q['status'] === 'Checked-in') {
                                $status_class = 'info';
                            }

                            if ($q['status'] === 'In Progress') {
                                $status_class = 'success';
                            }

                            if ($q['status'] === 'Completed') {
                                $status_class = 'dark';
                            }

                            ?>

                            <tr
                                <?php if ($is_me): ?>
                                    class="table-primary"
                                <?php endif; ?>
                            >

                                <td class="px-4">

                                    <span class="badge bg-dark fs-6">
                                        #<?= h($q['token_no']) ?>
                                    </span>

                                    <?php if ($is_me): ?>

                                        <span class="badge text-bg-primary ms-1">
                                            You
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?php if ($is_me): ?>

                                        <strong>
                                            You
                                        </strong>

                                    <?php else: ?>

                                        <span class="text-secondary">
                                            Patient
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <td>

                                    <?= date(
                                        'h:i A',
                                        strtotime($q['start_time'])
                                    ) ?>

                                </td>


                                <td>

                                    <span
                                        class="badge text-bg-<?= $status_class ?>"
                                    >
                                        <?= h($q['status']) ?>
                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


        </div>

    </div>


    <!-- Auto Refresh -->

    <script>

        setTimeout(function () {
            window.location.reload();
        }, 30000);

    </script>


</div>


<?php require '../includes/footer.php'; ?>