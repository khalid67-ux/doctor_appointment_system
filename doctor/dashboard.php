<?php

$page_title = 'Doctor Dashboard';
$asset_prefix = '../';

require '../includes/header.php';
require_role('doctor');

$doctor_id = get_doctor_id((int) $_SESSION['user_id']);

if (!$doctor_id) {
    echo '<div class="alert alert-danger">Doctor profile not found.</div>';
    require '../includes/footer.php';
    exit;
}

$doctorDocument = $mongoDb
    ->selectCollection('doctors')
    ->findOne([
        'doctor_id' => $doctor_id
    ]);

if ($doctorDocument === null) {
    echo '<div class="alert alert-danger">Doctor information could not be loaded.</div>';
    require '../includes/footer.php';
    exit;
}

$doctorData = $doctorDocument->getArrayCopy();

$userDocument = $mongoDb
    ->selectCollection('users')
    ->findOne([
        'user_id' => (int) $_SESSION['user_id']
    ]);

if ($userDocument === null) {
    echo '<div class="alert alert-danger">Doctor user information could not be loaded.</div>';
    require '../includes/footer.php';
    exit;
}

$userData = $userDocument->getArrayCopy();

$specialization = null;

if (!empty($doctorData['specialization_id'])) {
    $specializationDocument = $mongoDb
        ->selectCollection('specializations')
        ->findOne([
            'specialization_id' => (int) $doctorData['specialization_id']
        ]);

    if ($specializationDocument !== null) {
        $specialization = $specializationDocument['name'] ?? null;
    }
}

$doctor = array_merge($doctorData, [
    'name' => $userData['name'] ?? '',
    'email' => $userData['email'] ?? '',
    'specialization' => $specialization
]);


/*
|--------------------------------------------------------------------------
| Today's Statistics - MongoDB
|--------------------------------------------------------------------------
*/

$stats = [
    'today'     => 0,
    'requested' => 0,
    'confirmed' => 0,
    'completed' => 0
];

$todayDate = date('Y-m-d');

$appointmentsCollection = $mongoDb->selectCollection('appointments');

$todayAppointments = $appointmentsCollection->find([
    'doctor_id' => (int) $doctor_id,
    'appointment_date' => $todayDate
]);

foreach ($todayAppointments as $appointment) {
    $stats['today']++;

    $status = $appointment['status'] ?? '';

    if ($status === 'Requested') {
        $stats['requested']++;
    } elseif ($status === 'Confirmed') {
        $stats['confirmed']++;
    } elseif ($status === 'Completed') {
        $stats['completed']++;
    }
}


/*
|--------------------------------------------------------------------------
| Upcoming Appointments - MongoDB
|--------------------------------------------------------------------------
*/

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$patientsCollection = $mongoDb->selectCollection('patients');
$usersCollection = $mongoDb->selectCollection('users');

$todayDate = date('Y-m-d');

$upcoming_appointments = [];

$upcomingCursor = $appointmentsCollection->find(
    [
        'doctor_id' => (int) $doctor_id,
        'appointment_date' => ['$gte' => $todayDate],
        'status' => [
            '$nin' => ['Cancelled', 'Completed', 'No-show']
        ]
    ],
    [
        'sort' => [
            'appointment_date' => 1,
            'start_time' => 1
        ],
        'limit' => 8
    ]
);

