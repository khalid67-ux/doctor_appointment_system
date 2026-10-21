<?php

$page_title = 'Reschedule Appointment';
$asset_prefix = '../';

require '../includes/header.php';
require_role('patient');

$patient_id = get_patient_id(
    (int)$_SESSION['user_id']
);

$appointment_id = (int)($_GET['id'] ?? 0);


/*
|--------------------------------------------------------------------------
| Get Appointment
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
        'doctor_id' => (int) $appointment['doctor_id']
    ]);

    $doctorData = $doctor ? (array) $doctor : [];

    $doctorUser = $mongoDb->users->findOne([
        'user_id' => (int) ($doctorData['user_id'] ?? 0)
    ]);

    $appointment['doctor_user_id'] = (int) ($doctorData['user_id'] ?? 0);
    $appointment['doctor_name'] = $doctorUser
        ? (((array) $doctorUser)['name'] ?? 'Doctor')
        : 'Doctor';
}


/*
|--------------------------------------------------------------------------
| Appointment Not Found
|--------------------------------------------------------------------------
*/

if (!$appointment) {

    echo '
        <div class="alert alert-danger">
            Appointment not found.
        </div>
    ';

    require '../includes/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Check Reschedule Eligibility
|--------------------------------------------------------------------------
*/

if (
    !in_array(
        $appointment['status'],
        ['Requested', 'Confirmed'],
        true
    )
) {

    echo '
        <div class="alert alert-warning">
            This appointment cannot be rescheduled
            in its current status.
        </div>
    ';

    require '../includes/footer.php';
    exit;
}


verify_csrf();


/*
|--------------------------------------------------------------------------
| Handle Reschedule
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $new_date = trim(
        $_POST['date'] ?? ''
    );

    $new_time = trim(
        $_POST['time'] ?? ''
    );

    $doctor_id = (int)$appointment['doctor_id'];

    $old_date = $appointment['appointment_date'];
    $old_time = $appointment['start_time'];

    $branch_id = (int)$appointment['branch_id'];


    /*
    |--------------------------------------------------------------------------
    | Basic Validation
    |--------------------------------------------------------------------------
    */

    if (
        !valid_date($new_date) ||
        $new_date < date('Y-m-d') ||
        !valid_time($new_time)
    ) {

        flash(
            'danger',
            'Please select a valid date and time.'
        );

    } else {


        /*
        |--------------------------------------------------------------------------
        | Generate Available Slots
        |--------------------------------------------------------------------------
        */

        $generated_slots = generate_slots(
            $doctor_id,
            $new_date
        );


        /*
        |--------------------------------------------------------------------------
        | Check Selected Slot
        |--------------------------------------------------------------------------
        */

        $slot_available = false;

        foreach ($generated_slots as $slot) {

            if (
                $slot['available'] === true &&
                $slot['time'] === substr(
                    $new_time,
                    0,
                    5
                ) &&
                (int)$slot['branch_id'] === $branch_id
            ) {

                $slot_available = true;
                break;
            }
        }


        if (!$slot_available) {

            flash(
                'danger',
                'The selected slot is unavailable.'
            );

        } else {


            /*
            |--------------------------------------------------------------------------
            | Reschedule Appointment
            |--------------------------------------------------------------------------
            */

            try {
                // Recheck the latest appointment state
                $lockedAppointment = $mongoDb->appointments->findOne([
                    'appointment_id' => $appointment_id,
                    'patient_id' => (int) $patient_id,
                    'status' => ['$in' => ['Requested', 'Confirmed']]
                ]);

                if (!$lockedAppointment) {
                    throw new RuntimeException(
                        'Appointment is no longer available or cannot be rescheduled.'
                    );
                }

                $lockedAppointment = (array) $lockedAppointment;

                // Reject the same date and time
                if (
                    $new_date === ($lockedAppointment['appointment_date'] ?? '') &&
                    substr($new_time, 0, 8) ===
                        substr($lockedAppointment['start_time'] ?? '', 0, 8)
                ) {
                    throw new RuntimeException(
                        'Please select a different appointment slot.'
                    );
                }

                // Check for another appointment in the requested slot
                $conflict = $mongoDb->appointments->findOne([
                    'doctor_id' => $doctor_id,
                    'branch_id' => $branch_id,
                    'appointment_date' => $new_date,
                    'start_time' => $new_time,
                    'appointment_id' => ['$ne' => $appointment_id],
                    'status' => ['$nin' => ['Cancelled', 'No-show']]
                ]);

                if ($conflict) {
                    throw new RuntimeException(
                        'This slot was just booked by another patient.'
                    );
                }

                // Generate a new token
                $new_token = generate_appointment_token(
                    $doctor_id,
                    $new_date
                );

                // Update only if the appointment remains eligible and unchanged
                $update = $mongoDb->appointments->updateOne(
                    [
                        'appointment_id' => $appointment_id,
                        'patient_id' => (int) $patient_id,
                        'status' => ['$in' => ['Requested', 'Confirmed']],
                        'appointment_date' => $lockedAppointment['appointment_date'],
                        'start_time' => $lockedAppointment['start_time']
                    ],
                    [
                        '$set' => [
                            'appointment_date' => $new_date,
                            'start_time' => $new_time,
                            'token_no' => $new_token
                        ]
                    ]
                );

                if ($update->getModifiedCount() !== 1) {
                    throw new RuntimeException(
                        'Appointment changed before it could be rescheduled. Please try again.'
                    );
                }

                $formattedDate = date('d M Y', strtotime($new_date));
                $formattedTime = date('h:i A', strtotime($new_time));

                create_notification(
                    (int) $_SESSION['user_id'],
                    'reschedule',
                    'Appointment Rescheduled',
                    "Appointment {$appointment['appointment_ref']} " .
                    "has been rescheduled to {$formattedDate} at " .
                    "{$formattedTime}. Your new token is #{$new_token}."
                );

                create_notification(
                    (int) $appointment['doctor_user_id'],
                    'reschedule',
                    'Appointment Rescheduled',
                    "Appointment {$appointment['appointment_ref']} " .
                    "has been rescheduled to {$formattedDate} at " .
                    "{$formattedTime}. Token #{$new_token}."
                );

                audit(
                    (int) $_SESSION['user_id'],
                    "Rescheduled appointment from " .
                    "{$old_date} {$old_time} to " .
                    "{$new_date} {$new_time}; new token #{$new_token}",
                    'appointment',
                    $appointment_id
                );

                flash(
                    'success',
                    "Appointment rescheduled successfully. New token #{$new_token} assigned."
                );

                header('Location: appointments.php');
                exit;
            } catch (Throwable $e) {
                error_log('Appointment Reschedule Error: ' . $e->getMessage());

                flash('danger', $e->getMessage());
            }
        }
    }
}

