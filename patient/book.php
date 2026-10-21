<?php

declare(strict_types=1);

$page_title = 'Book Appointment';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('patient');

/*
|--------------------------------------------------------------------------
| Request Values
|--------------------------------------------------------------------------
*/

$did = (int)(
    $_GET['doctor_id']
    ?? $_POST['doctor_id']
    ?? 0
);

$date = trim(
    (string)(
        $_GET['date']
        ?? $_POST['appointment_date']
        ?? date('Y-m-d')
    )
);

$time = trim(
    (string)(
        $_GET['time']
        ?? $_POST['start_time']
        ?? ''
    )
);

$selected_branch = (int)(
    $_GET['branch_id']
    ?? $_POST['branch_id']
    ?? 0
);


/*
|--------------------------------------------------------------------------
| Doctor Information
|--------------------------------------------------------------------------
*/

$doctorDoc = $mongoDb->selectCollection('doctors')->findOne([
    'doctor_id' => $did,
    'verification_status' => 'Approved'
]);

$d = null;

if ($doctorDoc) {
    $doctor = $doctorDoc->getArrayCopy();

    $userDoc = $mongoDb->selectCollection('users')->findOne([
        'user_id' => (int)($doctor['user_id'] ?? 0),
        'status' => 'active'
    ]);

    if ($userDoc) {
        $user = $userDoc->getArrayCopy();

        $specialization = null;

        if (isset($doctor['specialization_id'])) {
            $specializationDoc = $mongoDb
                ->selectCollection('specializations')
                ->findOne([
                    'specialization_id' => (int)$doctor['specialization_id']
                ]);

            $specialization = $specializationDoc
                ? $specializationDoc->getArrayCopy()
                : null;
        }

        $d = array_merge($doctor, [
            'user_id' => $user['user_id'] ?? null,
            'name' => $user['name'] ?? '',
            'email' => $user['email'] ?? '',
            'user_status' => $user['status'] ?? '',
            'specialization' => $specialization['name'] ?? 'General'
        ]);
    }
}


/*
|--------------------------------------------------------------------------
| Doctor Not Found
|--------------------------------------------------------------------------
*/

