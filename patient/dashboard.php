<?php

$page_title = 'Patient Dashboard';
$asset_prefix = '../';

require '../includes/header.php';
require_role('patient');

$patient_id = get_patient_id((int)$_SESSION['user_id']);

if (!$patient_id) {

    echo '
        <div class="container py-5">
            <div class="alert alert-danger">
                Patient profile not found.
            </div>
        </div>
    ';

    require '../includes/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Dashboard Statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'upcoming'  => 0,
    'completed' => 0,
    'cancelled' => 0,
    'total'     => 0,
    'favorites' => 0,
    'unread'    => 0
];

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$favoritesCollection = $mongoDb->selectCollection('favorites');
$notificationsCollection = $mongoDb->selectCollection('notifications');

$nowDate = date('Y-m-d');
$nowTime = date('H:i:s');

$activeStatuses = [
    'Cancelled',
    'Completed',
    'No-show'
];

/* Appointment statistics */
$patientAppointments = $appointmentsCollection->find([
    'patient_id' => (int)$patient_id
])->toArray();

foreach ($patientAppointments as $appointment) {
    $status = $appointment['status'] ?? '';
    $date = (string)($appointment['appointment_date'] ?? '');
    $time = (string)($appointment['start_time'] ?? '');

    $stats['total']++;

    if ($status === 'Completed') {
        $stats['completed']++;
    }

    if ($status === 'Cancelled') {
        $stats['cancelled']++;
    }

    if (
        !in_array($status, $activeStatuses, true) &&
        ($date > $nowDate || ($date === $nowDate && $time >= $nowTime))
    ) {
        $stats['upcoming']++;
    }
}

/* Favorite doctors count */
$stats['favorites'] = $favoritesCollection->countDocuments([
    'patient_id' => (int)$patient_id
]);

/* Unread notifications count */
$stats['unread'] = $notificationsCollection->countDocuments([
    'user_id' => (int)$_SESSION['user_id'],
    'is_read' => ['$in' => [0, '0', false]]
]);

/* Appointment lists: doctor details are joined in the next step */
$next_appointment = null;
$upcoming_appointments = [];
$favorite_doctors = [];
$recent_notifications = [];
$recent_appointments = [];

/*
|--------------------------------------------------------------------------
| Load Favorite Doctors
|--------------------------------------------------------------------------
*/

$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');
$specializationsCollection = $mongoDb->selectCollection('specializations');
$reviewsCollection = $mongoDb->selectCollection('reviews');

$favoriteRecords = $favoritesCollection->find(
    ['patient_id' => (int) $patient_id],
    ['sort' => ['created_at' => -1]]
);

foreach ($favoriteRecords as $favorite) {
    $favoriteDoctorId = (int) ($favorite['doctor_id'] ?? 0);

    if ($favoriteDoctorId <= 0) {
        continue;
    }

    $doctorDoc = $doctorsCollection->findOne([
        'doctor_id' => $favoriteDoctorId,
        'verification_status' => 'Approved'
    ]);

    if (!$doctorDoc) {
        continue;
    }

    $doctorUser = $usersCollection->findOne([
        'user_id' => (int) ($doctorDoc['user_id'] ?? 0),
        'status' => 'active'
    ]);

    if (!$doctorUser) {
        continue;
    }

    $specializationName = 'General';
    $specializationId = $doctorDoc['specialization_id'] ?? null;

    if ($specializationId !== null) {
        $specialization = $specializationsCollection->findOne([
            'specialization_id' => $specializationId
        ]);

        if ($specialization) {
            $specializationName = (string) (
                $specialization['name'] ?? 'General'
            );
        }
    }

    $doctorReviews = $reviewsCollection->find([
        'doctor_id' => $favoriteDoctorId
    ]);

    $ratingTotal = 0;
    $reviewCount = 0;

    foreach ($doctorReviews as $review) {
        $rating = $review['rating'] ?? null;

        if (is_numeric($rating) || $rating instanceof MongoDB\BSON\Decimal128) {
            $ratingTotal += (float) (string) $rating;
            $reviewCount++;
        }
    }

    $favorite_doctors[] = [
        'doctor_id' => $favoriteDoctorId,
        'doctor_name' => (string) ($doctorUser['name'] ?? 'Unknown Doctor'),
        'specialization' => $specializationName,
        'average_rating' => $reviewCount > 0
            ? $ratingTotal / $reviewCount
            : 0,
        'review_count' => $reviewCount
    ];
}


/*
|--------------------------------------------------------------------------
| Notification Icon Helper
|--------------------------------------------------------------------------
*/

function dashboard_notification_icon(string $type): string
{
    return match (strtolower($type)) {

        'appointment' =>
            'bi-calendar-check',

        'cancellation' =>
            'bi-calendar-x',

        'reschedule' =>
            'bi-calendar-event',

        'reminder' =>
            'bi-bell',

        'queue' =>
            'bi-people',

        'waitlist' =>
            'bi-hourglass-split',

        'review' =>
            'bi-star',

        default =>
            'bi-info-circle'
    };
}


