<?php

$page_title = 'Admin Dashboard';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

/*
|--------------------------------------------------------------------------
| MongoDB Collections
|--------------------------------------------------------------------------
*/

$usersCollection = $mongoDb->selectCollection('users');
$patientsCollection = $mongoDb->selectCollection('patients');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$appointmentsCollection = $mongoDb->selectCollection('appointments');
$specializationsCollection = $mongoDb->selectCollection('specializations');

/*
|--------------------------------------------------------------------------
| Dashboard Metrics
|--------------------------------------------------------------------------
*/

$metrics = [
    'users' => $usersCollection->countDocuments([]),
    'patients' => $patientsCollection->countDocuments([]),
    'doctors' => $doctorsCollection->countDocuments([
        'verification_status' => 'Approved'
    ]),
    'pending_doctors' => $doctorsCollection->countDocuments([
        'verification_status' => 'Pending'
    ]),
    'appointments' => $appointmentsCollection->countDocuments([]),
    'today_appointments' => $appointmentsCollection->countDocuments([
        'appointment_date' => date('Y-m-d')
    ]),
    'completed' => $appointmentsCollection->countDocuments([
        'status' => 'Completed'
    ]),
    'cancelled' => $appointmentsCollection->countDocuments([
        'status' => 'Cancelled'
    ]),
    'noshow' => $appointmentsCollection->countDocuments([
        'status' => 'No-show'
    ]),
    'requested' => $appointmentsCollection->countDocuments([
        'status' => 'Requested'
    ]),
    'confirmed' => $appointmentsCollection->countDocuments([
        'status' => 'Confirmed'
    ])
];

/*
|--------------------------------------------------------------------------
| Rates
|--------------------------------------------------------------------------
*/

$totalAppointments = $metrics['appointments'];

$completionRate = $totalAppointments > 0
    ? round(($metrics['completed'] / $totalAppointments) * 100, 1)
    : 0;

$cancellationRate = $totalAppointments > 0
    ? round(($metrics['cancelled'] / $totalAppointments) * 100, 1)
    : 0;

$noShowRate = $totalAppointments > 0
    ? round(($metrics['noshow'] / $totalAppointments) * 100, 1)
    : 0;

/*
|--------------------------------------------------------------------------
| Helper: Find Related Document by Numeric ID
|--------------------------------------------------------------------------
*/

function admin_find_by_id($collection, string $field, $id)
{
    if ($id === null || $id === '') {
        return null;
    }

    $id = is_numeric($id) ? (int) $id : $id;

    return $collection->findOne([
        $field => $id
    ]);
}

/*
|--------------------------------------------------------------------------
| Today's Appointments
|--------------------------------------------------------------------------
*/

$todayAppointments = [];

$todayCursor = $appointmentsCollection->find(
    ['appointment_date' => date('Y-m-d')],
    ['sort' => ['start_time' => 1]]
);

foreach ($todayCursor as $appointment) {
    $a = $appointment->getArrayCopy();

    $patient = admin_find_by_id(
        $patientsCollection,
        'patient_id',
        $a['patient_id'] ?? null
    );

    $doctor = admin_find_by_id(
        $doctorsCollection,
        'doctor_id',
        $a['doctor_id'] ?? null
    );

    $patientUser = $patient
        ? admin_find_by_id(
            $usersCollection,
            'user_id',
            $patient['user_id'] ?? null
        )
        : null;

    $doctorUser = $doctor
        ? admin_find_by_id(
            $usersCollection,
            'user_id',
            $doctor['user_id'] ?? null
        )
        : null;

    $specialization = $doctor
        ? admin_find_by_id(
            $specializationsCollection,
            'specialization_id',
            $doctor['specialization_id'] ?? null
        )
        : null;

    $todayAppointments[] = [
        'appointment_id' => $a['appointment_id'] ?? '',
        'appointment_ref' => $a['appointment_ref'] ?? '',
        'appointment_date' => $a['appointment_date'] ?? '',
        'start_time' => $a['start_time'] ?? '00:00:00',
        'status' => $a['status'] ?? 'Requested',
        'token_no' => $a['token_no'] ?? '',
        'patient_name' => $patientUser['name'] ?? 'Unknown Patient',
        'doctor_name' => $doctorUser['name'] ?? 'Unknown Doctor',
        'specialization' => $specialization['name'] ?? ''
    ];

    if (count($todayAppointments) >= 8) {
        break;
    }
}