foreach ($upcomingCursor as $appointment) {
    $appointmentData = $appointment->getArrayCopy();

    $patient = $patientsCollection->findOne([
        'patient_id' => (int) ($appointmentData['patient_id'] ?? 0)
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

    $upcoming_appointments[] = array_merge(
        $appointmentData,
        [
            'patient_id' => $patientData['patient_id'] ?? null,
            'patient_name' => $userData['name'] ?? 'Unknown Patient',
            'patient_email' => $userData['email'] ?? '',
            'contact' => $patientData['contact'] ?? ''
        ]
    );
}


/*
|--------------------------------------------------------------------------
| Today's Appointments - MongoDB
|--------------------------------------------------------------------------
*/

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$patientsCollection = $mongoDb->selectCollection('patients');
$usersCollection = $mongoDb->selectCollection('users');

$todayDate = date('Y-m-d');

$today_appointments = [];

$todayCursor = $appointmentsCollection->find(
    [
        'doctor_id' => (int) $doctor_id,
        'appointment_date' => $todayDate
    ],
    [
        'sort' => [
            'start_time' => 1
        ],
        'limit' => 10
    ]
);

foreach ($todayCursor as $appointment) {
    $appointmentData = $appointment->getArrayCopy();

    $patient = $patientsCollection->findOne([
        'patient_id' => (int) ($appointmentData['patient_id'] ?? 0)
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

    $today_appointments[] = array_merge(
        $appointmentData,
        [
            'patient_id' => $patientData['patient_id'] ?? null,
            'patient_name' => $userData['name'] ?? 'Unknown Patient',
            'patient_email' => $userData['email'] ?? '',
            'contact' => $patientData['contact'] ?? ''
        ]
    );
}


/*
|--------------------------------------------------------------------------
| Verification UI
|--------------------------------------------------------------------------
*/

$verification = $doctor['verification_status'];

$verification_class = 'pending';
$verification_icon = 'bi-hourglass-split';
$verification_text = 'Pending Verification';

if ($verification === 'Approved') {

    $verification_class = 'approved';
    $verification_icon = 'bi-patch-check-fill';
    $verification_text = 'Verified Doctor';

} elseif ($verification === 'Rejected') {

    $verification_class = 'rejected';
    $verification_icon = 'bi-x-circle-fill';
    $verification_text = 'Verification Rejected';
}

?>

<!-- =========================================================
     Doctor Dashboard Header
========================================================= -->

<div class="doctor-dashboard-header">

    <div>

        <div class="doctor-welcome-label">
            DOCTOR PORTAL
        </div>

        <h1>
            Welcome, Dr. <?= h($doctor['name']) ?>
        </h1>

        <p>
            Manage your appointments, schedule and professional profile
            from one place.
        </p>

    </div>


    <div class="doctor-header-actions">

        <a
            href="appointments.php"
            class="btn btn-primary"
        >
            <i class="bi bi-calendar-check me-2"></i>
            Appointments
        </a>

        <a
            href="profile.php"
            class="btn btn-outline-primary"
        >
            <i class="bi bi-person me-2"></i>
            My Profile
        </a>

    </div>

</div>


<!-- =========================================================
     Verification Banner
========================================================= -->

<div class="verification-banner <?= h($verification_class) ?>">

    <div class="verification-icon">

        <i class="bi <?= h($verification_icon) ?>"></i>

    </div>

    <div class="verification-content">

        <strong>
            <?= h($verification_text) ?>
        </strong>

        <span>

            <?php if ($verification === 'Approved'): ?>

                Your professional profile has been verified by the
                administrator.

            <?php elseif ($verification === 'Rejected'): ?>

                Your doctor verification was rejected.
                Please contact the administrator.

            <?php else: ?>

                Your doctor profile is waiting for administrator
                verification.

            <?php endif; ?>

        </span>

    </div>

    <a
        href="profile.php"
        class="verification-action"
    >
        View Profile
        <i class="bi bi-arrow-right"></i>
    </a>

</div>


<!-- =========================================================
     Statistics
========================================================= -->

<div class="doctor-stat-grid">

    <!-- Today's appointments -->

    <div class="doctor-stat-card">

        <div class="doctor-stat-icon blue">
            <i class="bi bi-calendar2-day"></i>
        </div>

        <div>

            <span>
                Today's Appointments
            </span>

            <strong>
                <?= $stats['today'] ?>
            </strong>

            <small>
                Scheduled for today
            </small>

        </div>

    </div>


    <!-- Requested -->

    <div class="doctor-stat-card">

        <div class="doctor-stat-icon orange">
            <i class="bi bi-clock-history"></i>
        </div>

        <div>

            <span>
                Requested
            </span>

            <strong>
                <?= $stats['requested'] ?>
            </strong>

            <small>
                Need attention
            </small>

        </div>

    </div>


    <!-- Confirmed -->

    <div class="doctor-stat-card">

        <div class="doctor-stat-icon green">
            <i class="bi bi-check2-circle"></i>
        </div>

        <div>

            <span>
                Confirmed
            </span>

            <strong>
                <?= $stats['confirmed'] ?>
            </strong>

            <small>
                Confirmed visits
            </small>

        </div>

    </div>


    <!-- Completed -->

    <div class="doctor-stat-card">

        <div class="doctor-stat-icon purple">
            <i class="bi bi-clipboard2-check"></i>
        </div>

        <div>

            <span>
                Completed
            </span>

            <strong>
                <?= $stats['completed'] ?>
            </strong>

            <small>
                Completed today
            </small>

        </div>

    </div>

</div>


<!-- =========================================================
     Main Content
========================================================= -->

<div class="row g-4">


    <!-- =====================================================
         Today's Schedule
    ====================================================== -->

    <div class="col-xl-12">

        <div class="doctor-panel">

            <div class="doctor-panel-header">

                <div>

                    <span class="doctor-panel-label">
                        TODAY
                    </span>

                    <h3>
                        Today's Schedule
                    </h3>

                </div>

                <a
                    href="appointments.php"
                    class="doctor-panel-link"
                >
                    View All
                    <i class="bi bi-arrow-right"></i>
                </a>

            </div>


            <?php if ($today_appointments): ?>

                <div class="doctor-appointment-list">

                    <?php foreach ($today_appointments as $appointment): ?>

                        <div class="doctor-appointment-item">


                            <!-- Time -->

                            <div class="doctor-time">

                                <strong>
                                    <?= date(
                                        'h:i',
                                        strtotime($appointment['start_time'])
                                    ) ?>
                                </strong>

                                <span>
                                    <?= date(
                                        'A',
                                        strtotime($appointment['start_time'])
                                    ) ?>
                                </span>

                            </div>


                            <!-- Patient -->

                            <div class="doctor-patient-info">

                                <div class="patient-name">

                                    <div class="patient-avatar">

                                        <?= h(
                                            strtoupper(
                                                substr(
                                                    $appointment['patient_name'],
                                                    0,
                                                    1
                                                )
                                            )
                                        ) ?>

                                    </div>

                                    <div>

                                        <strong>
                                            <?= h(
                                                $appointment['patient_name']
                                            ) ?>
                                        </strong>

                                        <small>
                                            <?= h(
                                                $appointment['contact']
                                                ?: $appointment['patient_email']
                                            ) ?>
                                        </small>

                                    </div>

                                </div>

                                <div class="appointment-reference">

                                    Ref:
                                    <strong>
                                        <?= h(
                                            $appointment['appointment_ref']
                                        ) ?>
                                    </strong>

                                    <?php if (!empty($appointment['token_no'])): ?>

                                        <span>
                                            Token #<?= h(
                                                $appointment['token_no']
                                            ) ?>
                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <!-- Status -->

                            <div class="doctor-appointment-status">

                                <?php
                                $status_class = strtolower(
                                    str_replace(
                                        [' ', '-'],
                                        '',
                                        $appointment['status']
                                    )
                                );
                                ?>

                                <span
                                    class="doctor-status status-<?= h(
                                        $status_class
                                    ) ?>"
                                >
                                    <?= h($appointment['status']) ?>
                                </span>

                            </div>


                            <!-- Action -->

                            <div>

                                <a
                                    href="appointments.php"
                                    class="doctor-action-btn"
                                    title="Manage Appointment"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </a>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="doctor-empty-state">

                    <div class="doctor-empty-icon">
                        <i class="bi bi-calendar-x"></i>
                    </div>

                    <h4>
                        No appointments today
                    </h4>

                    <p>
                        Your schedule is currently clear for today.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </div>


  
<!-- =========================================================
     Upcoming Appointments
========================================================= -->

<div class="doctor-panel mt-4">

    <div class="doctor-panel-header">

        <div>

            <span class="doctor-panel-label">
                UPCOMING
            </span>

            <h3>
                Upcoming Appointments
            </h3>

        </div>

        <a
            href="appointments.php"
            class="doctor-panel-link"
        >
            Manage Appointments
            <i class="bi bi-arrow-right"></i>
        </a>

    </div>


    <?php if ($upcoming_appointments): ?>

        <div class="table-responsive">

            <table class="table doctor-dashboard-table">

                <thead>

                    <tr>

                        <th>Date</th>

                        <th>Time</th>

                        <th>Patient</th>

                        <th>Reference</th>

                        <th>Status</th>

                    </tr>

                </thead>

                <tbody>

                    <?php foreach ($upcoming_appointments as $appointment): ?>

                        <?php
                        $status_class = strtolower(
                            str_replace(
                                [' ', '-'],
                                '',
                                $appointment['status']
                            )
                        );
                        ?>

                        <tr>

                            <td>

                                <strong>
                                    <?= date(
                                        'd M Y',
                                        strtotime(
                                            $appointment['appointment_date']
                                        )
                                    ) ?>
                                </strong>

                            </td>

                            <td>

                                <?= date(
                                    'h:i A',
                                    strtotime(
                                        $appointment['start_time']
                                    )
                                ) ?>

                            </td>

                            <td>

                                <div class="table-patient">

                                    <div class="small-patient-avatar">

                                        <?= h(
                                            strtoupper(
                                                substr(
                                                    $appointment['patient_name'],
                                                    0,
                                                    1
                                                )
                                            )
                                        ) ?>

                                    </div>

                                    <strong>
                                        <?= h(
                                            $appointment['patient_name']
                                        ) ?>
                                    </strong>

                                </div>

                            </td>

                            <td>

                                <span class="reference-text">
                                    <?= h(
                                        $appointment['appointment_ref']
                                    ) ?>
                                </span>

                            </td>

                            <td>

                                <span
                                    class="doctor-status status-<?= h(
                                        $status_class
                                    ) ?>"
                                >
                                    <?= h($appointment['status']) ?>
                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="doctor-empty-state compact">

            <div class="doctor-empty-icon">
                <i class="bi bi-calendar2-check"></i>
            </div>

            <h4>
                No upcoming appointments
            </h4>

            <p>
                There are currently no upcoming appointments.
            </p>

        </div>

    <?php endif; ?>

</div>


<!-- =========================================================
     Quick Management
========================================================= -->

<div class="doctor-panel mt-4">

    <div class="doctor-panel-header">

        <div>

            <span class="doctor-panel-label">
                MANAGEMENT
            </span>

            <h3>
                Quick Actions
            </h3>

        </div>

    </div>


    <div class="doctor-quick-grid">

        <a href="schedule.php" class="doctor-quick-card">

            <div class="doctor-quick-icon blue">
                <i class="bi bi-calendar-week"></i>
            </div>

            <div>

                <strong>
                    Manage Schedule
                </strong>

                <span>
                    Set recurring availability and slots
                </span>

            </div>

            <i class="bi bi-arrow-up-right"></i>

        </a>


        <a href="breaks.php" class="doctor-quick-card">

            <div class="doctor-quick-icon orange">
                <i class="bi bi-cup-hot"></i>
            </div>

            <div>

                <strong>
                    Manage Breaks
                </strong>

                <span>
                    Configure break periods
                </span>

            </div>

            <i class="bi bi-arrow-up-right"></i>

        </a>


        <a href="leave.php" class="doctor-quick-card">

            <div class="doctor-quick-icon red">
                <i class="bi bi-calendar-x"></i>
            </div>

            <div>

                <strong>
                    Manage Leave
                </strong>

                <span>
                    Add or manage leave days
                </span>

            </div>

            <i class="bi bi-arrow-up-right"></i>

        </a>


        <a href="queue.php" class="doctor-quick-card">

            <div class="doctor-quick-icon purple">
                <i class="bi bi-people"></i>
            </div>

            <div>

                <strong>
                    Patient Queue
                </strong>

                <span>
                    Manage today's waiting queue
                </span>

            </div>

            <i class="bi bi-arrow-up-right"></i>

        </a>

    </div>

</div>


<style>

/* =========================================================
   Doctor Dashboard
========================================================= */

.doctor-dashboard-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 25px;
    margin-bottom: 25px;
}