/*
|--------------------------------------------------------------------------
| Status CSS Helper
|--------------------------------------------------------------------------
*/

function dashboard_status_class(string $status): string
{
    return match ($status) {

        'Requested' =>
            'status-requested',

        'Confirmed' =>
            'status-confirmed',

        'Checked-in' =>
            'status-checkedin',

        'In Progress' =>
            'status-inprogress',

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

?>


<!-- =========================================================
     Dashboard Header
========================================================= -->

<div class="patient-dashboard-header">

    <div>

        <div class="mini-label mb-2">
            Patient Portal
        </div>

        <h1 class="dashboard-title">
            Welcome back, <?= h($_SESSION['name']) ?>
        </h1>

        <p class="dashboard-subtitle">
            Manage appointments, follow your visits and quickly
            access your favorite doctors.
        </p>

    </div>


    <div class="dashboard-header-actions">

        <a
            href="doctors.php"
            class="btn btn-primary px-4"
        >
            <i class="bi bi-search me-2"></i>
            Find a Doctor
        </a>

    </div>

</div>


<!-- =========================================================
     Statistics
========================================================= -->

<div class="dashboard-grid patient-stats mb-4">


    <!-- Upcoming -->

    <div class="patient-stat-card">

        <div class="patient-stat-icon blue">

            <i class="bi bi-calendar-check"></i>

        </div>

        <div>

            <div class="mini-label">
                Upcoming
            </div>

            <div class="patient-stat-number">
                <?= $stats['upcoming'] ?>
            </div>

            <div class="patient-stat-text">
                Active appointments
            </div>

        </div>

    </div>


    <!-- Completed -->

    <div class="patient-stat-card">

        <div class="patient-stat-icon green">

            <i class="bi bi-check-circle"></i>

        </div>

        <div>

            <div class="mini-label">
                Completed
            </div>

            <div class="patient-stat-number">
                <?= $stats['completed'] ?>
            </div>

            <div class="patient-stat-text">
                Completed visits
            </div>

        </div>

    </div>


    <!-- Favorites -->

    <div class="patient-stat-card">

        <div class="patient-stat-icon pink">

            <i class="bi bi-heart-fill"></i>

        </div>

        <div>

            <div class="mini-label">
                Favorites
            </div>

            <div class="patient-stat-number">
                <?= $stats['favorites'] ?>
            </div>

            <div class="patient-stat-text">
                Saved doctors
            </div>

        </div>

    </div>


    <!-- Notifications -->

    <div class="patient-stat-card">

        <div class="patient-stat-icon orange">

            <i class="bi bi-bell"></i>

        </div>

        <div>

            <div class="mini-label">
                Notifications
            </div>

            <div class="patient-stat-number">
                <?= $stats['unread'] ?>
            </div>

            <div class="patient-stat-text">
                Unread updates
            </div>

        </div>

    </div>

</div>


<!-- =========================================================
     Next Appointment
========================================================= -->

<?php if ($next_appointment): ?>

<div class="next-appointment-panel mb-4">

    <div class="next-appointment-left">

        <div class="next-appointment-icon">

            <i class="bi bi-calendar2-check"></i>

        </div>

        <div>

            <div class="next-label">
                NEXT APPOINTMENT
            </div>

            <h2>
                Dr. <?= h($next_appointment['doctor_name']) ?>
            </h2>

            <div class="next-specialization">

                <?= h(
                    $next_appointment['specialization']
                    ?? 'General'
                ) ?>

            </div>

        </div>

    </div>


    <div class="next-appointment-details">

        <div class="next-detail">

            <i class="bi bi-calendar3"></i>

            <div>

                <small>Date</small>

                <strong>
                    <?= date(
                        'd M Y',
                        strtotime(
                            $next_appointment['appointment_date']
                        )
                    ) ?>
                </strong>

            </div>

        </div>


        <div class="next-detail">

            <i class="bi bi-clock"></i>

            <div>

                <small>Time</small>

                <strong>
                    <?= date(
                        'h:i A',
                        strtotime(
                            $next_appointment['start_time']
                        )
                    ) ?>
                </strong>

            </div>

        </div>


        <div class="next-detail">

            <i class="bi bi-hospital"></i>

            <div>

                <small>Location</small>

                <strong>

                    <?= h(
                        $next_appointment['clinic_name']
                        ?? 'Clinic'
                    ) ?>

                </strong>

                <span>
                    <?= h(
                        $next_appointment['branch_name']
                        ?? ''
                    ) ?>
                </span>

            </div>

        </div>

    </div>


    <div class="next-appointment-actions">

        <?php if (
            in_array(
                $next_appointment['status'],
                ['Checked-in', 'In Progress'],
                true
            )
        ): ?>

            <a
                href="queue.php?id=<?= (int)$next_appointment['appointment_id'] ?>"
                class="btn btn-success"
            >
                <i class="bi bi-people me-1"></i>
                View Queue
            </a>

        <?php elseif (
            in_array(
                $next_appointment['status'],
                ['Requested', 'Confirmed'],
                true
            )
        ): ?>

            <a
                href="reschedule.php?id=<?= (int)$next_appointment['appointment_id'] ?>"
                class="btn btn-outline-primary"
            >
                <i class="bi bi-calendar-event me-1"></i>
                Reschedule
            </a>

        <?php endif; ?>

        <a
            href="appointments.php"
            class="btn btn-light"
        >
            View Details
        </a>

    </div>

</div>

<?php endif; ?>


<!-- =========================================================
     Main Dashboard
========================================================= -->

<div class="row g-4">


    <!-- =====================================================
         Upcoming Appointments
    ====================================================== -->

    <div class="col-xl-12">

        <div class="dashboard-panel">

            <div class="panel-header">

                <div>

                    <div class="mini-label">
                        Schedule
                    </div>

                    <h3 class="panel-title">
                        Upcoming Appointments
                    </h3>

                </div>

                <a
                    href="appointments.php"
                    class="panel-link"
                >
                    View all
                    <i class="bi bi-arrow-right"></i>
                </a>

            </div>


            <?php if ($upcoming_appointments): ?>

                <div class="appointment-list">

                    <?php foreach (
                        $upcoming_appointments
                        as $appointment
                    ): ?>

                        <div class="appointment-item">


                            <!-- Date -->

                            <div class="appointment-date">

                                <div class="appointment-month">

                                    <?= strtoupper(
                                        date(
                                            'M',
                                            strtotime(
                                                $appointment['appointment_date']
                                            )
                                        )
                                    ) ?>

                                </div>

                                <div class="appointment-day">

                                    <?= date(
                                        'd',
                                        strtotime(
                                            $appointment['appointment_date']
                                        )
                                    ) ?>

                                </div>

                                <div class="appointment-weekday">

                                    <?= date(
                                        'D',
                                        strtotime(
                                            $appointment['appointment_date']
                                        )
                                    ) ?>

                                </div>

                            </div>


                            <!-- Information -->

                            <div class="appointment-info">

                                <div class="appointment-doctor">

                                    Dr.
                                    <?= h(
                                        $appointment['doctor_name']
                                    ) ?>

                                </div>

                                <div class="appointment-specialization">

                                    <?= h(
                                        $appointment['specialization']
                                        ?? 'General'
                                    ) ?>

                                </div>

                                <div class="appointment-meta">

                                    <span>

                                        <i class="bi bi-clock"></i>

                                        <?= date(
                                            'h:i A',
                                            strtotime(
                                                $appointment['start_time']
                                            )
                                        ) ?>

                                    </span>


                                    <?php if (
                                        !empty(
                                            $appointment['clinic_name']
                                        )
                                    ): ?>

                                        <span>

                                            <i class="bi bi-hospital"></i>

                                            <?= h(
                                                $appointment['clinic_name']
                                            ) ?>

                                        </span>

                                    <?php endif; ?>

                                </div>

                            </div>


                            <!-- Status -->

                            <div class="appointment-status">

                                <span
                                    class="status-badge <?= dashboard_status_class(
                                        $appointment['status']
                                    ) ?>"
                                >
                                    <?= h(
                                        $appointment['status']
                                    ) ?>
                                </span>


                                <?php if (
                                    !empty(
                                        $appointment['token_no']
                                    )
                                ): ?>

                                    <small class="token-number">

                                        Token #
                                        <?= h(
                                            $appointment['token_no']
                                        ) ?>

                                    </small>

                                <?php endif; ?>


                                <?php if (
                                    in_array(
                                        $appointment['status'],
                                        ['Checked-in', 'In Progress'],
                                        true
                                    )
                                ): ?>

                                    <a
                                        href="queue.php?id=<?= (int)$appointment['appointment_id'] ?>"
                                        class="btn btn-sm btn-outline-success"
                                    >
                                        Queue
                                    </a>

                                <?php elseif (
                                    in_array(
                                        $appointment['status'],
                                        ['Requested', 'Confirmed'],
                                        true
                                    )
                                ): ?>

                                    <a
                                        href="reschedule.php?id=<?= (int)$appointment['appointment_id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        Reschedule
                                    </a>

                                <?php endif; ?>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="empty-dashboard">

                    <div class="empty-icon">

                        <i class="bi bi-calendar2-x"></i>

                    </div>

                    <h4>
                        No upcoming appointments
                    </h4>

                    <p>
                        You don't have any active appointments right now.
                    </p>

                    <a
                        href="doctors.php"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-search me-1"></i>
                        Find a Doctor
                    </a>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         Quick Actions
    ====================================================== -->

   

<!-- =========================================================
     Favorites + Notifications
========================================================= -->

<div class="row g-4 mt-1">


    <!-- =====================================================
         Favorite Doctors
    ====================================================== -->

    <div class="col-xl-7">

        <div class="dashboard-panel">

            <div class="panel-header">

                <div>

                    <div class="mini-label">
                        Saved Doctors
                    </div>

                    <h3 class="panel-title">
                        Favorite Doctors
                    </h3>

                </div>

                <a
                    href="favorites.php"
                    class="panel-link"
                >
                    View all
                    <i class="bi bi-arrow-right"></i>
                </a>

            </div>


            <?php if ($favorite_doctors): ?>

                <div class="favorite-preview-list">

                    <?php foreach (
                        $favorite_doctors
                        as $doctor
                    ): ?>

                        <?php
                        $doctor_rating = round(
                            (float)$doctor['average_rating'],
                            1
                        );
                        ?>

                        <div class="favorite-preview-item">


                            <div class="favorite-preview-avatar">

                                <i class="bi bi-person-fill"></i>

                            </div>


                            <div class="favorite-preview-info">

                                <strong>

                                    Dr.
                                    <?= h(
                                        $doctor['doctor_name']
                                    ) ?>

                                </strong>

                                <span>

                                    <?= h(
                                        $doctor['specialization']
                                        ?? 'General'
                                    ) ?>

                                </span>


                                <div class="favorite-rating">

                                    <i class="bi bi-star-fill"></i>

                                    <?= number_format(
                                        $doctor_rating,
                                        1
                                    ) ?>

                                    <small>
                                        (<?= (int)$doctor['review_count'] ?>)
                                    </small>

                                </div>

                            </div>


                            <a
                                href="book.php?doctor_id=<?= (int)$doctor['doctor_id'] ?>"
                                class="btn btn-sm btn-outline-primary"
                            >
                                <i class="bi bi-calendar-plus"></i>
                                Quick Book
                            </a>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="small-empty-state">

                    <div class="small-empty-icon">

                        <i class="bi bi-heart"></i>

                    </div>

                    <div>

                        <strong>
                            No favorite doctors yet
                        </strong>

                        <p>
                            Save doctors you may want to visit again.
                        </p>

                        <a
                            href="doctors.php"
                            class="btn btn-sm btn-outline-primary"
                        >
                            Find Doctors
                        </a>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>


    <!-- =====================================================
         Notifications
    ====================================================== -->

    <div class="col-xl-5">

        <div class="dashboard-panel">

            <div class="panel-header">

                <div>

                    <div class="mini-label">
                        Updates
                    </div>

                    <h3 class="panel-title">
                        Recent Notifications
                    </h3>

                </div>

                <a
                    href="notifications.php"
                    class="panel-link"
                >
                    View all
                </a>

            </div>


            <?php if ($recent_notifications): ?>

                <div class="notification-preview-list">

                    <?php foreach (
                        $recent_notifications
                        as $notification
                    ): ?>

                        <a
                            href="notifications.php"
                            class="notification-preview-item <?= empty($notification['is_read']) ? 'unread' : '' ?>"
                        >

                            <div class="notification-preview-icon">

                                <i class="bi <?= h(
                                    dashboard_notification_icon(
                                        (string)$notification['type']
                                    )
                                ) ?>"></i>

                            </div>


                            <div class="notification-preview-content">

                                <div class="notification-preview-title">

                                    <?= h(
                                        $notification['title']
                                    ) ?>

                                </div>

                                <div class="notification-preview-message">

                                    <?= h(
                                        $notification['message']
                                    ) ?>

                                </div>

                                <small>

                                    <?= date(
                                        'd M, h:i A',
                                        strtotime(
                                            $notification['created_at']
                                        )
                                    ) ?>

                                </small>

                            </div>


                            <?php if (
                                empty(
                                    $notification['is_read']
                                )
                            ): ?>

                                <span class="unread-dot"></span>

                            <?php endif; ?>

                        </a>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="small-empty-state">

                    <div class="small-empty-icon">

                        <i class="bi bi-bell-slash"></i>

                    </div>

                    <div>

                        <strong>
                            No notifications
                        </strong>

                        <p>
                            New appointment updates will appear here.
                        </p>

                    </div>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- =========================================================
     Recent Appointment History
