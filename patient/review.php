<?php

$page_title = 'Review';
$asset_prefix = '../';

require '../includes/functions.php';
require '../includes/auth.php';
require_role('patient');

$patient_id = get_patient_id(
    (int)$_SESSION['user_id']
);

$appointment_id = (int)($_GET['id'] ?? 0);

verify_csrf();


/*
|--------------------------------------------------------------------------
| Get Completed Appointment
|--------------------------------------------------------------------------
*/

$appointmentDoc = $mongoDb->appointments->findOne([
    'appointment_id' => $appointment_id,
    'patient_id' => (int) $patient_id,
    'status' => 'Completed'
]);

$appointment = null;

if ($appointmentDoc) {
    $appointment = (array) $appointmentDoc;

    $doctor = $mongoDb->doctors->findOne([
        'doctor_id' => (int) ($appointment['doctor_id'] ?? 0)
    ]);

    $doctorData = $doctor ? (array) $doctor : [];

    $doctorUser = $mongoDb->users->findOne([
        'user_id' => (int) ($doctorData['user_id'] ?? 0)
    ]);

    $appointment['doctor_user_id'] =
        (int) ($doctorData['user_id'] ?? 0);

    $appointment['doctor_name'] = $doctorUser
        ? (((array) $doctorUser)['name'] ?? 'Doctor')
        : 'Doctor';
}


/*
|--------------------------------------------------------------------------
| Appointment Validation
|--------------------------------------------------------------------------
*/