if (!$d) {

    require __DIR__ . '/../includes/header.php';
    ?>

    <div class="container py-5">

        <div class="booking-error-card">

            <div class="booking-error-icon">
                <i class="bi bi-person-x"></i>
            </div>

            <h3>Doctor Not Available</h3>

            <p>
                The selected doctor could not be found or is currently unavailable.
            </p>

            <a
                href="doctors.php"
                class="btn btn-primary"
            >
                <i class="bi bi-arrow-left me-1"></i>
                Back to Doctors
            </a>

        </div>

    </div>

    <?php

    require __DIR__ . '/../includes/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Doctor Branches
|--------------------------------------------------------------------------
*/

$branches = [];

$doctorBranches = $mongoDb->selectCollection('doctor_branches')->find(
    ['doctor_id' => $did]
);

$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');

foreach ($doctorBranches as $doctorBranchDoc) {
    $doctorBranch = $doctorBranchDoc->getArrayCopy();

    $branchDoc = $branchesCollection->findOne([
        'branch_id' => (int)($doctorBranch['branch_id'] ?? 0)
    ]);

    if (!$branchDoc) {
        continue;
    }

    $branch = $branchDoc->getArrayCopy();

    $clinicDoc = $clinicsCollection->findOne([
        'clinic_id' => (int)($branch['clinic_id'] ?? 0)
    ]);

    $clinic = $clinicDoc ? $clinicDoc->getArrayCopy() : [];

    $branches[] = [
        'branch_id' => (int)$branch['branch_id'],
        'branch_name' => $branch['branch_name'] ?? '',
        'address' => $branch['address'] ?? '',
        'clinic_id' => $clinic['clinic_id'] ?? null,
        'clinic_name' => $clinic['name'] ?? ''
    ];
}

usort($branches, function ($a, $b) {
    return strcmp(
        $a['clinic_name'] . ' ' . $a['branch_name'],
        $b['clinic_name'] . ' ' . $b['branch_name']
    );
});


/*
|--------------------------------------------------------------------------
| Booking State
|--------------------------------------------------------------------------
*/

$booking_error = false;


/*
|--------------------------------------------------------------------------
| Handle Booking
|--------------------------------------------------------------------------
|
| IMPORTANT:
| All POST processing happens BEFORE header.php.
| This prevents "Cannot modify header information".
|
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $date = trim((string)($_POST['appointment_date'] ?? ''));
    $time = trim((string)($_POST['start_time'] ?? ''));
    $branch = (int)($_POST['branch_id'] ?? 0);

    $pid = get_patient_id((int)$_SESSION['user_id']);

    if (!$pid) {
        $booking_error = true;
        flash('danger', 'Patient profile not found.');

    } elseif (!valid_date($date) || $date < date('Y-m-d')) {
        $booking_error = true;
        flash('danger', 'Please select a valid appointment date.');

    } elseif (!valid_time($time)) {
        $booking_error = true;
        flash('danger', 'Please select a valid appointment time.');

    } elseif (
        $date === date('Y-m-d')
        && strtotime($time) <= strtotime(date('H:i:s'))
    ) {
        $booking_error = true;
        flash(
            'danger',
            'This time slot has already passed. Please select a future time.'
        );

    } elseif ($branch <= 0) {
        $booking_error = true;
        flash('danger', 'Please select a clinic branch.');

    } else {

        $doctorBranch = $mongoDb->selectCollection('doctor_branches')->findOne([
            'doctor_id' => $did,
            'branch_id' => $branch
        ]);

        $branchInfo = null;

        if ($doctorBranch) {
            $branchDoc = $branchesCollection->findOne([
                'branch_id' => $branch
            ]);

            if ($branchDoc) {
                $branchData = $branchDoc->getArrayCopy();

                $clinicDoc = $clinicsCollection->findOne([
                    'clinic_id' => (int)($branchData['clinic_id'] ?? 0)
                ]);

                $clinicData = $clinicDoc ? $clinicDoc->getArrayCopy() : [];

                $branchInfo = [
                    'branch_id' => $branchData['branch_id'],
                    'branch_name' => $branchData['branch_name'] ?? '',
                    'clinic_name' => $clinicData['name'] ?? ''
                ];
            }
        }

        if (!$branchInfo) {
            $booking_error = true;
            flash(
                'danger',
                'Selected branch is not assigned to this doctor.'
            );

        } else {

            $availableSlots = generate_slots($did, $date);

            if (!is_array($availableSlots)) {
                $availableSlots = [];
            }

            $matchingSlot = null;
            $requestedTime = substr($time, 0, 5);

            foreach ($availableSlots as $slot) {

                if (!is_array($slot)) {
                    continue;
                }

                $slotRawTime = $slot['start_time']
                    ?? $slot['time']
                    ?? '';

                $slotTime = substr((string)$slotRawTime, 0, 5);
                $slotBranch = (int)($slot['branch_id'] ?? 0);

                $slotAvailable = array_key_exists('available', $slot)
                    ? (bool)$slot['available']
                    : true;

                if (
                    $slotTime === $requestedTime
                    && $slotBranch === $branch
                    && $slotAvailable
                ) {
                    $matchingSlot = $slot;
                    break;
                }
            }

            if (!$matchingSlot) {
                $booking_error = true;
                flash(
                    'danger',
                    'That appointment slot is no longer available. Please select another slot.'
                );

            } else {

                $_SESSION['pending_booking'] = [
                    'patient_id' => $pid,
                    'doctor_id' => $did,
                    'branch_id' => $branch,
                    'appointment_date' => $date,
                    'start_time' => $time,
                    'created_at' => time()
                ];

                header('Location: booking-summary.php');
                exit;
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| Load Header
|--------------------------------------------------------------------------
|
| This MUST be after all redirect/header processing.
|
*/

require __DIR__ . '/../includes/header.php';

?>

<div class="container py-4 py-lg-5">

    <div class="row justify-content-center">

        <div class="col-xl-9 col-lg-10">


            <!-- Page Header -->

            <div class="booking-page-header mb-4">

                <div>

                    <span class="eyebrow">
                        <i class="bi bi-calendar2-check"></i>
                        Appointment Booking
                    </span>

                    <h1 class="h3 fw-bold mt-2 mb-2">
                        Book an Appointment
                    </h1>

                    <p class="text-secondary mb-0">
                        Select a clinic branch, appointment date and available time slot.
                    </p>

                </div>


                <a
                    href="doctor-details.php?id=<?= $did ?>"
                    class="btn btn-outline-secondary"
                >
                    <i class="bi bi-arrow-left me-1"></i>
                    Doctor Profile
                </a>

            </div>


            <!-- Main Booking Card -->

            <div class="booking-card">


                <!-- Doctor Summary -->

                <div class="doctor-summary">

                    <div class="doctor-avatar">
                        <i class="bi bi-person-badge"></i>
                    </div>


                    <div class="doctor-summary-info">

                        <div class="mini-label">
                            Selected Doctor
                        </div>

                        <h2>
                            Dr. <?= h($d['name']) ?>
                        </h2>

                        <div class="doctor-meta">

                            <span>
                                <i class="bi bi-award"></i>
                                <?= h($d['specialization'] ?? 'General') ?>
                            </span>

                            <?php if (!empty($d['qualification'])): ?>

                                <span>
                                    <i class="bi bi-mortarboard"></i>
                                    <?= h($d['qualification']) ?>
                                </span>

                            <?php endif; ?>

                        </div>

                    </div>


                    <div class="doctor-fee">

                        <span>
                            Consultation Fee
                        </span>

                        <?php
                        $feeValue = $d['consultation_fee'] ?? 0;

                        $consultationFee = $feeValue instanceof MongoDB\BSON\Decimal128
                            ? (float) (string) $feeValue
                            : (float) $feeValue;
                        ?>

                        <strong>
                            ৳<?= number_format($consultationFee, 2) ?>
                        </strong>

                    </div>

                </div>


                <!-- Booking Form -->

                <form
                    method="post"
                    id="bookingForm"
                    novalidate
                >

                    <?= csrf_field() ?>


                    <input
                        type="hidden"
                        name="doctor_id"
                        value="<?= $did ?>"
                    >


                    <input
                        type="hidden"
                        name="start_time"
                        id="start_time"
                        value="<?= h($time) ?>"
                    >


                    <div class="booking-step-grid">


                        <!-- Branch -->

                        <div class="booking-field">

                            <label
                                for="branch_id"
                                class="form-label"
                            >
                                <span class="field-number">
                                    1
                                </span>

                                Clinic / Branch
                            </label>


                            <select
                                class="form-select form-select-lg"
                                name="branch_id"
                                id="branch_id"
                                required
                            >

                                <option value="">
                                    Select clinic branch
                                </option>


                                <?php foreach ($branches as $b): ?>

                                    <option
                                        value="<?= (int)$b['branch_id'] ?>"
                                        <?= (
                                            $selected_branch
                                            === (int)$b['branch_id']
                                        )
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >

                                        <?= h(
                                            $b['clinic_name']
                                            . ' — '
                                            . $b['branch_name']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>


                            <small class="field-help">
                                Choose where you want to visit the doctor.
                            </small>

                        </div>


                        <!-- Date -->

                        <div class="booking-field">

                            <label
                                for="appointment_date"
                                class="form-label"
                            >

                                <span class="field-number">
                                    2
                                </span>

                                Appointment Date

                            </label>


                            <input
                                type="date"
                                class="form-control form-control-lg"
                                name="appointment_date"
                                id="appointment_date"
                                value="<?= h($date) ?>"
                                min="<?= date('Y-m-d') ?>"
                                required
                            >


                            <small class="field-help">
                                Select today or a future date.
                            </small>

                        </div>

                    </div>


                    <!-- Live Availability -->

                    <div class="availability-panel mt-4">

                        <div class="availability-header">

                            <div>

                                <div class="availability-title">

                                    <span class="field-number">
                                        3
                                    </span>

                                    Available Time Slots

                                </div>

                                <p class="text-secondary small mb-0">
                                    Live availability for your selected branch and date.
                                </p>

                            </div>


                            <div
                                id="slotLoader"
                                class="slot-loader d-none"
                            >

                                <span class="spinner-border spinner-border-sm"></span>

                                <span>
                                    Loading
                                </span>

                            </div>

                        </div>


                        <!-- Message -->

                        <div
                            id="slotMessage"
                            class="slot-message d-none"
                        ></div>


                        <!-- Slots -->

                        <div
                            id="slotsContainer"
                            class="slots-grid"
                        ></div>


                        <!-- Selected Slot -->

                        <div
                            id="selectedSlotSummary"
                            class="selected-slot-summary d-none"
                        >

                            <div class="selected-slot-icon">
                                <i class="bi bi-check-lg"></i>
                            </div>

                            <div>

                                <small>
                                    Selected Time
                                </small>

                                <strong id="selectedTimeDisplay">
                                    —
                                </strong>

                            </div>

                        </div>

                    </div>


                    <!-- Booking Notice -->

                    <div class="booking-notice mt-4">

                        <i class="bi bi-info-circle"></i>

                        <div>

                            <strong>
                                Before confirming
                            </strong>

                            <p>
                                Please verify the clinic branch, date and
                                selected time. The slot will be checked
                                again on the server before the appointment
                                is created.
                            </p>

                        </div>

                    </div>


                    <!-- Actions -->

                    <div class="booking-actions">

                        <a
                            href="doctors.php"
                            class="btn btn-light btn-lg"
                        >
                            Cancel
                        </a>


                        <button
                            type="submit"
                            id="confirmBookingBtn"
                            class="btn btn-primary btn-lg"
                            disabled
                        >

                            <span
                                id="confirmButtonSpinner"
                                class="spinner-border spinner-border-sm d-none me-2"
                            ></span>

                            <i
                                id="confirmButtonIcon"
                                class="bi bi-calendar-check me-2"
                            ></i>

                            <span id="confirmButtonText">
                                Confirm Booking
                            </span>

                        </button>

                    </div>

                </form>

            </div>

        </div>

    </div>

</div>


<style>

.booking-page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
}

@media (max-width: 767px) {

    .booking-page-header {
        align-items: flex-start;
        flex-direction: column;
    }

}

.booking-card {
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    box-shadow: 0 12px 35px rgba(15, 23, 42, .06);
    padding: 1.5rem;
}

@media (min-width: 992px) {

    .booking-card {
        padding: 2rem;
    }

}

.doctor-summary {
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 1.15rem;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    margin-bottom: 1.75rem;
}

.doctor-avatar {
    width: 58px;
    height: 58px;
    min-width: 58px;
    border-radius: 15px;
    background: #dbeafe;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
}

.doctor-summary-info {
    flex: 1;
    min-width: 0;
}

.mini-label {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .06em;
    color: #64748b;
    font-weight: 700;
}

.doctor-summary h2 {
    font-size: 1.05rem;
    font-weight: 700;
    margin: .15rem 0 .35rem;
    color: #0f172a;
}

.doctor-meta {
    display: flex;
    flex-wrap: wrap;
    gap: .8rem;
    font-size: .78rem;
    color: #64748b;
}

.doctor-meta i {
    margin-right: .2rem;
}

.doctor-fee {
    text-align: right;
    padding-left: 1rem;
    border-left: 1px solid #e2e8f0;
}

.doctor-fee span {
    display: block;
    font-size: .7rem;
    color: #64748b;
    margin-bottom: .2rem;
}

.doctor-fee strong {
    font-size: 1rem;
    color: #0f766e;
}

@media (max-width: 575px) {

    .doctor-summary {
        align-items: flex-start;
        flex-wrap: wrap;
    }

    .doctor-fee {
        width: 100%;
        text-align: left;
        padding-left: 0;
        padding-top: .75rem;
        border-left: 0;
        border-top: 1px solid #e2e8f0;
    }

}

.booking-step-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1.25rem;
}