========================================================= -->

<div class="dashboard-panel mt-4">

    <div class="panel-header">

        <div>

            <div class="mini-label">
                History
            </div>

            <h3 class="panel-title">
                Recent Appointments
            </h3>

        </div>

        <a
            href="appointments.php"
            class="panel-link"
        >
            View all
            <i class="bi bi-arrow-right"></i>
        </a>

    </div>


    <?php if ($recent_appointments): ?>

        <div class="table-responsive">

            <table class="table dashboard-table mb-0">

                <thead>

                    <tr>

                        <th>
                            Doctor
                        </th>

                        <th>
                            Specialization
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

                        <th>
                            Action
                        </th>

                    </tr>

                </thead>


                <tbody>

                    <?php foreach (
                        $recent_appointments
                        as $appointment
                    ): ?>

                        <tr>

                            <td>

                                <strong>
                                    Dr.
                                    <?= h(
                                        $appointment['doctor_name']
                                    ) ?>
                                </strong>

                            </td>


                            <td>

                                <?= h(
                                    $appointment['specialization']
                                    ?? 'General'
                                ) ?>

                            </td>


                            <td>

                                <?= date(
                                    'd M Y',
                                    strtotime(
                                        $appointment['appointment_date']
                                    )
                                ) ?>

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

                                <span
                                    class="status-badge <?= dashboard_status_class(
                                        $appointment['status']
                                    ) ?>"
                                >
                                    <?= h(
                                        $appointment['status']
                                    ) ?>
                                </span>

                            </td>


                            <td>

                                <?php if (
                                    $appointment['status']
                                    === 'Completed'
                                ): ?>

                                    <a
                                        href="book.php?doctor_id=<?= (int)$appointment['doctor_id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        <i class="bi bi-arrow-repeat"></i>
                                        Book Again
                                    </a>

                                <?php elseif (
                                    in_array(
                                        $appointment['status'],
                                        ['Requested', 'Confirmed'],
                                        true
                                    )
                                ): ?>

                                    <a
                                        href="reschedule.php?id=<?= (int)$appointment['appointment_id'] ?>"
                                        class="btn btn-sm btn-outline-primary"
                                    >
                                        Reschedule
                                    </a>

                                <?php else: ?>

                                    <a
                                        href="appointments.php"
                                        class="btn btn-sm btn-light"
                                    >
                                        Details
                                    </a>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                </tbody>

            </table>

        </div>

    <?php else: ?>

        <div class="empty-dashboard small-empty">

            <div class="empty-icon">

                <i class="bi bi-clipboard"></i>

            </div>

            <p class="mb-0">
                No appointment history available yet.
            </p>

        </div>

    <?php endif; ?>