/*
|--------------------------------------------------------------------------
| Pending Doctor Verification
|--------------------------------------------------------------------------
*/

$pendingDoctors = [];

$pendingDoctorCursor = $doctorsCollection->find(
    ['verification_status' => 'Pending'],
    [
        'sort' => ['doctor_id' => -1],
        'limit' => 5
    ]
);

foreach ($pendingDoctorCursor as $doctor) {
    $d = $doctor->getArrayCopy();

    $user = admin_find_by_id(
        $usersCollection,
        'user_id',
        $d['user_id'] ?? null
    );

    $specialization = admin_find_by_id(
        $specializationsCollection,
        'specialization_id',
        $d['specialization_id'] ?? null
    );

    $pendingDoctors[] = [
        'doctor_id' => $d['doctor_id'] ?? '',
        'qualification' => $d['qualification'] ?? '',
        'experience' => $d['experience'] ?? '',
        'consultation_fee' => $d['consultation_fee'] ?? 0,
        'name' => $user['name'] ?? 'Unknown Doctor',
        'email' => $user['email'] ?? '',
        'specialization' => $specialization['name'] ?? ''
    ];
}

/*
|--------------------------------------------------------------------------
| Recent Appointments
|--------------------------------------------------------------------------
*/

$recentAppointments = [];

$recentCursor = $appointmentsCollection->find(
    [],
    [
        'sort' => ['created_at' => -1],
        'limit' => 6
    ]
);

foreach ($recentCursor as $appointment) {
    $a = $appointment->getArrayCopy();

    $patient = admin_find_by_id(
        $patientsCollection,
        'patient_id',
        $a['patient_id'] ?? null
    );

    $doctor = admin_find_by_id(
        $doctorsCollection,
        'doctor_id',
        $a['doctor_id'] ?? null
    );

    $patientUser = $patient
        ? admin_find_by_id(
            $usersCollection,
            'user_id',
            $patient['user_id'] ?? null
        )
        : null;

    $doctorUser = $doctor
        ? admin_find_by_id(
            $usersCollection,
            'user_id',
            $doctor['user_id'] ?? null
        )
        : null;

    $recentAppointments[] = [
        'appointment_ref' => $a['appointment_ref'] ?? '',
        'appointment_date' => $a['appointment_date'] ?? '',
        'start_time' => $a['start_time'] ?? '00:00:00',
        'status' => $a['status'] ?? 'Requested',
        'patient_name' => $patientUser['name'] ?? 'Unknown Patient',
        'doctor_name' => $doctorUser['name'] ?? 'Unknown Doctor'
    ];
}


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function admin_status_class(string $status): string
{
    return match ($status) {

        'Requested' => 'status-requested',

        'Confirmed' => 'status-confirmed',

        'Checked-in' => 'status-checked',

        'In Progress' => 'status-progress',

        'Completed' => 'status-completed',

        'Cancelled' => 'status-cancelled',

        'No-show' => 'status-noshow',

        default => 'status-default'
    };
}


function admin_status_icon(string $status): string
{
    return match ($status) {

        'Requested' => 'bi-hourglass-split',

        'Confirmed' => 'bi-check-circle',

        'Checked-in' => 'bi-person-check',

        'In Progress' => 'bi-activity',

        'Completed' => 'bi-check2-all',

        'Cancelled' => 'bi-x-circle',

        'No-show' => 'bi-person-x',

        default => 'bi-circle'
    };
}

require '../includes/header.php';

?>

<style>

.admin-page {
    padding-bottom: 40px;
}


/* Header */

.admin-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 28px;
}

.admin-eyebrow {
    display: block;
    color: #2563eb;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.5px;
    margin-bottom: 7px;
}