?>

<div class="card p-4 p-lg-5">

    <span class="eyebrow">
        <i class="bi bi-arrow-repeat"></i>
        Reschedule
    </span>


    <h1 class="h3 fw-bold mt-3">
        <?= h($appointment['appointment_ref']) ?>
    </h1>


    <p class="text-secondary">
        Dr. <?= h($appointment['doctor_name']) ?>
    </p>


    <div class="alert alert-light border mt-3">

        <div class="small text-secondary">
            Current appointment
        </div>

        <strong>
            <?= h($appointment['appointment_date']) ?>
        </strong>

        at

        <strong>
            <?= date(
                'h:i A',
                strtotime($appointment['start_time'])
            ) ?>
        </strong>

        <?php if (
            $appointment['token_no'] !== null
        ): ?>

            <span class="ms-2 badge text-bg-dark">
                Token #<?= h($appointment['token_no']) ?>
            </span>

        <?php endif; ?>

    </div>


    <form
        method="post"
        class="row g-3 mt-2"
    >

        <?= csrf_field() ?>


        <div class="col-md-6">

            <label
                for="date"
                class="form-label fw-semibold"
            >
                New date
            </label>

            <input
                id="date"
                name="date"
                type="date"
                class="form-control"
                min="<?= date('Y-m-d') ?>"
                required
            >

        </div>


        <div class="col-md-6">

            <label
                for="time"
                class="form-label fw-semibold"
            >
                Available slot
            </label>

            <select
                id="time"
                name="time"
                class="form-select"
                required
            >

                <option value="">
                    Select a date first
                </option>

            </select>

        </div>


        <div class="col-12">

            <button
                type="submit"
                class="btn btn-primary"
            >

                <i class="bi bi-arrow-repeat me-1"></i>

                Save New Slot

            </button>


            <a
                href="appointments.php"
                class="btn btn-outline-secondary ms-1"
            >
                Cancel
            </a>

        </div>

    </form>

</div>


<script>

const doctorId =
    <?= json_encode((int)$appointment['doctor_id']) ?>;

const branchId =
    <?= json_encode((int)$appointment['branch_id']) ?>;

const dateEl =
    document.getElementById('date');

const timeEl =
    document.getElementById('time');


dateEl.addEventListener(
    'change',
    async function () {

        timeEl.innerHTML =
            '<option value="">Loading...</option>';


        try {

            const response =
                await fetch(
                    '../api/slots.php?doctor_id=' +
                    doctorId +
                    '&date=' +
                    encodeURIComponent(
                        dateEl.value
                    ) +
                    '&branch_id=' +
                    branchId
                );


            const data =
                await response.json();


            timeEl.innerHTML = '';


            if (
                !data.success ||
                !Array.isArray(data.slots)
            ) {

                timeEl.innerHTML =
                    '<option value="">Unable to load slots</option>';

                return;
            }


            data.slots
                .filter(
                    slot =>
                        slot.available &&
                        Number(slot.branch_id) === branchId
                )
                .forEach(
                    slot => {

                        const option =
                            document.createElement(
                                'option'
                            );

                        option.value =
                            slot.time;

                        option.textContent =
                            slot.time;

                        timeEl.appendChild(
                            option
                        );
                    }
                );


            if (!timeEl.options.length) {

                timeEl.innerHTML =
                    '<option value="">No available slots</option>';
            }


        } catch (error) {

            timeEl.innerHTML =
                '<option value="">Unable to load slots</option>';
        }

    }
);

</script>


<?php require '../includes/footer.php'; ?>