</div>


<style>

/*
|--------------------------------------------------------------------------
| Dashboard Header
|--------------------------------------------------------------------------
*/

.patient-dashboard-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 25px;

    margin-bottom: 30px;

}

.dashboard-title {

    margin: 0;

    font-size: clamp(
        1.8rem,
        3vw,
        2.6rem
    );

    font-weight: 800;

    letter-spacing: -.04em;

    color: var(--navy);

}

.dashboard-subtitle {

    margin: 8px 0 0;

    color: var(--muted);

    max-width: 680px;

}


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

.patient-stats {

    grid-template-columns:
        repeat(4, 1fr);

}

.patient-stat-card {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 18px;

    padding: 22px;

    display: flex;

    align-items: center;

    gap: 16px;

    box-shadow: var(--shadow);

    transition:
        transform .2s ease,
        box-shadow .2s ease;

}

.patient-stat-card:hover {

    transform: translateY(-2px);

    box-shadow:
        0 12px 30px
        rgba(15, 23, 42, .08);

}

.patient-stat-icon {

    width: 52px;

    height: 52px;

    flex: 0 0 52px;

    border-radius: 15px;

    display: grid;

    place-items: center;

    font-size: 21px;

}

.patient-stat-icon.blue {

    background: #edf5ff;

    color: #1677ff;

}