@media (max-width: 767px) {

    .booking-step-grid {
        grid-template-columns: 1fr;
    }

}

.booking-field .form-label {
    display: flex;
    align-items: center;
    gap: .55rem;
    font-weight: 700;
    color: #334155;
}

.field-number {
    width: 25px;
    height: 25px;
    min-width: 25px;
    border-radius: 50%;
    background: #eff6ff;
    color: #2563eb;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .72rem;
    font-weight: 800;
}

.field-help {
    display: block;
    color: #94a3b8;
    font-size: .73rem;
    margin-top: .4rem;
}

.availability-panel {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 1.25rem;
    background: #ffffff;
}

.availability-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
}

.availability-title {
    display: flex;
    align-items: center;
    gap: .55rem;
    font-weight: 700;
    color: #334155;
}

.slot-loader {
    display: flex;
    align-items: center;
    gap: .45rem;
    font-size: .75rem;
    color: #2563eb;
}

.slot-message {
    margin-top: 1rem;
    padding: .85rem 1rem;
    border-radius: 10px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #64748b;
    font-size: .82rem;
}

.slots-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: .7rem;
    margin-top: 1rem;
}

@media (max-width: 767px) {

    .slots-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

}

@media (max-width: 480px) {

    .slots-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

}

.slot-button {
    min-height: 48px;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    background: #ffffff;
    color: #2563eb;
    font-size: .82rem;
    font-weight: 600;
    cursor: pointer;
    transition: .2s ease;
}

