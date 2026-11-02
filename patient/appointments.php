<?php

$page_title = 'My Appointments';
$asset_prefix = '../';

require_once '../config/mongodb.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

require_role('patient');

$pid = get_patient_id((int)$_SESSION['user_id']);


/*
|--------------------------------------------------------------------------
| Cancel Appointment
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'cancel'
) {
    verify_csrf();

    $aid = (int)($_POST['appointment_id'] ?? 0);

    $a = $mongoDb->selectCollection('appointments')->findOne([
        'appointment_id' => $aid,
        'patient_id' => (int)$pid
    ]);

    $a = $a ? $a->getArrayCopy() : null;

    // Keep the 12-hour cancellation policy
    $hours = (int) setting('cancellation_hours', '12');

    // Get appointment date and time
    $appointmentTimestamp = $a
        ? strtotime(
            $a['appointment_date'] . ' ' . $a['start_time']
        )
        : false;

    // Check if appointment time has passed
    $isPastAppointment =
        $appointmentTimestamp !== false &&
        $appointmentTimestamp <= time();

    // Check cancellation eligibility
    $allowed =
        $a &&
        in_array($a['status'], ['Requested', 'Confirmed'], true) &&
        $appointmentTimestamp !== false &&
        (
            // Allow cancellation for past appointments
            $isPastAppointment ||

            // Future appointments require 12 hours
            (
                $appointmentTimestamp - time()
                >= $hours * 3600
            )
        );

    if ($allowed) {
        try {
            $appointmentsCollection = $mongoDb->selectCollection('appointments');

            $update = $appointmentsCollection->updateOne(
                [
                    'appointment_id' => $aid,
                    'patient_id' => (int)$pid,
                    'status' => ['$in' => ['Requested', 'Confirmed']]
                ],
                [
                    '$set' => ['status' => 'Cancelled']
                ]
            );

            if ($update->getModifiedCount() !== 1) {
                throw new Exception(
                    'This appointment can no longer be cancelled.'
                );
            }

            create_notification(
                (int)$_SESSION['user_id'],
                'cancellation',
                'Appointment Cancelled',
                "Appointment {$a['appointment_ref']} has been cancelled."
            );

            $doctor = $mongoDb->selectCollection('doctors')->findOne([
                'doctor_id' => (int)$a['doctor_id']
            ]);

            if ($doctor && isset($doctor['user_id'])) {
                create_notification(
                    (int)$doctor['user_id'],
                    'cancellation',
                    'Appointment Cancelled',
                    "Appointment {$a['appointment_ref']} was cancelled by the patient."
                );
            }

            if (function_exists('audit')) {
                audit(
                    (int)$_SESSION['user_id'],
                    'Cancelled appointment',
                    'appointment',
                    $aid
                );
            }

            flash('success', 'Appointment cancelled successfully.');
        } catch (Throwable $e) {
            error_log('Appointment cancellation error: ' . $e->getMessage());

            flash(
                'danger',
                $e->getMessage() ?: 'Unable to cancel appointment.'
            );
        }

    } else {

        flash(
            'warning',
            'Cancellation is not allowed within the configured cancellation window.'
        );
    }

    header('Location: appointments.php');
    exit;
}

require '../includes/header.php';

/*
|--------------------------------------------------------------------------
| Appointment List
|--------------------------------------------------------------------------
*/
$rows = [];

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');
$specializationsCollection = $mongoDb->selectCollection('specializations');
$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');
$reviewsCollection = $mongoDb->selectCollection('reviews');

$documents = $appointmentsCollection->find(
    [
        'patient_id' => (int)$pid,
        'status' => ['$ne' => 'Cancelled']
    ],
    [
        'sort' => [
            'appointment_date' => -1,
            'start_time' => -1
        ]
    ]
);

foreach ($documents as $document) {
    $a = $document->getArrayCopy();

    $doctor = $doctorsCollection->findOne([
        'doctor_id' => (int)($a['doctor_id'] ?? 0)
    ]);

    if (!$doctor) {
        continue;
    }

    $doctor = $doctor->getArrayCopy();

    $doctorUser = $usersCollection->findOne([
        'user_id' => (int)($doctor['user_id'] ?? 0)
    ]);

    if (!$doctorUser) {
        continue;
    }

    $doctorUser = $doctorUser->getArrayCopy();

    $specialization = null;
    if (isset($doctor['specialization_id'])) {
        $specialization = $specializationsCollection->findOne([
            'specialization_id' => (int)$doctor['specialization_id']
        ]);
    }

    $branch = null;
    if (isset($a['branch_id'])) {
        $branch = $branchesCollection->findOne([
            'branch_id' => (int)$a['branch_id']
        ]);
    }

    $branch = $branch ? $branch->getArrayCopy() : null;

    $clinic = null;
    if ($branch && isset($branch['clinic_id'])) {
        $clinic = $clinicsCollection->findOne([
            'clinic_id' => (int)$branch['clinic_id']
        ]);
    }

    $specialization = $specialization
        ? $specialization->getArrayCopy()
        : null;

    $clinic = $clinic ? $clinic->getArrayCopy() : null;

    $a['doctor_name'] = $doctorUser['name'] ?? 'Unknown Doctor';
    $a['specialization'] = $specialization['name'] ?? 'General';
    $a['branch_name'] = $branch['branch_name'] ?? null;
    $a['clinic_name'] = $clinic['name'] ?? null;

    $existingReview = $reviewsCollection->findOne([
        'appointment_id' => (int) ($a['appointment_id'] ?? 0),
        'patient_id' => (int) $pid
    ]);

    $a['has_review'] = $existingReview !== null;

    $rows[] = $a;
}