.admin-header h1 {
    margin: 0;
    color: #172033;
    font-size: 31px;
    font-weight: 800;
}

.admin-header p {
    margin: 7px 0 0;
    color: #64748b;
}

.admin-date {
    padding: 10px 15px;
    background: #fff;
    border: 1px solid #e5eaf1;
    border-radius: 11px;
    color: #475569;
    font-size: 13px;
    font-weight: 700;
}


/* Metric Cards */

.metric-card {
    position: relative;
    height: 100%;
    padding: 21px;
    border: 1px solid #e7edf4;
    border-radius: 16px;
    background: #fff;
    transition: .2s ease;
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(15, 23, 42, .07);
}

.metric-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.metric-icon {
    width: 43px;
    height: 43px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.metric-icon.blue {
    background: #eff6ff;
    color: #2563eb;
}

.metric-icon.green {
    background: #ecfdf5;
    color: #059669;
}

.metric-icon.orange {
    background: #fff7ed;
    color: #ea580c;
}

.metric-icon.red {
    background: #fef2f2;
    color: #dc2626;
}

.metric-icon.purple {
    background: #f5f3ff;
    color: #7c3aed;
}

.metric-icon.cyan {
    background: #ecfeff;
    color: #0891b2;
}

.metric-value {
    margin-top: 18px;
    color: #172033;
    font-size: 27px;
    line-height: 1;
    font-weight: 800;
}

.metric-label {
    margin-top: 7px;
    color: #64748b;
    font-size: 12px;
    font-weight: 600;
}


/* Section Cards */

.dashboard-card {
    height: 100%;
    border: 1px solid #e7edf4;
    border-radius: 17px;
    background: #fff;
    overflow: hidden;
}

.dashboard-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 20px 22px;
    border-bottom: 1px solid #edf1f5;
}

.dashboard-card-header h2 {
    margin: 0;
    color: #172033;
    font-size: 16px;
    font-weight: 800;
}

.dashboard-card-header p {
    margin: 4px 0 0;
    color: #94a3b8;
    font-size: 11px;
}


/* Rate Cards */

.rate-card {
    padding: 22px;
    border: 1px solid #e7edf4;
    border-radius: 16px;
    background: #fff;
}

.rate-label {
    color: #64748b;
    font-size: 12px;
    font-weight: 700;
}

.rate-value {
    margin-top: 8px;
    color: #172033;
    font-size: 28px;
    font-weight: 800;
}

.rate-bar {
    height: 7px;
    margin-top: 13px;
    background: #eef2f7;
    border-radius: 10px;
    overflow: hidden;
}

.rate-fill {
    height: 100%;
    border-radius: 10px;
}

.rate-fill.green {
    background: #10b981;
}

.rate-fill.red {
    background: #ef4444;
}

.rate-fill.orange {
    background: #f59e0b;
}


/* Appointment Rows */

.appointment-row {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 15px 22px;
    border-bottom: 1px solid #f0f3f7;
}

.appointment-row:last-child {
    border-bottom: 0;
}

.appointment-time {
    min-width: 72px;
    color: #2563eb;
    font-size: 12px;
    font-weight: 800;
}

.appointment-main {
    flex: 1;
    min-width: 0;
}

.appointment-main strong {
    display: block;
    color: #334155;
    font-size: 13px;
}

.appointment-main span {
    display: block;
    margin-top: 3px;
    color: #94a3b8;
    font-size: 11px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}


/* Status */

.status-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
    white-space: nowrap;
}

.status-requested {
    background: #fff7ed;
    color: #c2410c;
}

.status-confirmed {
    background: #ecfdf5;
    color: #047857;
}

.status-checked {
    background: #eff6ff;
    color: #1d4ed8;
}

.status-progress {
    background: #f5f3ff;
    color: #6d28d9;
}

.status-completed {
    background: #ecfdf5;
    color: #047857;
}

.status-cancelled {
    background: #fef2f2;
    color: #b91c1c;
}

.status-noshow {
    background: #fff7ed;
    color: #c2410c;
}

.status-default {
    background: #f1f5f9;
    color: #475569;
}