.slot-button:hover {
    background: #eff6ff;
    border-color: #2563eb;
    transform: translateY(-1px);
}

.slot-button.active {
    background: #2563eb;
    border-color: #2563eb;
    color: #ffffff;
    box-shadow: 0 5px 14px rgba(37, 99, 235, .22);
}

.slot-button.unavailable {
    background: #f8fafc;
    border-color: #e2e8f0;
    color: #94a3b8;
    cursor: not-allowed;
    text-decoration: line-through;
}

.selected-slot-summary {
    margin-top: 1rem;
    display: flex;
    align-items: center;
    gap: .7rem;
    padding: .8rem 1rem;
    border-radius: 12px;
    background: #ecfdf5;
    border: 1px solid #bbf7d0;
}

.selected-slot-icon {
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #16a34a;
    color: #ffffff;
    display: flex;
    align-items: center;
    justify-content: center;
}

.selected-slot-summary small {
    display: block;
    color: #64748b;
    font-size: .7rem;
}

.selected-slot-summary strong {
    display: block;
    color: #166534;
    font-size: .9rem;
}

.booking-notice {
    display: flex;
    align-items: flex-start;
    gap: .7rem;
    padding: .9rem 1rem;
    border-radius: 12px;
    background: #eff6ff;
    border: 1px solid #dbeafe;
    color: #1e40af;
}