.doctor-welcome-label {
    color: var(--primary);
    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .12em;
    margin-bottom: 6px;
}

.doctor-dashboard-header h1 {
    margin: 0;
    font-size: clamp(1.8rem, 4vw, 2.7rem);
    font-weight: 800;
    letter-spacing: -.04em;
}

.doctor-dashboard-header p {
    color: var(--muted);
    margin: 8px 0 0;
    max-width: 680px;
}

.doctor-header-actions {
    display: flex;
    gap: 10px;
}


/* =========================================================
   Verification
========================================================= */

.verification-banner {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 18px;
    border-radius: 16px;
    margin-bottom: 22px;
    border: 1px solid;
}

.verification-banner.approved {
    background: #effcf7;
    border-color: #c8f0df;
}

.verification-banner.pending {
    background: #fff9eb;
    border-color: #f5dfaa;
}

.verification-banner.rejected {
    background: #fff1f1;
    border-color: #f3caca;
}

.verification-icon {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-size: 18px;
}

.approved .verification-icon {
    background: #d9f7eb;
    color: #0b9270;
}

.pending .verification-icon {
    background: #ffedc5;
    color: #b97900;
}

.rejected .verification-icon {
    background: #ffdede;
    color: #c53d3d;
}

.verification-content {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
}

.verification-content strong {
    font-size: .88rem;
}