/* Doctor Verification */

.doctor-row {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 15px 22px;
    border-bottom: 1px solid #f0f3f7;
}

.doctor-row:last-child {
    border-bottom: 0;
}

.doctor-avatar {
    width: 39px;
    height: 39px;
    flex: 0 0 39px;
    border-radius: 11px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 13px;
    font-weight: 800;
}

.doctor-info {
    flex: 1;
    min-width: 0;
}

.doctor-info strong {
    display: block;
    color: #334155;
    font-size: 13px;
}

.doctor-info span {
    display: block;
    margin-top: 3px;
    color: #94a3b8;
    font-size: 11px;
}


/* Quick Actions */

.quick-action {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 13px;
    border: 1px solid #e8edf4;
    border-radius: 12px;
    color: #334155;
    text-decoration: none;
    transition: .2s ease;
}

.quick-action:hover {
    color: #2563eb;
    border-color: #bfdbfe;
    background: #f8fbff;
}

.quick-action-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    border-radius: 10px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
}

.quick-action strong {
    display: block;
    font-size: 12px;
}

.quick-action span {
    display: block;
    margin-top: 2px;
    color: #94a3b8;
    font-size: 10px;
}


/* Empty State */

.empty-state {
    padding: 38px 20px;
    text-align: center;
}

.empty-state i {
    display: block;
    margin-bottom: 10px;
    color: #cbd5e1;
    font-size: 30px;
}

.empty-state strong {
    display: block;
    color: #475569;
    font-size: 13px;
}

.empty-state span {
    display: block;
    margin-top: 4px;
    color: #94a3b8;
    font-size: 11px;
}


/* Responsive */

