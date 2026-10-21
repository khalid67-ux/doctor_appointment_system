<?php

$page_title = 'Appointment Management';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$admin_user_id = (int) $_SESSION['user_id'];

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$patientsCollection = $mongoDb->selectCollection('patients');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');
$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');


/*
|--------------------------------------------------------------------------
| Statuses
|--------------------------------------------------------------------------
*/

$statuses = appointment_statuses();


/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['q'] ?? '');
$date_filter = trim($_GET['date'] ?? '');
$status_filter = trim($_GET['status'] ?? '');
$branch_filter = (int) ($_GET['branch_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| Validate Status Filter
|--------------------------------------------------------------------------
*/

if (
    $status_filter !== '' &&
    !in_array($status_filter, $statuses, true)
) {
    $status_filter = '';
}


/*
|--------------------------------------------------------------------------
| Validate Date
|--------------------------------------------------------------------------
*/

if (
    $date_filter !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_filter)
) {
    $date_filter = '';
}


/*
|--------------------------------------------------------------------------
| Branches
|--------------------------------------------------------------------------
*/

$branches = [];

$branchCursor = $branchesCollection->find([]);

foreach ($branchCursor as $branchDocument) {
    $branch = $branchDocument->getArrayCopy();

    $clinicDocument = $clinicsCollection->findOne([
        'clinic_id' => (int) ($branch['clinic_id'] ?? 0),
    ]);

    $branches[] = [
        'branch_id' => (int) ($branch['branch_id'] ?? 0),
        'branch_name' => $branch['branch_name'] ?? '',
        'clinic_name' => $clinicDocument['name'] ?? '',
    ];
}

usort($branches, function ($a, $b) {
    $clinicCompare = strcmp(
        (string) $a['clinic_name'],
        (string) $b['clinic_name']
    );

    return $clinicCompare !== 0
        ? $clinicCompare
        : strcmp(
            (string) $a['branch_name'],
            (string) $b['branch_name']
        );
});


/*
|--------------------------------------------------------------------------
| Update Appointment Status
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'status_update'
) {

    verify_csrf();

    $appointment_id =
        (int) ($_POST['appointment_id'] ?? 0);

    $new_status =
        trim($_POST['status'] ?? '');


    /*
    | Validate status
    */

    if (
        $appointment_id <= 0 ||
        !in_array($new_status, $statuses, true)
    ) {

        flash(
            'danger',
            'Invalid appointment status.'
        );

        header('Location: appointments.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Fetch Appointment
    |--------------------------------------------------------------------------
    */

    $appointmentDocument = $appointmentsCollection->findOne([
        'appointment_id' => (int) $appointment_id,
    ]);

    $appointment = null;

    if ($appointmentDocument !== null) {
        $appointment = $appointmentDocument->getArrayCopy();

        $patientDocument = $patientsCollection->findOne([
            'patient_id' => (int) ($appointment['patient_id'] ?? 0),
        ]);

        $patient = $patientDocument !== null
            ? $patientDocument->getArrayCopy()
            : [];

        $doctorDocument = $doctorsCollection->findOne([
            'doctor_id' => (int) ($appointment['doctor_id'] ?? 0),
        ]);

        $doctor = $doctorDocument !== null
            ? $doctorDocument->getArrayCopy()
            : [];

        $appointment['patient_user_id'] =
            (int) ($patient['user_id'] ?? 0);

        $appointment['doctor_user_id'] =
            (int) ($doctor['user_id'] ?? 0);

        $patientUser = $usersCollection->findOne([
            'user_id' => $appointment['patient_user_id'],
        ]);

        $doctorUser = $usersCollection->findOne([
            'user_id' => $appointment['doctor_user_id'],
        ]);

        $appointment['patient_name'] =
            $patientUser['name'] ?? 'Patient';

        $appointment['doctor_name'] =
            $doctorUser['name'] ?? 'Doctor';
    }


    if (!$appointment) {

        flash(
            'danger',
            'Appointment not found.'
        );

        header('Location: appointments.php');
        exit;
    }


    $old_status =
        $appointment['status'];


    /*
    |--------------------------------------------------------------------------
    | Don't update if unchanged
    |--------------------------------------------------------------------------
    */

    if ($old_status === $new_status) {

        flash(
            'info',
            'Appointment is already ' .
            $new_status .
            '.'
        );

        header('Location: appointments.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Basic Status Transition Rules
    |--------------------------------------------------------------------------
    */

    $allowed_transitions = [

        'Requested' => [
            'Confirmed',
            'Cancelled'
        ],

        'Confirmed' => [
            'Checked-in',
            'Cancelled',
            'No-show'
        ],

        'Checked-in' => [
            'In Progress',
            'Cancelled',
            'No-show'
        ],

        'In Progress' => [
            'Completed',
            'Cancelled'
        ],

        'Completed' => [],

        'Cancelled' => [],

        'No-show' => []

    ];


    if (
        isset($allowed_transitions[$old_status]) &&
        !in_array(
            $new_status,
            $allowed_transitions[$old_status],
            true
        )
    ) {

        flash(
            'warning',
            "Cannot change appointment from {$old_status} to {$new_status}."
        );

        header('Location: appointments.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Update
    |--------------------------------------------------------------------------
    */

    try {

        $latestDocument = $appointmentsCollection->findOne([
            'appointment_id' => (int) $appointment_id,
        ]);

        if ($latestDocument === null) {
            throw new RuntimeException('Appointment not found.');
        }

        $latest = $latestDocument->getArrayCopy();
        $old_status = (string) ($latest['status'] ?? '');

        if ($old_status === $new_status) {
            flash('info', 'Appointment is already ' . $new_status . '.');
            header('Location: appointments.php');
            exit;
        }

        if (
            !isset($allowed_transitions[$old_status]) ||
            !in_array($new_status, $allowed_transitions[$old_status], true)
        ) {
            flash(
                'warning',
                "Cannot change appointment from {$old_status} to {$new_status}."
            );
            header('Location: appointments.php');
            exit;
        }

        $updateResult = $appointmentsCollection->updateOne(
            [
                'appointment_id' => (int) $appointment_id,
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
                'Appointment status changed concurrently.'
            );
        }


        /*
        | Notify patient
        */

        create_notification(
            (int) $appointment['patient_user_id'],
            'appointment',
            'Appointment Status Updated',
            "Appointment {$appointment['appointment_ref']} with Dr. {$appointment['doctor_name']} is now {$new_status}."
        );


        /*
        | Notify doctor
        */

        create_notification(
            (int) $appointment['doctor_user_id'],
            'appointment',
            'Appointment Status Updated',
            "Appointment {$appointment['appointment_ref']} for {$appointment['appointment_date']} at " .
            substr((string) ($appointment['start_time'] ?? ''), 0, 5) .
            " is now {$new_status}."
        );


        /*
        | Audit
        */

        audit(
            $admin_user_id,
            "Changed appointment status from {$old_status} to {$new_status}",
            'appointment',
            $appointment_id
        );


        flash(
            'success',
            'Appointment status updated successfully.'
        );

    } catch (Throwable $e) {
        error_log('Admin appointment status update error: ' . $e->getMessage());
        flash(
            'danger',
            'Unable to update appointment status.'
        );
    }


    header('Location: appointments.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_appointments = $appointmentsCollection->countDocuments([]);

$today = date('Y-m-d');

$today_appointments = $appointmentsCollection->countDocuments([
    'appointment_date' => $today,
]);

$requested_appointments = $appointmentsCollection->countDocuments([
    'status' => 'Requested',
]);

$confirmed_appointments = $appointmentsCollection->countDocuments([
    'status' => 'Confirmed',
]);

$completed_appointments = $appointmentsCollection->countDocuments([
    'status' => 'Completed',
]);

$cancelled_appointments = $appointmentsCollection->countDocuments([
    'status' => 'Cancelled',
]);


/*
|--------------------------------------------------------------------------
| MongoDB Filters and Pagination
|--------------------------------------------------------------------------
*/

$per_page = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));

$mongoFilter = [];

if ($date_filter !== '') {
    $mongoFilter['appointment_date'] = $date_filter;
}

if ($status_filter !== '') {
    $mongoFilter['status'] = $status_filter;
}

if ($branch_filter > 0) {
    $mongoFilter['branch_id'] = $branch_filter;
}

$appointments = [];

$cursor = $appointmentsCollection->find(
    $mongoFilter,
    [
        'sort' => [
            'appointment_date' => -1,
            'start_time' => -1,
            'appointment_id' => -1,
        ],
    ]
);

foreach ($cursor as $appointmentDocument) {
    $a = $appointmentDocument->getArrayCopy();

    $patientDocument = $patientsCollection->findOne([
        'patient_id' => (int) ($a['patient_id'] ?? 0),
    ]);

    $patient = $patientDocument !== null
        ? $patientDocument->getArrayCopy()
        : [];

    $doctorDocument = $doctorsCollection->findOne([
        'doctor_id' => (int) ($a['doctor_id'] ?? 0),
    ]);

    $doctor = $doctorDocument !== null
        ? $doctorDocument->getArrayCopy()
        : [];

    $patientUser = $usersCollection->findOne([
        'user_id' => (int) ($patient['user_id'] ?? 0),
    ]);

    $doctorUser = $usersCollection->findOne([
        'user_id' => (int) ($doctor['user_id'] ?? 0),
    ]);

    $branchDocument = $branchesCollection->findOne([
        'branch_id' => (int) ($a['branch_id'] ?? 0),
    ]);

    $branch = $branchDocument !== null
        ? $branchDocument->getArrayCopy()
        : [];

    $clinicDocument = $clinicsCollection->findOne([
        'clinic_id' => (int) ($branch['clinic_id'] ?? 0),
    ]);

    $a['patient_id'] = (int) ($a['patient_id'] ?? 0);
    $a['patient_name'] = $patientUser['name'] ?? '';
    $a['patient_email'] = $patientUser['email'] ?? '';

    $a['doctor_id'] = (int) ($a['doctor_id'] ?? 0);
    $a['doctor_name'] = $doctorUser['name'] ?? '';
    $a['doctor_email'] = $doctorUser['email'] ?? '';

    $a['branch_name'] = $branch['branch_name'] ?? '';
    $a['clinic_name'] = $clinicDocument['name'] ?? '';

    if ($search !== '') {
        $searchable = implode(' ', [
            $a['appointment_ref'] ?? '',
            $a['patient_name'],
            $a['patient_email'],
            $a['doctor_name'],
            $a['doctor_email'],
        ]);

        if (stripos($searchable, $search) === false) {
            continue;
        }
    }

    $appointments[] = $a;
}

$total_filtered = count($appointments);

$total_pages = max(
    1,
    (int) ceil($total_filtered / $per_page)
);

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

$appointments = array_slice(
    $appointments,
    $offset,
    $per_page
);


/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function appointment_page_url(
    int $page,
    string $search,
    string $date,
    string $status,
    int $branch
): string {

    $query = [
        'page' => $page
    ];

    if ($search !== '') {
        $query['q'] = $search;
    }

    if ($date !== '') {
        $query['date'] = $date;
    }

    if ($status !== '') {
        $query['status'] = $status;
    }

    if ($branch > 0) {
        $query['branch_id'] = $branch;
    }

    return 'appointments.php?' .
        http_build_query($query);
}


/*
|--------------------------------------------------------------------------
| UI Helpers
|--------------------------------------------------------------------------
*/

function appointment_status_class(
    string $status
): string {

    return match ($status) {

        'Requested' =>
            'status-requested',

        'Confirmed' =>
            'status-confirmed',

        'Checked-in' =>
            'status-checked',

        'In Progress' =>
            'status-progress',

        'Completed' =>
            'status-completed',

        'Cancelled' =>
            'status-cancelled',

        'No-show' =>
            'status-noshow',

        default =>
            'status-default'
    };
}


function appointment_status_icon(
    string $status
): string {

    return match ($status) {

        'Requested' =>
            'bi-hourglass-split',

        'Confirmed' =>
            'bi-check-circle',

        'Checked-in' =>
            'bi-person-check',

        'In Progress' =>
            'bi-activity',

        'Completed' =>
            'bi-check2-all',

        'Cancelled' =>
            'bi-x-circle',

        'No-show' =>
            'bi-person-x',

        default =>
            'bi-question-circle'
    };
}

require '../includes/header.php';

?>

<style>

/* ----------------------------------------------------------
   Page
---------------------------------------------------------- */

.appointment-page {
    padding-bottom: 40px;
    font-size: 1.12rem;
}

.appointment-page .btn,
.appointment-page .form-control,
.appointment-page .form-select,
.appointment-page .badge,
.appointment-page .page-link {
    font-size: 1.04rem;
}


/* ----------------------------------------------------------
   Header
---------------------------------------------------------- */

.appointment-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 25px;
}

.appointment-eyebrow {
    display: block;
    color: #2563eb;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.4px;
    margin-bottom: 7px;
}

.appointment-header h1 {
    margin: 0;
    color: #172033;
    font-size: 42px;
    font-weight: 800;
}

.appointment-header p {
    margin: 7px 0 0;
    color: #64748b;
    font-size: 16px;
}


/* ----------------------------------------------------------
   Stats
---------------------------------------------------------- */

.app-stat {
    height: 100%;
    padding: 19px;
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 15px;
}

.app-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 11px;

    display: flex;
    align-items: center;
    justify-content: center;
}

.app-stat-icon.blue {
    background: #eff6ff;
    color: #2563eb;
}

.app-stat-icon.orange {
    background: #fff7ed;
    color: #ea580c;
}

.app-stat-icon.green {
    background: #ecfdf5;
    color: #059669;
}

.app-stat-icon.red {
    background: #fef2f2;
    color: #dc2626;
}

.app-stat-value {
    margin-top: 14px;
    color: #172033;
    font-size: 36px;
    font-weight: 800;
}

.app-stat-label {
    margin-top: 4px;
    color: #64748b;
    font-size: 14px;
    font-weight: 600;
}


/* ----------------------------------------------------------
   Filter
---------------------------------------------------------- */

.app-filter {
    padding: 20px;
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 16px;
}

.filter-label {
    display: block;
    color: #475569;
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 7px;
}

.filter-control {
    min-height: 50px;
    border: 1px solid #dce3ec;
    border-radius: 10px;
    font-size: 1.05rem;
}

.filter-control:focus {
    border-color: #60a5fa;
    box-shadow:
        0 0 0 3px rgba(37, 99, 235, .08);
}


/* ----------------------------------------------------------
   Table
---------------------------------------------------------- */

.app-table-card {
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 17px;
    overflow: hidden;
}

.app-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;

    padding: 19px 22px;

    border-bottom: 1px solid #edf1f5;
}

.app-table-header h2 {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.app-table-header p {
    margin: 4px 0 0;
    color: #94a3b8;
    font-size: 14px;
}

.app-table {
    margin: 0;
}

.app-table thead th {
    padding: 13px 15px;
    background: #f8fafc;
    color: #64748b;

    font-size: 13px;
    font-weight: 800;

    text-transform: uppercase;
    letter-spacing: .05em;

    border-bottom: 1px solid #e8edf3;

    white-space: nowrap;
}

.app-table tbody td {
    padding: 15px;
    border-color: #f0f3f7;
    vertical-align: middle;
    font-size: 1.08rem;
}


/* ----------------------------------------------------------
   Reference
---------------------------------------------------------- */

.app-ref {
    color: #2563eb;
    font-size: 16px;
    font-weight: 800;
}

.app-token {
    margin-top: 3px;
    color: #94a3b8;
    font-size: 13px;
}


/* ----------------------------------------------------------
   People
---------------------------------------------------------- */

.person-name {
    color: #334155;
    font-size: 16px;
    font-weight: 800;
    white-space: nowrap;
}

.person-email {
    margin-top: 2px;
    color: #94a3b8;
    font-size: 13px;
}


/* ----------------------------------------------------------
   Date
---------------------------------------------------------- */

.app-date {
    color: #334155;
    font-size: 16px;
    font-weight: 700;
    white-space: nowrap;
}

.app-time {
    margin-top: 3px;
    color: #64748b;
    font-size: 13px;
}


/* ----------------------------------------------------------
   Branch
---------------------------------------------------------- */

.app-branch {
    color: #475569;
    font-size: 15px;
    font-weight: 700;
    max-width: 170px;
}

.app-clinic {
    margin-top: 2px;
    color: #94a3b8;
    font-size: 12px;
}


/* ----------------------------------------------------------
   Status
---------------------------------------------------------- */

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;

    padding: 8px 12px;

    border-radius: 999px;

    font-size: 13px;
    font-weight: 800;

    white-space: nowrap;
}

.status-requested {
    background: #fff7ed;
    color: #c2410c;
}

.status-confirmed {
    background: #eff6ff;
    color: #1d4ed8;
}

.status-checked {
    background: #ecfdf5;
    color: #047857;
}

.status-progress {
    background: #f5f3ff;
    color: #6d28d9;
}

.status-completed {
    background: #dcfce7;
    color: #166534;
}

.status-cancelled {
    background: #fef2f2;
    color: #b91c1c;
}

.status-noshow {
    background: #f1f5f9;
    color: #475569;
}

.status-default {
    background: #f1f5f9;
    color: #475569;
}


/* ----------------------------------------------------------
   Empty
---------------------------------------------------------- */

.app-empty {
    padding: 60px 20px;
    text-align: center;
}

.app-empty-icon {
    width: 68px;
    height: 68px;

    margin: 0 auto 15px;

    border-radius: 18px;

    background: #f1f5f9;
    color: #94a3b8;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 28px;
}

.app-empty h3 {
    margin: 0;
    color: #475569;
    font-size: 16px;
    font-weight: 800;
}

.app-empty p {
    margin: 6px 0 0;
    color: #94a3b8;
    font-size: 12px;
}


/* ----------------------------------------------------------
   Pagination
---------------------------------------------------------- */

.app-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;

    padding: 17px 22px;

    border-top: 1px solid #edf1f5;
}

.pagination-info {
    color: #94a3b8;
    font-size: 11px;
}

.pagination {
    margin: 0;
}

.pagination .page-link {
    border-color: #e2e8f0;
    color: #475569;

    font-size: 12px;
    font-weight: 700;
}

.pagination .page-item.active .page-link {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}


/* ----------------------------------------------------------
   Responsive
---------------------------------------------------------- */

@media (max-width: 767px) {

    .appointment-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .appointment-header h1 {
        font-size: 26px;
    }

    .app-pagination {
        flex-direction: column;
        align-items: flex-start;
    }

}

</style>


<div class="appointment-page">


    <!-- Header -->

    <div class="appointment-header">

        <div>

            <span class="appointment-eyebrow">
                <i class="bi bi-calendar2-check me-1"></i>
                ADMINISTRATION
            </span>

            <h1>
                Appointment Management
            </h1>

            <p>
                Monitor and manage all appointments across the healthcare system.
            </p>

        </div>

        <a
            href="dashboard.php"
            class="btn btn-light"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Dashboard
        </a>

    </div>


    <!-- Statistics -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">

            <div class="app-stat">

                <div class="app-stat-icon blue">
                    <i class="bi bi-calendar3"></i>
                </div>

                <div class="app-stat-value">
                    <?= number_format(
                        $total_appointments
                    ) ?>
                </div>

                <div class="app-stat-label">
                    Total Appointments
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="app-stat">

                <div class="app-stat-icon orange">
                    <i class="bi bi-calendar-day"></i>
                </div>

                <div class="app-stat-value">
                    <?= number_format(
                        $today_appointments
                    ) ?>
                </div>

                <div class="app-stat-label">
                    Today's Appointments
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="app-stat">

                <div class="app-stat-icon green">
                    <i class="bi bi-check-circle"></i>
                </div>

                <div class="app-stat-value">
                    <?= number_format(
                        $confirmed_appointments
                    ) ?>
                </div>

                <div class="app-stat-label">
                    Confirmed
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="app-stat">

                <div class="app-stat-icon red">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <div class="app-stat-value">
                    <?= number_format(
                        $requested_appointments
                    ) ?>
                </div>

                <div class="app-stat-label">
                    Awaiting Confirmation
                </div>

            </div>

        </div>

    </div>


    <!-- Filters -->

    <div class="app-filter mb-4">

        <form
            method="get"
            class="row g-3 align-items-end"
        >

            <div class="col-lg-4">

                <label class="filter-label">
                    Search
                </label>

                <input
                    type="text"
                    name="q"
                    class="form-control filter-control"
                    value="<?= h($search) ?>"
                    placeholder="Reference, patient or doctor..."
                >

            </div>


            <div class="col-md-4 col-lg-2">

                <label class="filter-label">
                    Appointment Date
                </label>

                <input
                    type="date"
                    name="date"
                    class="form-control filter-control"
                    value="<?= h($date_filter) ?>"
                >

            </div>


            <div class="col-md-4 col-lg-2">

                <label class="filter-label">
                    Status
                </label>

                <select
                    name="status"
                    class="form-select filter-control"
                >

                    <option value="">
                        All Status
                    </option>

                    <?php foreach ($statuses as $status): ?>

                        <option
                            value="<?= h($status) ?>"
                            <?= $status_filter === $status
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= h($status) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-4 col-lg-2">

                <label class="filter-label">
                    Branch
                </label>

                <select
                    name="branch_id"
                    class="form-select filter-control"
                >

                    <option value="">
                        All Branches
                    </option>

                    <?php foreach ($branches as $branch): ?>

                        <option
                            value="<?= (int)$branch['branch_id'] ?>"
                            <?= $branch_filter ===
                                (int)$branch['branch_id']
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= h(
                                $branch['clinic_name']
                                    ? $branch['clinic_name'] .
                                      ' - ' .
                                      $branch['branch_name']
                                    : $branch['branch_name']
                            ) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-4 col-lg-2 d-flex gap-2">

                <button
                    type="submit"
                    class="btn btn-primary flex-grow-1"
                >
                    <i class="bi bi-search me-1"></i>
                    Filter
                </button>

                <a
                    href="appointments.php"
                    class="btn btn-light"
                    title="Clear filters"
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>

            </div>

        </form>

    </div>


    <!-- Appointment Table -->

    <div class="app-table-card">

        <div class="app-table-header">

            <div>

                <h2>
                    All Appointments
                </h2>

                <p>
                    Showing
                    <?= number_format(
                        $total_filtered
                    ) ?>
                    matching appointment<?= $total_filtered !== 1 ? 's' : '' ?>.
                </p>

            </div>

            <span class="badge text-bg-light">
                Completed:
                <?= number_format(
                    $completed_appointments
                ) ?>
            </span>

        </div>


        <?php if ($appointments): ?>

            <div class="table-responsive">

                <table class="table app-table">

                    <thead>

                        <tr>

                            <th>
                                Reference
                            </th>

                            <th>
                                Patient
                            </th>

                            <th>
                                Doctor
                            </th>

                            <th>
                                Date & Time
                            </th>

                            <th>
                                Branch
                            </th>

                            <th>
                                Status
                            </th>

                            <th class="text-end">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $appointments as $a
                        ): ?>

                            <tr>

                                <!-- Reference -->

                                <td>

                                    <div class="app-ref">
                                        <?= h(
                                            $a['appointment_ref']
                                        ) ?>
                                    </div>

                                    <?php if (
                                        $a['token_no'] !== null
                                    ): ?>

                                        <div class="app-token">
                                            Token #<?= (int)
                                                $a['token_no'] ?>
                                        </div>

                                    <?php endif; ?>

                                </td>


                                <!-- Patient -->

                                <td>

                                    <div class="person-name">
                                        <?= h(
                                            $a['patient_name']
                                        ) ?>
                                    </div>

                                    <div class="person-email">
                                        <?= h(
                                            $a['patient_email']
                                        ) ?>
                                    </div>

                                </td>


                                <!-- Doctor -->

                                <td>

                                    <div class="person-name">
                                        Dr. <?= h(
                                            $a['doctor_name']
                                        ) ?>
                                    </div>

                                    <div class="person-email">
                                        <?= h(
                                            $a['doctor_email']
                                        ) ?>
                                    </div>

                                </td>


                                <!-- Date -->

                                <td>

                                    <div class="app-date">

                                        <?= h(
                                            date(
                                                'd M Y',
                                                strtotime(
                                                    $a[
                                                        'appointment_date'
                                                    ]
                                                )
                                            )
                                        ) ?>

                                    </div>

                                    <div class="app-time">

                                        <i class="bi bi-clock me-1"></i>

                                        <?= h(
                                            date(
                                                'h:i A',
                                                strtotime(
                                                    $a['start_time']
                                                )
                                            )
                                        ) ?>

                                    </div>

                                </td>


                                <!-- Branch -->

                                <td>

                                    <div class="app-branch">

                                        <?= h(
                                            $a['branch_name']
                                                ?: 'Not specified'
                                        ) ?>

                                    </div>

                                    <?php if (
                                        !empty(
                                            $a['clinic_name']
                                        )
                                    ): ?>

                                        <div class="app-clinic">

                                            <?= h(
                                                $a['clinic_name']
                                            ) ?>

                                        </div>

                                    <?php endif; ?>

                                </td>


                                <!-- Status -->

                                <td>

                                    <span
                                        class="status-pill <?= h(
                                            appointment_status_class(
                                                $a['status']
                                            )
                                        ) ?>"
                                    >

                                        <i class="bi <?= h(
                                            appointment_status_icon(
                                                $a['status']
                                            )
                                        ) ?>"></i>

                                        <?= h(
                                            $a['status']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Action -->

                                <td class="text-end">

                                    <?php

                                    $next_statuses =
                                        [];

                                    if (
                                        isset(
                                            [
                                                'Requested' => [
                                                    'Confirmed',
                                                    'Cancelled'
                                                ],

                                                'Confirmed' => [
                                                    'Checked-in',
                                                    'Cancelled',
                                                    'No-show'
                                                ],

                                                'Checked-in' => [
                                                    'In Progress',
                                                    'Cancelled',
                                                    'No-show'
                                                ],

                                                'In Progress' => [
                                                    'Completed',
                                                    'Cancelled'
                                                ],

                                                'Completed' => [],

                                                'Cancelled' => [],

                                                'No-show' => []
                                            ][$a['status']]
                                        )
                                    ) {

                                        $next_statuses =
                                            [
                                                'Requested' => [
                                                    'Confirmed',
                                                    'Cancelled'
                                                ],

                                                'Confirmed' => [
                                                    'Checked-in',
                                                    'Cancelled',
                                                    'No-show'
                                                ],

                                                'Checked-in' => [
                                                    'In Progress',
                                                    'Cancelled',
                                                    'No-show'
                                                ],

                                                'In Progress' => [
                                                    'Completed',
                                                    'Cancelled'
                                                ],

                                                'Completed' => [],

                                                'Cancelled' => [],

                                                'No-show' => []
                                            ][$a['status']];
                                    }

                                    ?>


                                    <?php if (
                                        !empty(
                                            $next_statuses
                                        )
                                    ): ?>

                                        <div class="dropdown">

                                            <button
                                                class="btn btn-sm btn-outline-primary dropdown-toggle"
                                                type="button"
                                                data-bs-toggle="dropdown"
                                            >
                                                Update
                                            </button>

                                            <ul class="dropdown-menu dropdown-menu-end">

                                                <?php foreach (
                                                    $next_statuses
                                                    as $next_status
                                                ): ?>

                                                    <li>

                                                        <form
                                                            method="post"
                                                            onsubmit="return confirm(
                                                                'Change appointment status to <?= h($next_status) ?>?'
                                                            );"
                                                        >

                                                            <?= csrf_field() ?>

                                                            <input
                                                                type="hidden"
                                                                name="action"
                                                                value="status_update"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="appointment_id"
                                                                value="<?= (int)$a['appointment_id'] ?>"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="status"
                                                                value="<?= h($next_status) ?>"
                                                            >

                                                            <button
                                                                type="submit"
                                                                class="dropdown-item"
                                                            >
                                                                <?= h(
                                                                    $next_status
                                                                ) ?>
                                                            </button>

                                                        </form>

                                                    </li>

                                                <?php endforeach; ?>

                                            </ul>

                                        </div>

                                    <?php else: ?>

                                        <span class="text-secondary small">
                                            Final
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <!-- Pagination -->

            <?php if ($total_pages > 1): ?>

                <div class="app-pagination">

                    <div class="pagination-info">

                        Showing

                        <?= number_format(
                            $offset + 1
                        ) ?>

                        –

                        <?= number_format(
                            min(
                                $offset + $per_page,
                                $total_filtered
                            )
                        ) ?>

                        of

                        <?= number_format(
                            $total_filtered
                        ) ?>

                        appointments

                    </div>


                    <nav>

                        <ul class="pagination pagination-sm">

                            <?php if ($page > 1): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            appointment_page_url(
                                                $page - 1,
                                                $search,
                                                $date_filter,
                                                $status_filter,
                                                $branch_filter
                                            )
                                        ) ?>"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                </li>

                            <?php endif; ?>


                            <?php

                            $start_page = max(
                                1,
                                $page - 2
                            );

                            $end_page = min(
                                $total_pages,
                                $page + 2
                            );

                            for (
                                $i = $start_page;
                                $i <= $end_page;
                                $i++
                            ):
                            ?>

                                <li
                                    class="page-item <?= $i === $page
                                        ? 'active'
                                        : ''
                                    ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            appointment_page_url(
                                                $i,
                                                $search,
                                                $date_filter,
                                                $status_filter,
                                                $branch_filter
                                            )
                                        ) ?>"
                                    >
                                        <?= $i ?>
                                    </a>

                                </li>

                            <?php endfor; ?>


                            <?php if (
                                $page < $total_pages
                            ): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            appointment_page_url(
                                                $page + 1,
                                                $search,
                                                $date_filter,
                                                $status_filter,
                                                $branch_filter
                                            )
                                        ) ?>"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                </li>

                            <?php endif; ?>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>


        <?php else: ?>

            <div class="app-empty">

                <div class="app-empty-icon">
                    <i class="bi bi-calendar-x"></i>
                </div>

                <h3>
                    No appointments found
                </h3>

                <p>
                    Try changing your search or filter criteria.
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>


<?php require '../includes/footer.php'; ?>