.patient-stat-icon.green {

    background: #ecfbf7;

    color: #0f9f8f;

}

.patient-stat-icon.pink {

    background: #fff1f5;

    color: #e11d48;

}

.patient-stat-icon.orange {

    background: #fff6e8;

    color: #d98700;

}

.patient-stat-number {

    font-size: 1.8rem;

    line-height: 1.1;

    font-weight: 800;

    margin-top: 3px;

}

.patient-stat-text {

    color: var(--muted);

    font-size: .78rem;

    margin-top: 3px;

}


/*
|--------------------------------------------------------------------------
| Next Appointment
|--------------------------------------------------------------------------
*/

.next-appointment-panel {

    background:
        linear-gradient(
            135deg,
            #eff6ff 0%,
            #ffffff 55%,
            #f8fafc 100%
        );

    border: 1px solid #dbeafe;

    border-radius: 20px;

    padding: 22px;

    display: flex;

    align-items: center;

    gap: 22px;

    box-shadow:
        0 8px 25px
        rgba(37, 99, 235, .06);

}

.next-appointment-left {

    display: flex;

    align-items: center;

    gap: 14px;

    min-width: 230px;

}

.next-appointment-icon {

    width: 54px;

    height: 54px;

    border-radius: 15px;

    background: #2563eb;

    color: #fff;

    display: grid;

    place-items: center;

    font-size: 1.35rem;

}

