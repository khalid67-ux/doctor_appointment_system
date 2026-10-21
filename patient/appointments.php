<?php

$page_title = 'My Appointments';
$asset_prefix = '../';

require '../includes/header.php';
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
    if (!verify_csrf($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Invalid security token. Please try again.');
        header('Location: appointments.php');
        exit;
    }

    $aid = (int)($_POST['appointment_id'] ?? 0);

    $a = $mongoDb->selectCollection('appointments')->findOne([
        'appointment_id' => $aid,
        'patient_id' => (int)$pid
    ]);

    $a = $a ? $a->getArrayCopy() : null;

    $hours = (int)setting('cancellation_hours', '24');

    $allowed =
        $a &&
        in_array($a['status'], ['Requested', 'Confirmed'], true) &&
        (
            strtotime(
                $a['appointment_date'] . ' ' . $a['start_time']
            ) - time()
            >= $hours * 3600
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
    ['patient_id' => (int)$pid],
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
                            <?= h($a['appointment_ref']) ?>
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

                        <!-- Download Receipt -->
                        <a
                            class="btn btn-sm btn-outline-success appointment-btn btn-receipt"
                            href="booking-receipt.php?id=<?= (int)$a['appointment_id'] ?>"
                        >
                            <i class="bi bi-file-earmark-pdf me-1"></i>
                            Download Receipt
                        </a>

                        <?php if (
                            in_array(
                                $a['status'],
                                ['Requested', 'Confirmed'],
                                true
                            )
                        ): ?>

                            <!-- Cancel -->
                            <form
                                method="post"
                                class="d-inline"
                            >
                                <?= csrf_field() ?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="cancel"
                                >

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
                            in_array(
                                $a['status'],
                                ['Checked-in', 'In Progress'],
                                true
                            )
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

                            <!-- Review -->
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

/* Balanced appointment action buttons */
.appointment-action-cell {
    min-width: 580px;
}

.appointment-actions {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
}

.appointment-actions .appointment-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 42px;
    padding: 9px 14px;
    font-size: 14px;
    line-height: 1.3;
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

@media (max-width: 767.98px) {
    .appointment-actions {
        gap: 6px;
    }

    .appointment-actions .appointment-btn {
        min-height: 38px;
        padding: 8px 10px;
        font-size: 13px;
    }

    .appointment-actions .btn-receipt,
    .appointment-actions .btn-cancel,
    .appointment-actions .btn-book-again,
    .appointment-actions .btn-review {
        min-width: 0;
    }
}

/* Shift appointment action content slightly right */
.appointment-actions {
    margin-left: 12px;
}

</style>


<?php require '../includes/footer.php'; ?>