.verification-content span {
    font-size: .75rem;
    color: var(--muted);
}

.verification-action {
    color: var(--primary);
    text-decoration: none;
    font-size: .78rem;
    font-weight: 700;
    white-space: nowrap;
}


/* =========================================================
   Statistics
========================================================= */

.doctor-stat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 22px;
}

.doctor-stat-card {
    display: flex;
    align-items: center;
    gap: 14px;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 19px;
    box-shadow: var(--shadow);
}

.doctor-stat-icon {
    width: 50px;
    height: 50px;
    flex: 0 0 50px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    font-size: 20px;
}

.doctor-stat-icon.blue,
.doctor-quick-icon.blue {
    background: #edf5ff;
    color: #1677ff;
}

.doctor-stat-icon.orange,
.doctor-quick-icon.orange {
    background: #fff6e8;
    color: #d48700;
}

.doctor-stat-icon.green {
    background: #eafaf5;
    color: #0b9877;
}

.doctor-stat-icon.purple,
.doctor-quick-icon.purple {
    background: #f2efff;
    color: #7555d4;
}

.doctor-stat-card span {
    display: block;
    color: var(--muted);
    font-size: .72rem;
    margin-bottom: 2px;
}

.doctor-stat-card strong {
    display: block;
    font-size: 1.65rem;
    line-height: 1.15;
    font-weight: 800;
}