.booking-notice > i {
    margin-top: .15rem;
}

.booking-notice strong {
    display: block;
    font-size: .8rem;
    margin-bottom: .2rem;
}

.booking-notice p {
    margin: 0;
    font-size: .75rem;
    color: #475569;
}

.booking-actions {
    display: flex;
    justify-content: flex-end;
    gap: .75rem;
    margin-top: 1.5rem;
    padding-top: 1.25rem;
    border-top: 1px solid #e2e8f0;
}

@media (max-width: 575px) {

    .booking-actions {
        flex-direction: column-reverse;
    }

    .booking-actions .btn {
        width: 100%;
    }

}

.booking-error-card {
    max-width: 620px;
    margin: 2rem auto;
    padding: 3rem 2rem;
    text-align: center;
    background: #ffffff;
    border: 1px solid #e5e7eb;
    border-radius: 20px;
    box-shadow: 0 10px 30px rgba(15, 23, 42, .06);
}

.booking-error-icon {
    width: 70px;
    height: 70px;
    margin: 0 auto 1rem;
    border-radius: 50%;
    background: #fef2f2;
    color: #dc2626;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
}

.booking-error-card h3 {
    font-weight: 700;
    color: #0f172a;
}

.booking-error-card p {
    color: #64748b;
    margin-bottom: 1.25rem;
}


/* Improve text readability on booking page */

.booking-page-header h1 {
    font-size: 2rem;
}

.booking-page-header p {
    font-size: 1.05rem;
}

.mini-label {
    font-size: 0.85rem;
}

.doctor-summary h2 {
    font-size: 1.2rem;
}

.doctor-meta {
    font-size: 0.9rem;
}

.doctor-fee span {
    font-size: 0.85rem;
}

.doctor-fee strong {
    font-size: 1.15rem;
}

.booking-field .form-label {
    font-size: 1.05rem;
}

.field-number {
    font-size: 0.85rem;
}

.field-help {
    font-size: 0.88rem;
}

.availability-title {
    font-size: 1.1rem;
}

.availability-header p {
    font-size: 0.9rem !important;
}

.slot-loader {
    font-size: 0.9rem;
}

.slot-message {
    font-size: 0.95rem;
}

.slot-button {
    font-size: 0.95rem;
}

.selected-slot-summary small {
    font-size: 0.85rem;
}