if (!$appointment) {

    require '../includes/header.php';

    echo '
        <div class="alert alert-danger">
            <i class="bi bi-exclamation-circle me-1"></i>
            Only completed appointments can be reviewed.
        </div>
    ';

    require '../includes/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Check Existing Review
|--------------------------------------------------------------------------
*/

$existingReviewDoc = $mongoDb->reviews->findOne([
    'appointment_id' => $appointment_id,
    'patient_id' => (int) $patient_id
]);

$existing_review = $existingReviewDoc
    ? (array) $existingReviewDoc
    : null;


if ($existing_review) {

    require '../includes/header.php';

    echo '
        <div class="alert alert-info">
            <i class="bi bi-info-circle me-1"></i>
            You already reviewed this appointment.
        </div>
    ';

    require '../includes/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Submit Review
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
) {

    $rating = (int)($_POST['rating'] ?? 0);

    $review_text = trim(
        $_POST['review_text'] ?? ''
    );


    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if (
        $rating < 1 ||
        $rating > 5
    ) {

        flash(
            'danger',
            'Please select a rating between 1 and 5.'
        );

    } elseif ($review_text === '') {

        flash(
            'danger',
            'Please write a review.'
        );

    } elseif (mb_strlen($review_text) > 1000) {

        flash(
            'danger',
            'Review cannot exceed 1000 characters.'
        );

    } else {


        try {
            // Verify the appointment is still completed
            $locked = $mongoDb->appointments->findOne([
                'appointment_id' => $appointment_id,
                'patient_id' => (int) $patient_id,
                'status' => 'Completed'
            ]);

            if (!$locked) {
                throw new RuntimeException(
                    'Only completed appointments can be reviewed.'
                );
            }

            $locked = (array) $locked;

            // Check for an existing review before inserting
            $duplicate = $mongoDb->reviews->findOne([
                'appointment_id' => $appointment_id,
                'patient_id' => (int) $patient_id
            ]);

            if ($duplicate) {
                throw new RuntimeException(
                    'You have already reviewed this appointment.'
                );
            }

            $reviewDocument = [
                'appointment_id' => $appointment_id,
                'patient_id' => (int) $patient_id,
                'doctor_id' => (int) $locked['doctor_id'],
                'rating' => $rating,
                'review_text' => $review_text,
                'created_at' => new MongoDB\BSON\UTCDateTime()
            ];

            $mongoDb->reviews->insertOne($reviewDocument);

            try {
                if (function_exists('create_notification')) {
                    create_notification(
                        (int) $appointment['doctor_user_id'],
                        'review',
                        'New Patient Review',
                        "You received a {$rating}-star review " .
                        "for appointment {$appointment['appointment_ref']}."
                    );
                }
            } catch (Throwable $e) {
                error_log('Review Notification Error: ' . $e->getMessage());
            }

            try {
                if (function_exists('audit')) {
                    audit(
                        (int) $_SESSION['user_id'],
                        "Submitted {$rating}-star review",
                        'appointment',
                        $appointment_id
                    );
                }
            } catch (Throwable $e) {
                error_log('Review Audit Error: ' . $e->getMessage());
            }

            flash(
                'success',
                'Your review has been submitted successfully.'
            );

            header('Location: appointments.php');
            exit;
        } catch (Throwable $e) {
            error_log('Review Submission Error: ' . $e->getMessage());

            flash('danger', $e->getMessage());
        }
    }
}

require '../includes/header.php';

?>

<div class="container-fluid py-4">


    <!-- Header -->

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">

        <div>

            <span class="badge rounded-pill bg-primary-subtle text-primary mb-2">
                Patient Portal
            </span>

            <h2 class="fw-bold mb-1">
                Write a Review
            </h2>

            <p class="text-secondary mb-0">
                Share your experience with your doctor.
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


    <div class="row justify-content-center">

        <div class="col-lg-7">


            <!-- Doctor Card -->

            <div class="card border-0 shadow-sm mb-4">

                <div class="card-body p-4">

                    <div class="d-flex align-items-center gap-3">

                        <div
                            class="rounded-circle bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                            style="width:58px;height:58px;"
                        >

                            <i class="bi bi-person-badge fs-3"></i>

                        </div>


                        <div>

                            <div class="small text-secondary">
                                Reviewing
                            </div>

                            <h5 class="fw-bold mb-1">
                                Dr. <?= h($appointment['doctor_name']) ?>
                            </h5>

                            <div class="text-secondary small">

                                Appointment
                                <?= h($appointment['appointment_ref']) ?>

                                ·

                                <?= date(
                                    'd M Y',
                                    strtotime(
                                        $appointment['appointment_date']
                                    )
                                ) ?>

                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Review Form -->

            <div class="card border-0 shadow-sm">

                <div class="card-body p-4 p-lg-5">

                    <form method="post">

                        <?= csrf_field() ?>


                        <!-- Rating -->

                        <div class="mb-4">

                            <label
                                class="form-label fw-semibold"
                            >
                                Your Rating
                            </label>

                            <p class="text-secondary small">
                                How would you rate your experience?
                            </p>


                            <div
                                class="rating-wrapper"
                                style="font-size:2rem;"
                            >

                                <label
                                    class="me-1"
                                    style="cursor:pointer;"
                                >

                                    <input
                                        type="radio"
                                        name="rating"
                                        value="1"
                                        class="d-none"
                                        required
                                    >

                                    <span
                                        class="rating-star"
                                        data-value="1"
                                    >
                                        ★
                                    </span>

                                </label>


                                <label
                                    class="me-1"
                                    style="cursor:pointer;"
                                >

                                    <input
                                        type="radio"
                                        name="rating"
                                        value="2"
                                        class="d-none"
                                    >

                                    <span
                                        class="rating-star"
                                        data-value="2"
                                    >
                                        ★
                                    </span>

                                </label>


                                <label
                                    class="me-1"
                                    style="cursor:pointer;"
                                >

                                    <input
                                        type="radio"
                                        name="rating"
                                        value="3"
                                        class="d-none"
                                    >

                                    <span
                                        class="rating-star"
                                        data-value="3"
                                    >
                                        ★
                                    </span>

                                </label>


                                <label
                                    class="me-1"
                                    style="cursor:pointer;"
                                >

                                    <input
                                        type="radio"
                                        name="rating"
                                        value="4"
                                        class="d-none"
                                    >

                                    <span
                                        class="rating-star"
                                        data-value="4"
                                    >
                                        ★
                                    </span>

                                </label>


                                <label
                                    class="me-1"
                                    style="cursor:pointer;"
                                >

                                    <input
                                        type="radio"
                                        name="rating"
                                        value="5"
                                        class="d-none"
                                    >

                                    <span
                                        class="rating-star"
                                        data-value="5"
                                    >
                                        ★
                                    </span>

                                </label>

                            </div>


                            <div
                                id="ratingText"
                                class="small text-secondary mt-2"
                            >
                                Select a rating
                            </div>

                        </div>


                        <!-- Review -->

                        <div class="mb-4">

                            <label
                                for="review_text"
                                class="form-label fw-semibold"
                            >
                                Your Review
                            </label>

                            <textarea
                                id="review_text"
                                name="review_text"
                                class="form-control"
                                rows="6"
                                maxlength="1000"
                                placeholder="Tell us about your experience..."
                                required
                            ></textarea>

                            <div class="form-text">
                                Maximum 1000 characters.
                            </div>

                        </div>


                        <!-- Submit -->

                        <button
                            type="submit"
                            class="btn btn-primary w-100"
                        >

                            <i class="bi bi-send me-1"></i>

                            Submit Review

                        </button>

                    </form>

                </div>

            </div>

        </div>

    </div>

</div>


<script>

const stars =
    document.querySelectorAll('.rating-star');

const ratingText =
    document.getElementById('ratingText');

const labels = {
    1: 'Very Poor',
    2: 'Poor',
    3: 'Average',
    4: 'Good',
    5: 'Excellent'
};


function updateStars(value) {

    stars.forEach(
        star => {

            const starValue =
                Number(star.dataset.value);

            star.style.opacity =
                starValue <= value
                    ? '1'
                    : '0.25';
        }
    );


    ratingText.textContent =
        labels[value] || 'Select a rating';
}


stars.forEach(
    star => {

        star.addEventListener(
            'click',
            function () {

                const value =
                    Number(this.dataset.value);

                updateStars(value);

            }
        );

    }
);

</script>


<?php require '../includes/footer.php'; ?>