.doctor-stat-card small {
    display: block;
    color: var(--muted);
    font-size: .67rem;
    margin-top: 2px;
}


/* =========================================================
   Panels
========================================================= */

.doctor-panel {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow);
    padding: 22px;
}

.doctor-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 18px;
}

.doctor-panel-label {
    display: block;
    color: var(--primary);
    font-size: .66rem;
    font-weight: 800;
    letter-spacing: .1em;
}

.doctor-panel-header h3 {
    margin: 3px 0 0;
    font-size: 1.18rem;
    font-weight: 800;
}

.doctor-panel-link {
    color: var(--primary);
    text-decoration: none;
    font-size: .78rem;
    font-weight: 700;
}

.doctor-panel-link i {
    margin-left: 3px;
}

.doctor-edit-icon {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: #edf5ff;
    color: var(--primary);
    text-decoration: none;
}


/* =========================================================
   Today's Appointments
========================================================= */

.doctor-appointment-list {
    border-top: 1px solid var(--border);
}

.doctor-appointment-item {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 15px 0;
    border-bottom: 1px solid var(--border);
}

.doctor-appointment-item:last-child {
    border-bottom: 0;
    padding-bottom: 0;
}

.doctor-time {
    width: 72px;
    flex: 0 0 72px;
    text-align: center;
    background: #f4f8fd;
    border-radius: 12px;
    padding: 8px 5px;
}