?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-4">

    <div>

        <span class="eyebrow">
            <i class="bi bi-calendar2-week"></i>
            Patient portal
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            My Appointments
        </h1>

        <p class="text-secondary mb-0">
            Track, reschedule and manage your visits.
        </p>

    </div>

    <a class="btn btn-primary" href="doctors.php">
        <i class="bi bi-plus-lg me-1"></i>
        Book New
    </a>

</div>


<div class="card overflow-hidden">

    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>
                    <th>Reference</th>
                    <th>Doctor</th>
                    <th>Visit</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>

            </thead>

            <tbody>

            <?php foreach ($rows as $a): ?>

                <tr>

                    <!-- Reference -->
                    <td>

                        <strong>
                            <?= h(
                                'APT-'
                                . date('Ymd', strtotime($a['appointment_date']))
                                . '-'
                                . (int) $a['appointment_id']
                            ) ?>
                        </strong>

                    </td>


                    <!-- Doctor -->
                    <td>

                        <div class="fw-semibold">
                            Dr. <?= h($a['doctor_name']) ?>
                        </div>

                        <small class="text-secondary">
                            <?= h($a['specialization'] ?? 'General') ?>
                        </small>

                    </td>


                    <!-- Visit -->
                    <td>

                        <div class="fw-semibold">
                            <?= h($a['appointment_date']) ?>
                        </div>

                        <small class="text-secondary">
                            <?= h(substr($a['start_time'], 0, 5)) ?>
                        </small>

                    </td>


                    <!-- Location -->
                    <td>

                        <div>
                            <?= h($a['clinic_name'] ?? '—') ?>
                        </div>

                        <small class="text-secondary">
                            <?= h($a['branch_name'] ?? '—') ?>
                        </small>

                    </td>


                    <!-- Status -->
                    <td>

                        <span class="status-pill bg-light border">

                            <?= h($a['status']) ?>

                        </span>

                        <?php if (!empty($a['token_no'])): ?>

                            <span class="badge text-bg-info">

                                Token #<?= h($a['token_no']) ?>

                            </span>

                        <?php endif; ?>

                    </td>


                    <!-- Actions -->
                    <td class="appointment-action-cell">
                        <div class="appointment-actions">

                            <!-- First Row -->
                            <div class="appointment-main-actions">

                                <!-- Download Receipt -->
                                <form
                                    method="GET"
                                    action="booking-receipt.php"
                                    class="d-inline"
                                >
                                    <input
                                        type="hidden"
                                        name="id"
                                        value="<?= (int)$a['appointment_id'] ?>"
                                    >

                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-success appointment-btn btn-receipt"
                                    >
                                        <i class="bi bi-file-earmark-pdf me-1"></i>
                                        Download Receipt
                                    </button>
                                </form>

                                <?php if (
                                    in_array($a['status'], ['Requested', 'Confirmed'], true)
                                ): ?>

                                    <!-- Cancel -->
                                    <form method="post" class="d-inline">
                                        <?= csrf_field() ?>

                                        <input type="hidden" name="action" value="cancel">

                                        <input
                                            type="hidden"
                                            name="appointment_id"
                                            value="<?= (int)$a['appointment_id'] ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="btn btn-sm btn-outline-danger appointment-btn btn-cancel"
                                            data-confirm="Cancel this appointment?"
                                        >
                                            <i class="bi bi-x-circle me-1"></i>
                                            Cancel
                                        </button>
                                    </form>

                                <?php elseif (
                                    in_array($a['status'], ['Checked-in', 'In Progress'], true)
                                ): ?>

                                    <!-- Queue -->
                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        href="queue.php?id=<?= (int)$a['appointment_id'] ?>"
                                    >
                                        <i class="bi bi-people me-1"></i>
                                        Queue
                                    </a>

                                <?php elseif ($a['status'] === 'Completed'): ?>

                                    <!-- Book Again -->
                                    <a
                                        class="btn btn-sm btn-outline-primary appointment-btn btn-book-again"
                                        href="book.php?doctor_id=<?= (int)$a['doctor_id'] ?>"
                                    >
                                        <i class="bi bi-arrow-repeat me-1"></i>
                                        Book Again
                                    </a>

                                <?php endif; ?>

                            </div>

                            <!-- Second Row: Review -->
                            <?php if ($a['status'] === 'Completed'): ?>

                                <div class="review-action-row">

                                    <?php if (empty($a['has_review'])): ?>

                                        <a
                                            class="btn btn-sm btn-outline-warning appointment-btn btn-review"
                                            href="review.php?id=<?= (int)$a['appointment_id'] ?>"
                                        >
                                            <i class="bi bi-star me-1"></i>
                                            Rate Doctor / Give Feedback
                                        </a>

                                    <?php else: ?>

                                        <span class="badge text-bg-success">
                                            <i class="bi bi-check-circle me-1"></i>
                                            Reviewed
                                        </span>

                                    <?php endif; ?>

                                </div>

                            <?php endif; ?>

                        </div>
                    </td>

                </tr>

            <?php endforeach; ?>


            <?php if (!$rows): ?>

                <tr>

                    <td colspan="6">

                        <div class="empty-state text-center py-5">

                            <i class="bi bi-calendar-x fs-1 d-block mb-2"></i>

                            <h5 class="fw-semibold">
                                No appointments yet
                            </h5>

                            <p class="text-secondary mb-3">
                                You haven't booked any doctor appointment yet.
                            </p>

                            <a
                                href="doctors.php"
                                class="btn btn-primary"
                            >
                                <i class="bi bi-search me-1"></i>
                                Find a Doctor
                            </a>

                        </div>

                    </td>

                </tr>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<style>