.next-label {

    font-size: .68rem;

    letter-spacing: .08em;

    font-weight: 800;

    color: #2563eb;

}

.next-appointment-left h2 {

    font-size: 1.05rem;

    font-weight: 800;

    margin: 2px 0;

    color: #0f172a;

}

.next-specialization {

    font-size: .78rem;

    color: #64748b;

}

.next-appointment-details {

    flex: 1;

    display: flex;

    gap: 24px;

}

.next-detail {

    display: flex;

    align-items: flex-start;

    gap: 8px;

    min-width: 100px;

}

.next-detail > i {

    color: #2563eb;

    margin-top: 2px;

}

.next-detail small {

    display: block;

    color: #94a3b8;

    font-size: .68rem;

}

.next-detail strong {

    display: block;

    color: #334155;

    font-size: .78rem;

}

.next-detail span {

    display: block;

    color: #64748b;

    font-size: .68rem;

}

.next-appointment-actions {

    display: flex;

    gap: 8px;

    flex-wrap: wrap;

}

@media (max-width: 1100px) {

    .next-appointment-panel {

        flex-wrap: wrap;

    }

    .next-appointment-details {

        width: 100%;

        flex-wrap: wrap;

    }

}


/*
|--------------------------------------------------------------------------
| Panels
|--------------------------------------------------------------------------
*/

.dashboard-panel {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 20px;

    box-shadow: var(--shadow);

    padding: 24px;

}

.panel-header {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    margin-bottom: 18px;

}

.panel-title {

    margin: 3px 0 0;

    font-size: 1.25rem;

    font-weight: 800;

}

.panel-link {

    text-decoration: none;

    color: var(--primary);

    font-size: .9rem;

    font-weight: 700;

}

.panel-link:hover {

    color: var(--primary-dark);

}


/*
|--------------------------------------------------------------------------
| Appointment List
|--------------------------------------------------------------------------
*/

.appointment-list {

    border-top: 1px solid var(--border);

}

.appointment-item {

    display: flex;

    align-items: center;

    gap: 18px;

    padding: 18px 0;

    border-bottom: 1px solid var(--border);

}

.appointment-item:last-child {

    border-bottom: 0;

    padding-bottom: 2px;

}

.appointment-date {

    width: 68px;

    flex: 0 0 68px;

    text-align: center;

    background: #f5f9ff;

    border: 1px solid #e4edf8;

    border-radius: 14px;

    padding: 8px 5px;

}

.appointment-month {

    color: var(--primary);

    font-size: .68rem;

    font-weight: 800;

}

.appointment-day {

    font-size: 1.45rem;

    line-height: 1.2;

    font-weight: 800;

}

.appointment-weekday {

    color: var(--muted);

    font-size: .68rem;

    font-weight: 700;

}

.appointment-info {

    min-width: 0;

    flex: 1;

}

.appointment-doctor {

    font-size: 1rem;

    font-weight: 800;

}

.appointment-specialization {

    color: var(--primary);

    font-size: .84rem;

    font-weight: 600;

    margin-top: 2px;

}

.appointment-meta {

    display: flex;

    flex-wrap: wrap;

    gap: 13px;

    margin-top: 8px;

    color: var(--muted);

    font-size: .78rem;

}

.appointment-meta i {

    margin-right: 3px;

}

.appointment-status {

    display: flex;

    flex-direction: column;

    align-items: flex-end;

    gap: 6px;

}


/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

.status-badge {

    display: inline-flex;

    align-items: center;

    padding: 6px 10px;

    border-radius: 999px;

    font-size: .72rem;

    font-weight: 800;

    white-space: nowrap;

}

.status-requested {

    background: #fff7e6;

    color: #b76b00;

}

.status-confirmed {

    background: #eaf4ff;

    color: #1264c7;

}

.status-checkedin {

    background: #eafbf3;

    color: #138052;

}

.status-inprogress,
.status-inprogress {

    background: #f0edff;

    color: #684cc0;

}

.status-completed {

    background: #eafbf3;

    color: #138052;

}

.status-cancelled,
.status-noshow {

    background: #fff0f0;

    color: #c43f3f;

}

.status-default {

    background: #f1f5f9;

    color: #475569;

}

.token-number {

    color: var(--muted);

    font-size: .72rem;

}


/*
|--------------------------------------------------------------------------
| Quick Actions
|--------------------------------------------------------------------------
*/

.quick-panel {

    height: 100%;

}

.quick-action {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 13px 0;

    border-bottom: 1px solid var(--border);

    text-decoration: none;

    color: var(--navy);

}

.quick-action:last-child {

    border-bottom: 0;

}

.quick-action:hover strong {

    color: var(--primary);

}

.quick-action-icon {

    width: 42px;

    height: 42px;

    flex: 0 0 42px;

    border-radius: 12px;

    display: grid;

    place-items: center;

}