@media (max-width: 767px) {

    .admin-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .admin-header h1 {
        font-size: 26px;
    }

    .appointment-row {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .appointment-time {
        min-width: 65px;
    }

    .status-badge {
        margin-left: 79px;
    }

}


/* Slightly Larger Dashboard Text */

.admin-eyebrow {
    font-size: 12px;
}

.admin-header h1 {
    font-size: 34px;
}

.admin-header p {
    font-size: 16px;
}

.admin-date {
    font-size: 14px;
}

.metric-value {
    font-size: 30px;
}

.metric-label {
    font-size: 14px;
}

.dashboard-card-header h2 {
    font-size: 18px;
}

.dashboard-card-header p {
    font-size: 13px;
}

.rate-label {
    font-size: 14px;
}

.rate-value {
    font-size: 30px;
}

.appointment-time {
    font-size: 14px;
}

.appointment-main strong {
    font-size: 15px;
}

.appointment-main span {
    font-size: 12px;
}

.status-badge {
    font-size: 12px;
}

.doctor-info strong {
    font-size: 15px;
}

.doctor-info span {
    font-size: 12px;
}

.quick-action strong {
    font-size: 14px;
}

.quick-action span {
    font-size: 12px;
}

.empty-state strong {
    font-size: 15px;
}

.empty-state span {
    font-size: 13px;
}

.table {
    font-size: 14px;
}

</style>


<div class="admin-page">

    <!-- Header -->

    <div class="admin-header">

        <div>

            <span class="admin-eyebrow">
                ADMINISTRATION
            </span>

            <h1>Dashboard</h1>

            <p>
                Monitor appointments, doctors, patients and system activity.
            </p>

        </div>

        <div class="admin-date">
            <i class="bi bi-calendar3 me-1"></i>
            <?= date('d M Y') ?>
        </div>

    </div>


    <!-- Main Metrics -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-md-4 col-xl-3">

            <div class="metric-card">

                <div class="metric-top">

                    <div class="metric-icon blue">
                        <i class="bi bi-people"></i>
                    </div>

                </div>

                <div class="metric-value">
                    <?= number_format($metrics['users']) ?>
                </div>

                <div class="metric-label">
                    Total Users
                </div>

            </div>

        </div>


        <div class="col-6 col-md-4 col-xl-3">

            <div class="metric-card">

                <div class="metric-top">

                    <div class="metric-icon cyan">
                        <i class="bi bi-person-heart"></i>
                    </div>

                </div>

                <div class="metric-value">
                    <?= number_format($metrics['patients']) ?>
                </div>

                <div class="metric-label">
                    Registered Patients
                </div>

            </div>

        </div>


        <div class="col-6 col-md-4 col-xl-3">

            <div class="metric-card">

                <div class="metric-top">

                    <div class="metric-icon green">
                        <i class="bi bi-person-badge"></i>
                    </div>

                </div>

                <div class="metric-value">
                    <?= number_format($metrics['doctors']) ?>
                </div>

                <div class="metric-label">
                    Approved Doctors
                </div>

            </div>

        </div>


        <div class="col-6 col-md-4 col-xl-3">

            <div class="metric-card">

                <div class="metric-top">

                    <div class="metric-icon orange">
                        <i class="bi bi-person-check"></i>
                    </div>

                </div>

                <div class="metric-value">
                    <?= number_format($metrics['pending_doctors']) ?>
                </div>

                <div class="metric-label">
                    Pending Verification
                </div>

            </div>

        </div>


        <div class="col-6 col-md-4 col-xl-3">

            <div class="metric-card">

                <div class="metric-top">

                    <div class="metric-icon purple">
                        <i class="bi bi-calendar2-check"></i>
                    </div>

                </div>

                <div class="metric-value">
                    <?= number_format($metrics['appointments']) ?>
                </div>

                <div class="metric-label">
                    Total Appointments
                </div>

            </div>

        </div>


        <div class="col-6 col-md-4 col-xl-3">

            <div class="metric-card">

                <div class="metric-top">

                    <div class="metric-icon blue">
                        <i class="bi bi-calendar-day"></i>
                    </div>

                </div>

                <div class="metric-value">
                    <?= number_format($metrics['today_appointments']) ?>
                </div>

                <div class="metric-label">
                    Today's Appointments
                </div>

            </div>

        </div>


        <div class="col-6 col-md-4 col-xl-3">

            <div class="metric-card">

                <div class="metric-top">

                    <div class="metric-icon green">
                        <i class="bi bi-check2-circle"></i>
                    </div>

                </div>

                <div class="metric-value">
                    <?= number_format($metrics['completed']) ?>
                </div>

                <div class="metric-label">
                    Completed
                </div>

            </div>

        </div>


        <div class="col-6 col-md-4 col-xl-3">

            <div class="metric-card">

                <div class="metric-top">

                    <div class="metric-icon red">
                        <i class="bi bi-x-circle"></i>
                    </div>

                </div>

                <div class="metric-value">
                    <?= number_format($metrics['cancelled']) ?>
                </div>

                <div class="metric-label">
                    Cancelled
                </div>

            </div>

        </div>

    </div>


    <!-- Rates -->

    <div class="row g-3 mb-4">

        <div class="col-md-4">

            <div class="rate-card">

                <div class="rate-label">
                    Completion Rate
                </div>

                <div class="rate-value">
                    <?= number_format($completionRate, 1) ?>%
                </div>

                <div class="rate-bar">
                    <div
                        class="rate-fill green"
                        style="width: <?= min(100, $completionRate) ?>%;"
                    ></div>
                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="rate-card">

                <div class="rate-label">
                    Cancellation Rate
                </div>

                <div class="rate-value">
                    <?= number_format($cancellationRate, 1) ?>%
                </div>

                <div class="rate-bar">
                    <div
                        class="rate-fill red"
                        style="width: <?= min(100, $cancellationRate) ?>%;"
                    ></div>
                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="rate-card">

                <div class="rate-label">
                    No-show Rate
                </div>

                <div class="rate-value">
                    <?= number_format($noShowRate, 1) ?>%
                </div>

                <div class="rate-bar">
                    <div
                        class="rate-fill orange"
                        style="width: <?= min(100, $noShowRate) ?>%;"
                    ></div>
                </div>

            </div>

        </div>

    </div>


    <!-- Today's Appointments + Pending Doctors -->

    <div class="row g-4 mb-4">

        <!-- Today's Appointments -->

        <div class="col-lg-7">

            <div class="dashboard-card">

                <div class="dashboard-card-header">

                    <div>

                        <h2>
                            Today's Appointments
                        </h2>

                        <p>
                            Current appointment activity for today
                        </p>

                    </div>

                    <a
                        href="appointments.php"
                        class="btn btn-sm btn-outline-primary"
                    >
                        View All
                    </a>

                </div>


                <?php if ($todayAppointments): ?>

                    <?php foreach ($todayAppointments as $a): ?>

                        <div class="appointment-row">

                            <div class="appointment-time">

                                <?= h(
                                    date(
                                        'h:i A',
                                        strtotime($a['start_time'])
                                    )
                                ) ?>

                            </div>


                            <div class="appointment-main">

                                <strong>
                                    <?= h($a['patient_name']) ?>
                                </strong>

                                <span>
                                    Dr. <?= h($a['doctor_name']) ?>

                                    <?php if (!empty($a['specialization'])): ?>

                                        · <?= h($a['specialization']) ?>

                                    <?php endif; ?>

                                </span>

                            </div>


                            <span
                                class="status-badge <?= h(
                                    admin_status_class($a['status'])
                                ) ?>"
                            >

                                <i class="bi <?= h(
                                    admin_status_icon($a['status'])
                                ) ?>"></i>

                                <?= h($a['status']) ?>

                            </span>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-calendar-x"></i>

                        <strong>
                            No appointments today
                        </strong>

                        <span>
                            There are no scheduled appointments for today.
                        </span>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- Pending Doctors -->

        <div class="col-lg-5">

            <div class="dashboard-card">

                <div class="dashboard-card-header">

                    <div>

                        <h2>
                            Doctor Verification
                        </h2>

                        <p>
                            Doctors waiting for approval
                        </p>

                    </div>

                    <span class="badge text-bg-warning">
                        <?= number_format($metrics['pending_doctors']) ?>
                    </span>

                </div>


                <?php if ($pendingDoctors): ?>

                    <?php foreach ($pendingDoctors as $doctor): ?>

                        <?php

                        $doctorNameParts =
                            preg_split(
                                '/\s+/',
                                trim($doctor['name'])
                            );

                        $doctorInitials =
                            strtoupper(
                                substr(
                                    $doctorNameParts[0] ?? 'D',
                                    0,
                                    1
                                )
                            );

                        ?>

                        <div class="doctor-row">

                            <div class="doctor-avatar">
                                <?= h($doctorInitials) ?>
                            </div>


                            <div class="doctor-info">

                                <strong>
                                    <?= h($doctor['name']) ?>
                                </strong>

                                <span>

                                    <?= h(
                                        $doctor['specialization']
                                        ?? 'Specialization not set'
                                    ) ?>

                                </span>

                            </div>


                            <a
                                href="doctors.php"
                                class="btn btn-sm btn-outline-primary"
                            >
                                Review
                            </a>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-person-check"></i>

                        <strong>
                            No pending doctors
                        </strong>

                        <span>
                            All doctor profiles have been reviewed.
                        </span>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- Recent Appointments -->

    <div class="row g-4 mb-4">

        <div class="col-12">

            <div class="dashboard-card">

                <div class="dashboard-card-header">

                    <div>

                        <h2>
                            Recent Appointments
                        </h2>

                        <p>
                            Latest appointment activity in the system
                        </p>

                    </div>

                    <a
                        href="appointments.php"
                        class="btn btn-sm btn-outline-primary"
                    >
                        Manage Appointments
                    </a>

                </div>


                <?php if ($recentAppointments): ?>

                    <div class="table-responsive">

                        <table class="table mb-0 align-middle">

                            <thead>

                                <tr>

                                    <th class="ps-4">
                                        Reference
                                    </th>

                                    <th>
                                        Patient
                                    </th>

                                    <th>
                                        Doctor
                                    </th>

                                    <th>
                                        Date
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

                                <?php foreach ($recentAppointments as $a): ?>

                                    <tr>

                                        <td class="ps-4">

                                            <strong class="small">
                                                <?= h(
                                                    $a['appointment_ref']
                                                ) ?>
                                            </strong>

                                        </td>


                                        <td class="small">
                                            <?= h(
                                                $a['patient_name']
                                            ) ?>
                                        </td>


                                        <td class="small">
                                            Dr. <?= h(
                                                $a['doctor_name']
                                            ) ?>
                                        </td>


                                        <td class="small">

                                            <?= h(
                                                date(
                                                    'd M Y',
                                                    strtotime(
                                                        $a['appointment_date']
                                                    )
                                                )
                                            ) ?>

                                        </td>


                                        <td class="small">

                                            <?= h(
                                                date(
                                                    'h:i A',
                                                    strtotime(
                                                        $a['start_time']
                                                    )
                                                )
                                            ) ?>

                                        </td>


                                        <td>

                                            <span
                                                class="status-badge <?= h(
                                                    admin_status_class(
                                                        $a['status']
                                                    )
                                                ) ?>"
                                            >

                                                <?= h($a['status']) ?>

                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="empty-state">

                        <i class="bi bi-calendar2-x"></i>

                        <strong>
                            No appointment records
                        </strong>

                        <span>
                            Appointment activity will appear here.
                        </span>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- Quick Actions -->

    <div class="dashboard-card">

        <div class="dashboard-card-header">

            <div>

                <h2>
                    Quick Management
                </h2>

                <p>
                    Access common administration tasks
                </p>

            </div>

        </div>


        <div class="p-4">

            <div class="row g-3">

                <div class="col-sm-6 col-lg-3">

                    <a
                        href="users.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-people"></i>
                        </div>

                        <div>
                            <strong>User Management</strong>
                            <span>Manage system users</span>
                        </div>

                    </a>

                </div>


                <div class="col-sm-6 col-lg-3">

                    <a
                        href="doctors.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-person-badge"></i>
                        </div>

                        <div>
                            <strong>Doctors</strong>
                            <span>Verify and manage doctors</span>
                        </div>

                    </a>

                </div>


                <div class="col-sm-6 col-lg-3">

                    <a
                        href="appointments.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-calendar-check"></i>
                        </div>

                        <div>
                            <strong>Appointments</strong>
                            <span>Monitor appointments</span>
                        </div>

                    </a>

                </div>


                <div class="col-sm-6 col-lg-3">

                    <a
                        href="specializations.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-diagram-3"></i>
                        </div>

                        <div>
                            <strong>Specializations</strong>
                            <span>Manage specialties</span>
                        </div>

                    </a>

                </div>


                <div class="col-sm-6 col-lg-3">

                    <a
                        href="clinics.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-hospital"></i>
                        </div>

                        <div>
                            <strong>Clinics</strong>
                            <span>Manage clinics</span>
                        </div>

                    </a>

                </div>


                <div class="col-sm-6 col-lg-3">

                    <a
                        href="doctor-branches.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-geo-alt"></i>
                        </div>

                        <div>
                            <strong>Doctor Branches</strong>
                            <span>Manage doctor locations</span>
                        </div>

                    </a>

                </div>


                <div class="col-sm-6 col-lg-3">

                    <a
                        href="analytics.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-bar-chart"></i>
                        </div>

                        <div>
                            <strong>Analytics</strong>
                            <span>View system analytics</span>
                        </div>

                    </a>

                </div>


                <div class="col-sm-6 col-lg-3">

                    <a
                        href="complaints.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-chat-square-text"></i>
                        </div>

                        <div>
                            <strong>Complaint Management</strong>
                            <span>View and reply to complaints</span>
                        </div>

                    </a>

                </div>


                <div class="col-sm-6 col-lg-3">

                    <a
                        href="audit-logs.php"
                        class="quick-action"
                    >

                        <div class="quick-action-icon">
                            <i class="bi bi-shield-check"></i>
                        </div>

                        <div>
                            <strong>Audit Logs</strong>
                            <span>Review system activity</span>
                        </div>

                    </a>

                </div>

            </div>

        </div>

    </div>

</div>


<?php require '../includes/footer.php'; ?>