.table > :not(caption) > * > * {
    padding: 1rem;
}

.table thead th {
    white-space: nowrap;
    font-size: .82rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #64748b;
    background: #f8fafc;
}

.table tbody tr {
    transition: background .2s ease;
}

.table tbody tr:hover {
    background: #f8fafc;
}

.status-pill {
    display: inline-flex;
    align-items: center;
    border-radius: 999px;
    padding: .35rem .65rem;
    font-size: .75rem;
    font-weight: 600;
    white-space: nowrap;
}

.empty-state {
    color: #64748b;
}

@media (max-width: 767.98px) {

    .table {
        min-width: 900px;
    }

}

/* Improve Appointments Page Readability */

/* Page Heading */
h1.h3 {
    font-size: 30px !important;
}

/* Page Description */
.text-secondary {
    font-size: 16px;
}

/* Table Headings */
.table thead th {
    font-size: 15px !important;
}

/* Table Content */
.table tbody td {
    font-size: 16px;
}

/* Doctor Name and Appointment Date */
.table tbody td .fw-semibold {
    font-size: 16px;
}

/* Reference Number */
.table tbody td strong {
    font-size: 15px;
}

/* Status and Token */
.status-pill,
.table .badge {
    font-size: 14px !important;
}

/* Action Buttons */
.table .btn {
    font-size: 14px;
}

/* Empty State */
.empty-state h5 {
    font-size: 21px;
}

.empty-state p {
    font-size: 16px;
    line-height: 1.6;
}

/* Find a Doctor Button */
.empty-state .btn {
    font-size: 16px;
}

/* Balanced Appointment Table */

.table {
    width: 100%;
    table-layout: auto;
}

/* Keep Reference, Doctor and Visit on one line */
.table tbody td:nth-child(1),
.table tbody td:nth-child(2),
.table tbody td:nth-child(3) {
    white-space: nowrap;
}

/* Reference */
.table tbody td:nth-child(1) {
    min-width: 170px;
}

/* Doctor */
.table tbody td:nth-child(2) {
    min-width: 175px;
}

/* Visit */
.table tbody td:nth-child(3) {
    min-width: 120px;
}

/* Location */
.table tbody td:nth-child(4) {
    min-width: 155px;
}

/* Status */
.table tbody td:nth-child(5) {
    min-width: 140px;
}

/* Action Column */
.appointment-action-cell {
    min-width: 360px;
    white-space: nowrap;
}

/* Main Action Row */
.appointment-actions {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
    margin-left: 18px;
}

.appointment-main-actions {
    display: flex;
    align-items: center;
    flex-wrap: nowrap;
    gap: 10px;
}

/* Review Second Row */
.review-action-row {
    display: flex;
    align-items: center;
    margin-left: 0;
}

/* Buttons */
.appointment-actions .appointment-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    min-height: 42px;
    padding: 9px 12px;
    font-size: 14px;
    white-space: nowrap;
    border-radius: 10px;
    margin: 0;
}

.appointment-actions .btn-receipt {
    min-width: 175px;
}

.appointment-actions .btn-cancel {
    min-width: 105px;
}

.appointment-actions .btn-book-again {
    min-width: 140px;
}

.appointment-actions .btn-review {
    min-width: 190px;
}

/* Mobile */
@media (max-width: 767.98px) {
    .table {
        min-width: 900px;
    }

    .appointment-action-cell {
        min-width: 360px;
    }

    .appointment-actions {
        margin-left: 8px;
    }

    .appointment-actions .appointment-btn {
        min-height: 38px;
        padding: 8px 10px;
        font-size: 13px;
    }
}


/* Align ACTION heading with action buttons */
.table thead th:last-child {
    text-align: left;
    padding-left: 40px;
}

</style>


<?php require '../includes/footer.php'; ?>