.quick-action-icon.blue {

    background: #edf5ff;

    color: #1677ff;

}

.quick-action-icon.green {

    background: #ecfbf7;

    color: #0f9f8f;

}

.quick-action-icon.pink {

    background: #fff1f5;

    color: #e11d48;

}

.quick-action-icon.orange {

    background: #fff6e8;

    color: #d98700;

}

.quick-action div:nth-child(2) {

    flex: 1;

}

.quick-action strong {

    display: block;

    font-size: .9rem;

}

.quick-action span {

    display: block;

    color: var(--muted);

    font-size: .73rem;

    margin-top: 2px;

}

.quick-action > i {

    color: #9aa7b7;

    font-size: .8rem;

}

.notification-count {

    min-width: 22px;

    height: 22px;

    padding: 0 6px;

    border-radius: 999px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    background: #e11d48;

    color: #fff;

    font-size: .65rem;

    font-weight: 800;

}


/*
|--------------------------------------------------------------------------
| Favorite Preview
|--------------------------------------------------------------------------
*/

.favorite-preview-list {

    border-top: 1px solid var(--border);

}

.favorite-preview-item {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 13px 0;

    border-bottom: 1px solid var(--border);

}

.favorite-preview-item:last-child {

    border-bottom: 0;

}

.favorite-preview-avatar {

    width: 44px;

    height: 44px;

    min-width: 44px;

    border-radius: 12px;

    display: grid;

    place-items: center;

    background: #eff6ff;

    color: #2563eb;

}

.favorite-preview-info {

    flex: 1;

    min-width: 0;

}

.favorite-preview-info strong {

    display: block;

    font-size: .84rem;

    color: #0f172a;

}

.favorite-preview-info > span {

    display: block;

    color: #64748b;

    font-size: .72rem;

    margin-top: 2px;

}

.favorite-rating {

    margin-top: 4px;

    color: #d97706;

    font-size: .72rem;

}

.favorite-rating i {

    color: #f59e0b;

}

.favorite-rating small {

    color: #94a3b8;

}


/*
|--------------------------------------------------------------------------
| Notification Preview
|--------------------------------------------------------------------------
*/

.notification-preview-list {

    border-top: 1px solid var(--border);

}

.notification-preview-item {

    display: flex;

    align-items: flex-start;

    gap: 10px;

    padding: 12px 0;

    border-bottom: 1px solid var(--border);

    text-decoration: none;

    color: inherit;

    position: relative;

}

.notification-preview-item:last-child {

    border-bottom: 0;

}

.notification-preview-item.unread {

    background: #f8fbff;

    margin-left: -.5rem;

    margin-right: -.5rem;

    padding-left: .5rem;

    padding-right: .5rem;

    border-radius: 10px;

}

.notification-preview-icon {

    width: 34px;

    height: 34px;

    min-width: 34px;

    border-radius: 10px;

    background: #eff6ff;

    color: #2563eb;

    display: grid;

    place-items: center;

    font-size: .82rem;

}

.notification-preview-content {

    flex: 1;

    min-width: 0;

}

.notification-preview-title {

    font-size: .78rem;

    font-weight: 700;

    color: #334155;

}

.notification-preview-message {

    font-size: .7rem;

    color: #64748b;

    margin-top: 2px;

    display: -webkit-box;

    -webkit-line-clamp: 2;

    -webkit-box-orient: vertical;

    overflow: hidden;

}

.notification-preview-content small {

    display: block;

    color: #94a3b8;

    font-size: .63rem;

    margin-top: 4px;

}

.unread-dot {

    width: 7px;

    height: 7px;

    min-width: 7px;

    border-radius: 50%;

    background: #2563eb;

    margin-top: 5px;

}


/*
|--------------------------------------------------------------------------
| Empty States
|--------------------------------------------------------------------------
*/

.empty-dashboard {

    text-align: center;

    padding: 42px 20px;

    color: var(--muted);

}

.empty-dashboard.small-empty {

    padding: 28px 20px;

}

.empty-icon {

    width: 58px;

    height: 58px;

    margin: 0 auto 12px;

    border-radius: 16px;

    display: grid;

    place-items: center;

    background: #f1f6fb;

    color: var(--primary);

    font-size: 23px;

}

.empty-dashboard h4 {

    color: var(--navy);

    font-size: 1rem;

    font-weight: 800;

}

.empty-dashboard p {

    font-size: .86rem;

}

.small-empty-state {

    display: flex;

    align-items: center;

    gap: 12px;

    padding: 22px 0;

    color: #64748b;

}

.small-empty-icon {

    width: 45px;

    height: 45px;

    min-width: 45px;

    border-radius: 12px;

    background: #f8fafc;

    color: #64748b;

    display: grid;

    place-items: center;

}

.small-empty-state strong {

    display: block;

    color: #334155;

    font-size: .82rem;

}