.doctor-time strong {
    display: block;
    font-size: .95rem;
    font-weight: 800;
}

.doctor-time span {
    color: var(--muted);
    font-size: .62rem;
    font-weight: 700;
}

.doctor-patient-info {
    flex: 1;
    min-width: 0;
}

.patient-name {
    display: flex;
    align-items: center;
    gap: 10px;
}

.patient-avatar,
.small-patient-avatar {
    display: grid;
    place-items: center;
    border-radius: 11px;
    background: #eaf4ff;
    color: var(--primary);
    font-weight: 800;
}

.patient-avatar {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    font-size: .85rem;
}

.patient-name strong {
    display: block;
    font-size: .84rem;
}

.patient-name small {
    display: block;
    color: var(--muted);
    font-size: .68rem;
    margin-top: 2px;
}

.appointment-reference {
    margin-top: 6px;
    color: var(--muted);
    font-size: .65rem;
}

.appointment-reference strong {
    color: var(--navy);
}

.appointment-reference span {
    margin-left: 8px;
    color: var(--primary);
    font-weight: 700;
}

.doctor-appointment-status {
    flex: 0 0 auto;
}

.doctor-status {
    display: inline-flex;
    align-items: center;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: .66rem;
    font-weight: 800;
    white-space: nowrap;
}

.status-requested {
    background: #fff6e5;
    color: #b66d00;
}

.status-confirmed {
    background: #edf5ff;
    color: #1264c7;
}

.status-checkedin {
    background: #e9fbf3;
    color: #087b57;
}

.status-inprogress {
    background: #f1edff;
    color: #694ec1;
}

.status-completed {
    background: #e9fbf3;
    color: #087b57;
}

.status-cancelled,
.status-noshow {
    background: #fff0f0;
    color: #c23d3d;
}

.doctor-action-btn {
    width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--muted);
    text-decoration: none;
}

.doctor-action-btn:hover {
    color: var(--primary);
    border-color: var(--primary);
}


/* =========================================================
   Profile
========================================================= */

.doctor-profile-summary {
    text-align: center;
    padding: 8px 0 20px;
}

.large-doctor-avatar {
    width: 76px;
    height: 76px;
    margin: 0 auto 12px;
    display: grid;
    place-items: center;
    border-radius: 22px;
    background: linear-gradient(
        135deg,
        #e9f3ff,
        #e8faf6
    );
    color: var(--primary);
    font-size: 1.6rem;
    font-weight: 800;
}

.doctor-profile-summary h3 {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 800;
}

.doctor-profile-summary p {
    margin: 4px 0 0;
    color: var(--primary);
    font-size: .76rem;
    font-weight: 600;
}

.doctor-profile-details {
    border-top: 1px solid var(--border);
}

.doctor-profile-details > div {
    display: grid;
    grid-template-columns: 25px 1fr;
    column-gap: 8px;
    padding: 11px 0;
    border-bottom: 1px solid var(--border);
}

.doctor-profile-details i {
    grid-row: span 2;
    color: var(--primary);
    margin-top: 3px;
}

.doctor-profile-details span {
    color: var(--muted);
    font-size: .68rem;
}

.doctor-profile-details strong {
    font-size: .78rem;
    margin-top: 2px;
}


/* =========================================================
   Table
========================================================= */

.doctor-dashboard-table {
    min-width: 720px;
    margin-bottom: 0;
}

.doctor-dashboard-table thead th {
    background: #f7f9fc;
    color: var(--muted);
    font-size: .7rem;
    font-weight: 800;
    border-bottom: 1px solid var(--border);
}

.doctor-dashboard-table tbody td {
    vertical-align: middle;
    font-size: .78rem;
}

.doctor-dashboard-table tbody tr:last-child td {
    border-bottom: 0;
}

.table-patient {
    display: flex;
    align-items: center;
    gap: 9px;
}

.small-patient-avatar {
    width: 32px;
    height: 32px;
    flex: 0 0 32px;
    font-size: .7rem;
}

.reference-text {
    color: var(--primary);
    font-size: .7rem;
    font-weight: 700;
}


/* =========================================================
   Empty
========================================================= */