.selected-slot-summary strong {
    font-size: 1rem;
}

.booking-notice strong {
    font-size: 0.95rem;
}

.booking-notice p {
    font-size: 0.9rem;
}

.booking-actions .btn {
    font-size: 1rem;
}

</style>


<script>

document.addEventListener(
    'DOMContentLoaded',
    function () {

        const doctorId =
            <?= (int)$did ?>;

        const branchSelect =
            document.getElementById('branch_id');

        const dateInput =
            document.getElementById('appointment_date');

        const startTimeInput =
            document.getElementById('start_time');

        const slotsContainer =
            document.getElementById('slotsContainer');

        const slotMessage =
            document.getElementById('slotMessage');

        const slotLoader =
            document.getElementById('slotLoader');

        const confirmButton =
            document.getElementById('confirmBookingBtn');

        const confirmButtonText =
            document.getElementById('confirmButtonText');

        const confirmButtonIcon =
            document.getElementById('confirmButtonIcon');

        const confirmButtonSpinner =
            document.getElementById('confirmButtonSpinner');

        const selectedSlotSummary =
            document.getElementById('selectedSlotSummary');

        const selectedTimeDisplay =
            document.getElementById('selectedTimeDisplay');

        const bookingForm =
            document.getElementById('bookingForm');


        /*
        |--------------------------------------------------------------------------
        | Message
        |--------------------------------------------------------------------------
        */

        function showMessage(message) {

            slotMessage.textContent = message;

            slotMessage.classList.remove('d-none');
        }


        function hideMessage() {

            slotMessage.textContent = '';

            slotMessage.classList.add('d-none');
        }


        /*
        |--------------------------------------------------------------------------
        | Reset
        |--------------------------------------------------------------------------
        */

        function resetTime() {

            startTimeInput.value = '';

            selectedTimeDisplay.textContent = '—';

            selectedSlotSummary.classList.add('d-none');

            confirmButton.disabled = true;
        }


        /*
        |--------------------------------------------------------------------------
        | Loading
        |--------------------------------------------------------------------------
        */

        function setLoading(isLoading) {

            if (isLoading) {

                slotLoader.classList.remove('d-none');

            } else {

                slotLoader.classList.add('d-none');
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Load Slots
        |--------------------------------------------------------------------------
        */

        async function loadSlots() {

            const branchId =
                branchSelect.value;

            const date =
                dateInput.value;


            slotsContainer.innerHTML = '';

            resetTime();

            hideMessage();


            if (!branchId) {

                showMessage(
                    'Please select a clinic branch to view available slots.'
                );

                return;
            }


            if (!date) {

                showMessage(
                    'Please select an appointment date.'
                );

                return;
            }


            if (
                date < '<?= date('Y-m-d') ?>'
            ) {

                showMessage(
                    'Please select today or a future date.'
                );

                return;
            }


            setLoading(true);


            try {

                const url =
                    '../api/slots.php'
                    + '?doctor_id='
                    + encodeURIComponent(doctorId)
                    + '&date='
                    + encodeURIComponent(date)
                    + '&branch_id='
                    + encodeURIComponent(branchId);


                const response =
                    await fetch(
                        url,
                        {
                            method: 'GET',

                            headers: {
                                'Accept':
                                    'application/json'
                            },

                            cache: 'no-store'
                        }
                    );


                if (!response.ok) {

                    throw new Error(
                        'Unable to load slots.'
                    );
                }


                const data =
                    await response.json();


                if (!data.success) {

                    showMessage(
                        data.message
                        || 'Unable to load available slots.'
                    );

                    return;
                }


                const slots =
                    Array.isArray(data.slots)
                        ? data.slots
                        : [];


                if (!slots.length) {

                    showMessage(
                        'No schedule is available for this branch on the selected date.'
                    );

                    return;
                }


                let availableCount = 0;


                slots.forEach(
                    function (slot) {

                        /*
                        |--------------------------------------------------------------------------
                        | IMPORTANT:
                        | API returns start_time.
                        | Support time too for compatibility.
                        |--------------------------------------------------------------------------
                        */

                        const rawTime =
                            slot.start_time
                            ?? slot.time
                            ?? '';


                        const time =
                            String(rawTime)
                                .substring(0, 5);


                        if (!time) {
                            return;
                        }


                        const now = new Date();
                        const today = [
                            now.getFullYear(),
                            String(now.getMonth() + 1).padStart(2, '0'),
                            String(now.getDate()).padStart(2, '0')
                        ].join('-');

                        const isPastSlot =
                            date === today &&
                            time <= [
                                String(now.getHours()).padStart(2, '0'),
                                String(now.getMinutes()).padStart(2, '0')
                            ].join(':');

                        const available =
                            Boolean(slot.available) && !isPastSlot;


                        const button =
                            document.createElement(
                                'button'
                            );


                        button.type = 'button';


                        button.className =
                            'slot-button'
                            + (
                                available
                                    ? ''
                                    : ' unavailable'
                            );


                        button.textContent =
                            time;


                        if (!available) {

                            button.disabled = true;

                            button.title =
                                'This slot is not available.';

                        } else {

                            availableCount++;


                            button.addEventListener(
                                'click',
                                function () {

                                    document
                                        .querySelectorAll(
                                            '#slotsContainer .slot-button'
                                        )
                                        .forEach(
                                            function (btn) {

                                                btn.classList.remove(
                                                    'active'
                                                );

                                            }
                                        );


                                    button.classList.add(
                                        'active'
                                    );


                                    startTimeInput.value =
                                        rawTime;


                                    selectedTimeDisplay.textContent =
                                        time;


                                    selectedSlotSummary
                                        .classList
                                        .remove(
                                            'd-none'
                                        );


                                    confirmButton.disabled =
                                        false;

                                }
                            );
                        }


                        slotsContainer.appendChild(
                            button
                        );

                    }
                );


                if (!availableCount) {

                    showMessage(
                        'All time slots are currently booked for this date.'
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | Preserve URL-selected time
                |--------------------------------------------------------------------------
                */

                const initialTime =
                    <?= json_encode(
                        substr($time, 0, 5)
                    ) ?>;


                if (initialTime) {

                    const buttons =
                        document.querySelectorAll(
                            '#slotsContainer .slot-button:not(.unavailable)'
                        );


                    buttons.forEach(
                        function (button) {

                            if (
                                button.textContent.trim()
                                === initialTime
                            ) {

                                button.click();
                            }

                        }
                    );
                }


            } catch (error) {

                console.error(error);

                showMessage(
                    'Unable to load live availability. Please refresh and try again.'
                );

            } finally {

                setLoading(false);
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Branch Change
        |--------------------------------------------------------------------------
        */

        branchSelect.addEventListener(
            'change',
            function () {

                loadSlots();

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Date Change
        |--------------------------------------------------------------------------
        */

        dateInput.addEventListener(
            'change',
            function () {

                loadSlots();

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Submit Protection
        |--------------------------------------------------------------------------
        */

        bookingForm.addEventListener(
            'submit',
            function (event) {

                if (
                    !branchSelect.value
                    || !dateInput.value
                    || !startTimeInput.value
                ) {

                    event.preventDefault();

                    showMessage(
                        'Please select a branch, date and available time slot before confirming.'
                    );

                    return;
                }


                confirmButton.disabled = true;

                confirmButtonSpinner.classList
                    .remove('d-none');

                confirmButtonIcon.classList
                    .add('d-none');

                confirmButtonText.textContent =
                    'Creating Appointment...';

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Auto Refresh Live Availability
        |--------------------------------------------------------------------------
        */

        let slotRefreshTimer = null;

        function startSlotAutoRefresh() {
            if (slotRefreshTimer !== null) {
                clearInterval(slotRefreshTimer);
            }

            slotRefreshTimer = setInterval(function () {
                if (
                    document.visibilityState === 'visible' &&
                    branchSelect.value &&
                    dateInput.value
                ) {
                    loadSlots();
                }
            }, 30000);
        }

        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible') {
                loadSlots();
            }
        });

        startSlotAutoRefresh();


        /*
        |--------------------------------------------------------------------------
        | Initial Load
        |--------------------------------------------------------------------------
        */

        if (
            branchSelect.value
            && dateInput.value
        ) {

            loadSlots();

        } else {

            showMessage(
                'Select a clinic branch and appointment date to view available slots.'
            );
        }

    }
);

</script>


<?php

require __DIR__ . '/../includes/footer.php';

?>