.small-empty-state p {

    margin: 3px 0 8px;

    font-size: .7rem;

}


/*
|--------------------------------------------------------------------------
| Table
|--------------------------------------------------------------------------
*/

.dashboard-table {

    min-width: 760px;

}

.dashboard-table thead th {

    background: #f8fafc;

    border-bottom: 1px solid var(--border);

    font-size: .75rem;

    color: #64748b;

    white-space: nowrap;

}

.dashboard-table tbody td {

    vertical-align: middle;

    font-size: .8rem;

}

.dashboard-table tbody tr:last-child td {

    border-bottom: 0;

}


/*
|--------------------------------------------------------------------------
| Responsive
|--------------------------------------------------------------------------
*/

@media (max-width: 1100px) {

    .patient-stats {

        grid-template-columns:
            repeat(2, 1fr);

    }

}

@media (max-width: 768px) {

    .patient-dashboard-header {

        flex-direction: column;

        align-items: flex-start;

    }

    .dashboard-header-actions {

        width: 100%;

    }

    .dashboard-header-actions .btn {

        width: 100%;

    }

    .next-appointment-panel {

        align-items: flex-start;

        flex-direction: column;

    }

    .next-appointment-left {

        width: 100%;

    }

    .next-appointment-details {

        width: 100%;

    }

    .next-appointment-actions {

        width: 100%;

    }

    .next-appointment-actions .btn {

        flex: 1;

    }

    .appointment-item {

        align-items: flex-start;

    }

    .appointment-status {

        align-items: flex-start;

    }

}

@media (max-width: 576px) {

    .patient-stats {

        grid-template-columns: 1fr;

    }

    .dashboard-panel {

        padding: 18px;

        border-radius: 16px;

    }

    .next-appointment-panel {

        padding: 18px;

    }

    .next-appointment-details {

        flex-direction: column;

        gap: 12px;

    }

    .next-appointment-actions {

        flex-direction: column;

    }

    .next-appointment-actions .btn {

        width: 100%;

        flex: none;

    }

    .appointment-item {

        flex-wrap: wrap;

    }

    .appointment-status {

        width: 100%;

        margin-left: 86px;

    }

    .favorite-preview-item {

        flex-wrap: wrap;

    }

    .favorite-preview-item .btn {

        width: 100%;

    }

}

/* Patient Dashboard - Improve Text Readability */

/* Dashboard Header */
.dashboard-title {
    font-size: clamp(2rem, 3vw, 2.7rem);
}

.dashboard-subtitle {
    font-size: 17px;
    line-height: 1.6;
}

/* Section Labels */
.mini-label {
    font-size: 13px;
}

/* Panel Headings */
.panel-title {
    font-size: 22px;
}

.panel-link {
    font-size: 16px;
}

/* Statistics Cards */
.patient-stat-number {
    font-size: 30px;
}

.patient-stat-text {
    font-size: 14px;
}

/* Next Appointment */
.next-label {
    font-size: 12px;
}

.next-appointment-left h2 {
    font-size: 20px;
}

.next-specialization {
    font-size: 15px;
}

.next-detail small {
    font-size: 13px;
}

.next-detail strong {
    font-size: 15px;
}

.next-detail span {
    font-size: 13px;
}

/* Appointment List */
.appointment-month,
.appointment-weekday {
    font-size: 13px;
}

.appointment-day {
    font-size: 25px;
}

.appointment-doctor {
    font-size: 17px;
}

.appointment-specialization {
    font-size: 15px;
}

.appointment-meta {
    font-size: 14px;
}

.status-badge {
    font-size: 13px;
}

.token-number {
    font-size: 13px;
}

/* Quick Access */
.quick-action strong {
    font-size: 16px;
}

.quick-action span {
    font-size: 14px;
}

/* Favorite Doctors */
.favorite-preview-info strong {
    font-size: 16px;
}

.favorite-preview-info > span {
    font-size: 14px;
}

.favorite-rating {
    font-size: 14px;
}

.favorite-rating small {
    font-size: 13px;
}

/* Recent Notifications */
.notification-preview-title {
    font-size: 15px;
}

.notification-preview-message {
    font-size: 14px;
    line-height: 1.5;
}

.notification-preview-content small {
    font-size: 13px;
}

/* Empty States */
.empty-dashboard h4 {
    font-size: 18px;
}

.empty-dashboard p {
    font-size: 15px;
    line-height: 1.6;
}

.small-empty-state strong {
    font-size: 15px;
}

.small-empty-state p {
    font-size: 14px;
    line-height: 1.5;
}

/* Appointment History Table */
.dashboard-table thead th {
    font-size: 14px;
}

.dashboard-table tbody td {
    font-size: 15px;
}

/* Buttons */
.dashboard-panel .btn {
    font-size: 15px;
}


</style>


<?php require '../includes/footer.php'; ?>