.doctor-empty-state {
    text-align: center;
    padding: 40px 20px;
    color: var(--muted);
}

.doctor-empty-state.compact {
    padding: 30px 20px;
}

.doctor-empty-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto 12px;
    display: grid;
    place-items: center;
    border-radius: 16px;
    background: #f2f6fa;
    color: var(--primary);
    font-size: 22px;
}

.doctor-empty-state h4 {
    color: var(--navy);
    font-size: .98rem;
    font-weight: 800;
    margin-bottom: 5px;
}

.doctor-empty-state p {
    font-size: .76rem;
    margin: 0;
}


/* =========================================================
   Quick Actions
========================================================= */

.doctor-quick-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
}

.doctor-quick-card {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 14px;
    text-decoration: none;
    color: var(--navy);
    transition: .2s ease;
}

.doctor-quick-card:hover {
    transform: translateY(-2px);
    border-color: #c8dced;
    box-shadow: 0 8px 25px rgba(20,50,80,.08);
}

.doctor-quick-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;
    display: grid;
    place-items: center;
    border-radius: 11px;
}

.doctor-quick-icon.red {
    background: #fff0f0;
    color: #cf4848;
}

.doctor-quick-card div:nth-child(2) {
    flex: 1;
    min-width: 0;
}

.doctor-quick-card strong {
    display: block;
    font-size: .76rem;
    font-weight: 800;
}

.doctor-quick-card span {
    display: block;
    color: var(--muted);
    font-size: .62rem;
    line-height: 1.35;
    margin-top: 2px;
}

.doctor-quick-card > i {
    color: #9aa8b8;
    font-size: .72rem;
}


/* =========================================================
   Responsive
========================================================= */

@media (max-width: 1100px) {

    .doctor-stat-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .doctor-quick-grid {
        grid-template-columns: repeat(2, 1fr);
    }

}

@media (max-width: 800px) {

    .doctor-dashboard-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .doctor-header-actions {
        width: 100%;
    }

    .doctor-header-actions .btn {
        flex: 1;
    }

    .doctor-appointment-item {
        flex-wrap: wrap;
    }

    .doctor-appointment-status {
        margin-left: 87px;
    }

}

@media (max-width: 600px) {

    .doctor-stat-grid {
        grid-template-columns: 1fr;
    }

    .doctor-quick-grid {
        grid-template-columns: 1fr;
    }

    .verification-banner {
        align-items: flex-start;
    }

    .verification-action {
        display: none;
    }

    .doctor-panel {
        padding: 17px;
        border-radius: 16px;
    }

    .doctor-appointment-status {
        width: 100%;
        margin-left: 0;
    }

}

/* Improved Dashboard Text Readability */

/* Verification Banner */
.verification-content strong {
    font-size: 16px;
}

.verification-content span {
    font-size: 14px;
}

.verification-action {
    font-size: 14px;
}

/* Statistics Cards */
.doctor-stat-card span {
    font-size: 14px;
}

.doctor-stat-card strong {
    font-size: 28px;
}

.doctor-stat-card small {
    font-size: 13px;
}

/* Panel Headings */
.doctor-panel-label {
    font-size: 12px;
}

.doctor-panel-header h3 {
    font-size: 21px;
}

.doctor-panel-link {
    font-size: 14px;
}

/* Profile Information */
.doctor-profile-summary h3 {
    font-size: 18px;
}

.doctor-profile-summary p {
    font-size: 14px;
}

.doctor-profile-details span {
    font-size: 14px;
}

.doctor-profile-details strong {
    font-size: 15px;
}

/* Empty State */
.doctor-empty-state h4 {
    font-size: 17px;
}

.doctor-empty-state p {
    font-size: 14px;
}

/* Quick Actions */
.doctor-quick-card strong {
    font-size: 15px;
}

.doctor-quick-card span {
    font-size: 13px;
}

/* Upcoming Appointments Table */
.doctor-dashboard-table thead th {
    font-size: 13px;
}

.doctor-dashboard-table tbody td {
    font-size: 14px;
}

.reference-text {
    font-size: 13px;
}

</style>

<?php require '../includes/footer.php'; ?>