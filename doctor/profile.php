<?php

$page_title = 'Doctor Profile';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('doctor');

$doctor_id = get_doctor_id((int) $_SESSION['user_id']);
$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');
$specializationsCollection = $mongoDb->selectCollection('specializations');
$reviewsCollection = $mongoDb->selectCollection('reviews');
$patientsCollection = $mongoDb->selectCollection('patients');

if (!$doctor_id) {
    echo '<div class="alert alert-danger">Doctor profile not found.</div>';
    require '../includes/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Handle Profile Update
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $specialization_id = (int) ($_POST['specialization_id'] ?? 0);
    $qualification = trim($_POST['qualification'] ?? '');
    $experience = max(0, (int) ($_POST['experience'] ?? 0));

    $consultation_fee = max(
        0,
        (float) ($_POST['consultation_fee'] ?? 0)
    );


    if ($specialization_id <= 0) {

        flash(
            'danger',
            'Please select a specialization.'
        );

    } elseif ($qualification === '') {

        flash(
            'danger',
            'Qualification is required.'
        );

    } else {

        $result = $doctorsCollection->updateOne(
            ['doctor_id' => (int) $doctor_id],
            [
                '$set' => [
                    'specialization_id' => $specialization_id,
                    'qualification' => $qualification,
                    'experience' => $experience,
                    'consultation_fee' => $consultation_fee,
                ],
            ]
        );


        audit(
            (int) $_SESSION['user_id'],
            'UPDATE',
            'doctor_profile',
            $doctor_id
        );


        flash(
            'success',
            'Your professional profile has been updated successfully.'
        );
    }


    header('Location: profile.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Doctor Information
|--------------------------------------------------------------------------
*/

$doctorDocument = $doctorsCollection->findOne([
    'doctor_id' => (int) $doctor_id,
]);

$doctor = null;

if ($doctorDocument !== null) {
    $doctor = $doctorDocument->getArrayCopy();

    $userDocument = $usersCollection->findOne([
        'user_id' => (int) ($doctor['user_id'] ?? 0),
    ]);

    $specializationDocument = $specializationsCollection->findOne([
        'specialization_id' => (int) ($doctor['specialization_id'] ?? 0),
    ]);

    $doctor['name'] = $userDocument['name'] ?? '';
    $doctor['email'] = $userDocument['email'] ?? '';
    $doctor['account_status'] = $userDocument['status'] ?? 'unknown';
    $doctor['specialization'] = $specializationDocument['name'] ?? '';
}

if (!$doctor) {
    echo '<div class="alert alert-danger">Doctor information could not be loaded.</div>';
    require '../includes/footer.php';
    exit;
}

$feeValue = $doctor['consultation_fee'] ?? 0;

$doctor['consultation_fee'] = $feeValue instanceof MongoDB\BSON\Decimal128
    ? (string) $feeValue
    : (string) $feeValue;


/*
|--------------------------------------------------------------------------
| Doctor Rating & Reviews
|--------------------------------------------------------------------------
*/

$ratingCursor = $reviewsCollection->find([
    'doctor_id' => (int) $doctor_id,
]);

$ratingTotal = 0;
$review_count = 0;

foreach ($ratingCursor as $ratingDocument) {
    $ratingTotal += (float) ($ratingDocument['rating'] ?? 0);
    $review_count++;
}

$average_rating = $review_count > 0
    ? round($ratingTotal / $review_count, 1)
    : 0;


/*
|--------------------------------------------------------------------------
| Recent Patient Reviews
|--------------------------------------------------------------------------
*/

$reviews = [];

$reviewCursor = $reviewsCollection->find(
    ['doctor_id' => (int) $doctor_id],
    [
        'sort' => ['created_at' => -1],
        'limit' => 10,
    ]
);

foreach ($reviewCursor as $reviewDocument) {
    $review = $reviewDocument->getArrayCopy();

    $patientDocument = $patientsCollection->findOne([
        'patient_id' => (int) ($review['patient_id'] ?? 0),
    ]);

    $patientUserDocument = null;

    if ($patientDocument !== null) {
        $patientUserDocument = $usersCollection->findOne([
            'user_id' => (int) ($patientDocument['user_id'] ?? 0),
        ]);
    }

    $review['patient_name'] = $patientUserDocument['name'] ?? 'Patient';

    $reviews[] = $review;
}


/*
|--------------------------------------------------------------------------
| Specializations
|--------------------------------------------------------------------------
*/

$specializations = [];

$specializationCursor = $specializationsCollection->find(
    [],
    ['sort' => ['name' => 1]]
);

foreach ($specializationCursor as $specializationDocument) {
    $specializations[] = $specializationDocument->getArrayCopy();
}


/*
|--------------------------------------------------------------------------
| Verification State
|--------------------------------------------------------------------------
*/

$verification = $doctor['verification_status'];

$verification_class = 'pending';
$verification_icon = 'bi-hourglass-split';
$verification_title = 'Pending Verification';

$verification_text =
    'Your professional profile is waiting for administrator verification.';


if ($verification === 'Approved') {

    $verification_class = 'approved';
    $verification_icon = 'bi-patch-check-fill';
    $verification_title = 'Verified Doctor';

    $verification_text =
        'Your professional profile has been verified by the administrator.';

} elseif ($verification === 'Rejected') {

    $verification_class = 'rejected';
    $verification_icon = 'bi-x-circle-fill';
    $verification_title = 'Verification Rejected';

    $verification_text =
        'Your verification was rejected. Please contact the administrator.';
}

require '../includes/header.php';

?>

<?php show_flash(); ?>


<!-- =========================================================
     Page Header
========================================================= -->

<div class="profile-page-header">

    <div>

        <div class="profile-page-label">
            DOCTOR PORTAL
        </div>

        <h1>
            Professional Profile
        </h1>

        <p>
            Manage your professional information and account details.
        </p>

    </div>


    <a
        href="dashboard.php"
        class="btn btn-outline-primary"
    >

        <i class="bi bi-arrow-left me-2"></i>

        Dashboard

    </a>

</div>


<!-- =========================================================
     Verification Banner
========================================================= -->

<div class="profile-verification <?= h($verification_class) ?>">

    <div class="profile-verification-icon">

        <i class="bi <?= h($verification_icon) ?>"></i>

    </div>


    <div class="profile-verification-content">

        <strong>
            <?= h($verification_title) ?>
        </strong>

        <span>
            <?= h($verification_text) ?>
        </span>

    </div>

</div>


<div class="row g-4">


    <!-- =====================================================
         Left — Profile Summary
    ====================================================== -->

    <div class="col-xl-4">

        <div class="profile-card profile-summary-card">


            <div class="profile-avatar">

                <?= h(
                    strtoupper(
                        substr(
                            $doctor['name'],
                            0,
                            1
                        )
                    )
                ) ?>

            </div>


            <h2>
                Dr. <?= h($doctor['name']) ?>
            </h2>


            <p class="profile-specialization">

                <?= h(
                    $doctor['specialization']
                    ?: 'Specialization not assigned'
                ) ?>

            </p>


            <div class="profile-status">

                <span class="status-dot"></span>

                Account:

                <strong>
                    <?= h(
                        ucfirst(
                            $doctor['account_status']
                        )
                    ) ?>
                </strong>

            </div>


            <div class="profile-summary-divider"></div>


            <!-- Email -->

            <div class="profile-summary-item">

                <i class="bi bi-envelope"></i>

                <div>

                    <span>
                        Email Address
                    </span>

                    <strong>
                        <?= h($doctor['email']) ?>
                    </strong>

                </div>

            </div>


            <!-- Verification -->

            <div class="profile-summary-item">

                <i class="bi bi-patch-check"></i>

                <div>

                    <span>
                        Verification
                    </span>

                    <strong>
                        <?= h($verification) ?>
                    </strong>

                </div>

            </div>


            <!-- Experience -->

            <div class="profile-summary-item">

                <i class="bi bi-briefcase"></i>

                <div>

                    <span>
                        Experience
                    </span>

                    <strong>
                        <?= h($doctor['experience']) ?> years
                    </strong>

                </div>

            </div>


            <!-- Consultation Fee -->

            <div class="profile-summary-item">

                <i class="bi bi-cash-stack"></i>

                <div>

                    <span>
                        Consultation Fee
                    </span>

                    <strong>
                        ৳<?= number_format(
                            (float) $doctor['consultation_fee'],
                            2
                        ) ?>
                    </strong>

                </div>

            </div>


            <!-- =================================================
                 Patient Rating
            ================================================== -->

            <div class="profile-summary-item">

                <i class="bi bi-star-fill"></i>

                <div>

                    <span>
                        Patient Rating
                    </span>

                    <strong>

                        <?php if ($review_count > 0): ?>

                            <span class="profile-rating-stars">

                                <?php

                                $summary_rounded_rating =
                                    (int) round($average_rating);

                                for (
                                    $i = 1;
                                    $i <= 5;
                                    $i++
                                ):

                                ?>

                                    <?= $i <= $summary_rounded_rating
                                        ? '★'
                                        : '☆' ?>

                                <?php endfor; ?>

                            </span>

                            <?= h(
                                number_format(
                                    $average_rating,
                                    1
                                )
                            ) ?>

                            <small class="text-secondary">

                                (
                                <?= h($review_count) ?>
                                reviews
                                )

                            </small>

                        <?php else: ?>

                            No reviews yet

                        <?php endif; ?>

                    </strong>

                </div>

            </div>


        </div>


        <!-- Profile Note -->

        <div class="profile-info-card mt-4">

            <div class="profile-info-icon">

                <i class="bi bi-shield-check"></i>

            </div>


            <div>

                <strong>
                    Profile Verification
                </strong>

                <p>

                    Your professional information may be reviewed
                    by the administrator before appearing to patients.

                </p>

            </div>

        </div>

    </div>


    <!-- =====================================================
         Right — Edit Profile
    ====================================================== -->

    <div class="col-xl-8">

        <div class="profile-card">


            <div class="profile-card-header">

                <div>

                    <span>
                        PROFESSIONAL INFORMATION
                    </span>

                    <h2>
                        Edit Your Profile
                    </h2>

                    <p>
                        Keep your professional information accurate
                        and up to date.
                    </p>

                </div>


                <div class="profile-header-icon">

                    <i class="bi bi-person-vcard"></i>

                </div>

            </div>


            <form
                method="POST"
                action=""
                class="profile-form"
            >

                <?= csrf_field() ?>


                <!-- Basic Account Information -->

                <div class="profile-section">

                    <div class="profile-section-title">

                        <i class="bi bi-person"></i>

                        <div>

                            <strong>
                                Account Information
                            </strong>

                            <span>
                                Basic information connected to your account
                            </span>

                        </div>

                    </div>


                    <div class="row g-3">


                        <div class="col-md-6">

                            <label>
                                Full Name
                            </label>

                            <input
                                type="text"
                                class="form-control"
                                value="<?= h($doctor['name']) ?>"
                                disabled
                            >

                            <small>
                                Name is managed through your account.
                            </small>

                        </div>


                        <div class="col-md-6">

                            <label>
                                Email Address
                            </label>

                            <input
                                type="email"
                                class="form-control"
                                value="<?= h($doctor['email']) ?>"
                                disabled
                            >

                            <small>
                                Email address cannot be changed here.
                            </small>

                        </div>

                    </div>

                </div>


                <!-- Professional Information -->

                <div class="profile-section">

                    <div class="profile-section-title">

                        <i class="bi bi-mortarboard"></i>

                        <div>

                            <strong>
                                Professional Information
                            </strong>

                            <span>
                                Details patients will use to understand your expertise
                            </span>

                        </div>

                    </div>


                    <div class="row g-3">


                        <!-- Specialization -->

                        <div class="col-md-6">

                            <label for="specialization_id">

                                Specialization

                                <span class="required">
                                    *
                                </span>

                            </label>


                            <select
                                name="specialization_id"
                                id="specialization_id"
                                class="form-select"
                                required
                            >

                                <option value="">
                                    Select specialization
                                </option>


                                <?php foreach (
                                    $specializations
                                    as $specialization
                                ): ?>

                                    <option
                                        value="<?= (int) $specialization['specialization_id'] ?>"
                                        <?= (int) $doctor['specialization_id']
                                            === (int) $specialization['specialization_id']
                                            ? 'selected'
                                            : '' ?>
                                    >

                                        <?= h(
                                            $specialization['name']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- Qualification -->

                        <div class="col-md-6">

                            <label for="qualification">

                                Qualification

                                <span class="required">
                                    *
                                </span>

                            </label>


                            <input
                                type="text"
                                name="qualification"
                                id="qualification"
                                class="form-control"
                                value="<?= h($doctor['qualification']) ?>"
                                placeholder="e.g. MBBS, FCPS, MD"
                                required
                            >

                        </div>


                        <!-- Experience -->

                        <div class="col-md-6">

                            <label for="experience">

                                Experience

                            </label>


                            <div class="input-with-suffix">

                                <input
                                    type="number"
                                    name="experience"
                                    id="experience"
                                    class="form-control"
                                    value="<?= h($doctor['experience']) ?>"
                                    min="0"
                                    max="80"
                                    placeholder="e.g. 5"
                                >


                                <span>
                                    Years
                                </span>

                            </div>

                        </div>


                        <!-- Consultation Fee -->

                        <div class="col-md-6">

                            <label for="consultation_fee">

                                Consultation Fee

                            </label>


                            <div class="input-with-prefix">

                                <span>
                                    ৳
                                </span>


                                <input
                                    type="number"
                                    name="consultation_fee"
                                    id="consultation_fee"
                                    class="form-control"
                                    value="<?= h($doctor['consultation_fee']) ?>"
                                    min="0"
                                    step="0.01"
                                    placeholder="e.g. 500"
                                >

                            </div>

                        </div>

                    </div>

                </div>


                <!-- Save -->

                <div class="profile-form-footer">

                    <div class="save-note">

                        <i class="bi bi-info-circle"></i>

                        Changes are saved to your professional profile.

                    </div>


                    <button
                        type="submit"
                        class="btn btn-primary save-profile-btn"
                    >

                        <i class="bi bi-check2-circle me-2"></i>

                        Save Changes

                    </button>

                </div>


            </form>

        </div>

    </div>

</div>


<!-- =========================================================
     Patient Reviews
========================================================= -->

<div class="row mt-4">

    <div class="col-12">

        <div class="profile-card">


            <div class="profile-card-header">

                <div>

                    <span>
                        PATIENT FEEDBACK
                    </span>

                    <h2>
                        Ratings &amp; Reviews
                    </h2>

                    <p>
                        Feedback submitted by patients after completed appointments.
                    </p>

                </div>


                <div class="profile-header-icon">

                    <i class="bi bi-star"></i>

                </div>

            </div>


            <!-- Rating Summary -->

            <div class="row g-4 py-4">


                <div class="col-md-4">

                    <div class="review-rating-summary text-center">

                        <div class="display-4 fw-bold">

                            <?= h(
                                number_format(
                                    $average_rating,
                                    1
                                )
                            ) ?>

                        </div>


                        <div
                            class="review-stars"
                            aria-label="Average rating"
                        >

                            <?php

                            $rounded_rating =
                                (int) round($average_rating);

                            for (
                                $i = 1;
                                $i <= 5;
                                $i++
                            ):

                            ?>

                                <?= $i <= $rounded_rating
                                    ? '★'
                                    : '☆' ?>

                            <?php endfor; ?>

                        </div>


                        <div class="text-secondary small mt-1">

                            <?= h($review_count) ?>

                            review<?= $review_count === 1 ? '' : 's' ?>

                        </div>

                    </div>

                </div>


                <!-- Review List -->

                <div class="col-md-8">

                    <?php if (!$reviews): ?>


                        <div class="review-empty text-center py-4">

                            <i class="bi bi-chat-square-text"></i>


                            <h6 class="fw-bold mt-3">
                                No reviews yet
                            </h6>


                            <p class="text-secondary small mb-0">

                                Patient reviews will appear here
                                after completed appointments.

                            </p>

                        </div>


                    <?php else: ?>


                        <div class="review-list">


                            <?php foreach (
                                $reviews
                                as $review
                            ): ?>


                                <div class="review-item">


                                    <div class="review-item-top">


                                        <div class="review-patient">


                                            <div class="review-patient-avatar">

                                                <?= h(
                                                    strtoupper(
                                                        substr(
                                                            $review['patient_name'],
                                                            0,
                                                            1
                                                        )
                                                    )
                                                ) ?>

                                            </div>


                                            <div>

                                                <div class="fw-bold">

                                                    <?= h(
                                                        $review['patient_name']
                                                    ) ?>

                                                </div>


                                                <div class="review-stars-small">

                                                    <?php

                                                    $patient_rating =
                                                        (int) $review['rating'];

                                                    for (
                                                        $i = 1;
                                                        $i <= 5;
                                                        $i++
                                                    ):

                                                    ?>

                                                        <?= $i <= $patient_rating
                                                            ? '★'
                                                            : '☆' ?>

                                                    <?php endfor; ?>

                                                </div>

                                            </div>

                                        </div>


                                        <small class="text-secondary">

                                            <?= h(
                                                date(
                                                    'd M Y',
                                                    strtotime(
                                                        $review['created_at']
                                                    )
                                                )
                                            ) ?>

                                        </small>

                                    </div>


                                    <p class="review-text">

                                        <?= h(
                                            $review['review_text']
                                        ) ?>

                                    </p>

                                </div>


                            <?php endforeach; ?>


                        </div>


                    <?php endif; ?>

                </div>

            </div>

        </div>

    </div>

</div>


<style>

/* =========================================================
   Profile Header
========================================================= */

.profile-page-header {

    display: flex;

    justify-content: space-between;

    align-items: flex-end;

    gap: 20px;

    margin-bottom: 24px;
}


.profile-page-label {

    color: var(--primary);

    font-size: .7rem;

    font-weight: 800;

    letter-spacing: .12em;

    margin-bottom: 6px;
}


.profile-page-header h1 {

    margin: 0;

    font-size: clamp(1.8rem, 4vw, 2.6rem);

    font-weight: 800;

    letter-spacing: -.04em;
}


.profile-page-header p {

    margin: 7px 0 0;

    color: var(--muted);
}


/* =========================================================
   Verification
========================================================= */

.profile-verification {

    display: flex;

    align-items: center;

    gap: 14px;

    padding: 15px 18px;

    border-radius: 16px;

    margin-bottom: 22px;

    border: 1px solid;
}


.profile-verification.approved {

    background: #effcf7;

    border-color: #c8f0df;
}


.profile-verification.pending {

    background: #fff9eb;

    border-color: #f5dfaa;
}


.profile-verification.rejected {

    background: #fff1f1;

    border-color: #f3caca;
}


.profile-verification-icon {

    width: 42px;

    height: 42px;

    flex: 0 0 42px;

    display: grid;

    place-items: center;

    border-radius: 12px;

    font-size: 18px;
}


.approved .profile-verification-icon {

    background: #d9f7eb;

    color: #0b9270;
}


.pending .profile-verification-icon {

    background: #ffedc5;

    color: #b97900;
}


.rejected .profile-verification-icon {

    background: #ffdede;

    color: #c53d3d;
}


.profile-verification-content {

    display: flex;

    flex-direction: column;

    gap: 2px;
}


.profile-verification-content strong {

    font-size: .86rem;
}


.profile-verification-content span {

    color: var(--muted);

    font-size: .74rem;
}


/* =========================================================
   Cards
========================================================= */

.profile-card {

    background: #fff;

    border: 1px solid var(--border);

    border-radius: 20px;

    box-shadow: var(--shadow);

    padding: 24px;
}


.profile-summary-card {

    text-align: center;
}


.profile-avatar {

    width: 92px;

    height: 92px;

    margin: 2px auto 16px;

    display: grid;

    place-items: center;

    border-radius: 27px;

    background: linear-gradient(
        135deg,
        #eaf3ff,
        #e8faf6
    );

    color: var(--primary);

    font-size: 2rem;

    font-weight: 800;
}


.profile-summary-card h2 {

    margin: 0;

    font-size: 1.25rem;

    font-weight: 800;
}


.profile-specialization {

    color: var(--primary);

    font-size: .8rem;

    font-weight: 700;

    margin: 5px 0 12px;
}


.profile-status {

    display: inline-flex;

    align-items: center;

    gap: 6px;

    color: var(--muted);

    font-size: .7rem;
}


.profile-status strong {

    color: var(--navy);
}


.status-dot {

    width: 7px;

    height: 7px;

    border-radius: 50%;

    background: #16a37a;
}


.profile-summary-divider {

    height: 1px;

    background: var(--border);

    margin: 20px 0 6px;
}


.profile-summary-item {

    display: flex;

    align-items: flex-start;

    gap: 11px;

    text-align: left;

    padding: 13px 0;

    border-bottom: 1px solid var(--border);
}


.profile-summary-item:last-child {

    border-bottom: 0;

    padding-bottom: 0;
}


.profile-summary-item > i {

    width: 32px;

    height: 32px;

    flex: 0 0 32px;

    display: grid;

    place-items: center;

    border-radius: 9px;

    background: #edf5ff;

    color: var(--primary);

    font-size: .8rem;
}


.profile-summary-item span {

    display: block;

    color: var(--muted);

    font-size: .65rem;

    margin-bottom: 2px;
}


.profile-summary-item strong {

    display: block;

    font-size: .76rem;

    word-break: break-word;
}


/* =========================================================
   Patient Rating
========================================================= */

.profile-rating-stars {

    color: #f5a623;

    font-size: .78rem;

    letter-spacing: 1px;

    margin-right: 3px;
}


/* =========================================================
   Info Card
========================================================= */

.profile-info-card {

    display: flex;

    align-items: flex-start;

    gap: 12px;

    padding: 17px;

    border-radius: 16px;

    background: #f5f9fd;

    border: 1px solid #e2ebf4;
}


.profile-info-icon {

    width: 38px;

    height: 38px;

    flex: 0 0 38px;

    display: grid;

    place-items: center;

    border-radius: 11px;

    background: #e8f4ff;

    color: var(--primary);
}


.profile-info-card strong {

    display: block;

    font-size: .78rem;
}


.profile-info-card p {

    color: var(--muted);

    font-size: .68rem;

    line-height: 1.5;

    margin: 4px 0 0;
}


/* =========================================================
   Form Header
========================================================= */

.profile-card-header {

    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    gap: 20px;

    padding-bottom: 20px;

    border-bottom: 1px solid var(--border);
}


.profile-card-header > div:first-child > span {

    color: var(--primary);

    font-size: .65rem;

    font-weight: 800;

    letter-spacing: .1em;
}


.profile-card-header h2 {

    margin: 4px 0 4px;

    font-size: 1.3rem;

    font-weight: 800;
}


.profile-card-header p {

    color: var(--muted);

    font-size: .74rem;

    margin: 0;
}


.profile-header-icon {

    width: 46px;

    height: 46px;

    flex: 0 0 46px;

    display: grid;

    place-items: center;

    border-radius: 13px;

    background: #edf5ff;

    color: var(--primary);

    font-size: 19px;
}


/* =========================================================
   Form
========================================================= */

.profile-section {

    padding: 22px 0;

    border-bottom: 1px solid var(--border);
}


.profile-section:last-of-type {

    border-bottom: 0;
}


.profile-section-title {

    display: flex;

    align-items: flex-start;

    gap: 10px;

    margin-bottom: 18px;
}


.profile-section-title > i {

    width: 34px;

    height: 34px;

    display: grid;

    place-items: center;

    border-radius: 10px;

    background: #edf5ff;

    color: var(--primary);

    font-size: .85rem;
}


.profile-section-title strong {

    display: block;

    font-size: .82rem;
}


.profile-section-title span {

    display: block;

    color: var(--muted);

    font-size: .66rem;

    margin-top: 2px;
}


.profile-form label {

    display: block;

    color: var(--navy);

    font-size: .74rem;

    font-weight: 700;

    margin-bottom: 6px;
}


.profile-form .required {

    color: #dc4040;
}


.profile-form .form-control,
.profile-form .form-select {

    min-height: 44px;

    border-color: #d9e3ed;

    border-radius: 10px;

    font-size: .8rem;
}


.profile-form .form-control:focus,
.profile-form .form-select:focus {

    border-color: #7db4ff;

    box-shadow: 0 0 0 .2rem rgba(22,119,255,.09);
}


.profile-form .form-control:disabled {

    background: #f6f8fa;

    color: #6d7b8c;
}


.profile-form small {

    display: block;

    color: var(--muted);

    font-size: .61rem;

    margin-top: 5px;
}


/* =========================================================
   Input Prefix / Suffix
========================================================= */

.input-with-prefix,
.input-with-suffix {

    position: relative;
}


.input-with-prefix > span {

    position: absolute;

    left: 13px;

    top: 50%;

    transform: translateY(-50%);

    color: var(--primary);

    font-weight: 800;

    z-index: 2;
}


.input-with-prefix input {

    padding-left: 31px;
}


.input-with-suffix > span {

    position: absolute;

    right: 12px;

    top: 50%;

    transform: translateY(-50%);

    color: var(--muted);

    font-size: .7rem;

    font-weight: 700;

    z-index: 2;
}


.input-with-suffix input {

    padding-right: 58px;
}


/* =========================================================
   Form Footer
========================================================= */

.profile-form-footer {

    display: flex;

    justify-content: space-between;

    align-items: center;

    gap: 15px;

    padding-top: 20px;
}


.save-note {

    display: flex;

    align-items: center;

    gap: 6px;

    color: var(--muted);

    font-size: .67rem;
}


.save-note i {

    color: var(--primary);
}


.save-profile-btn {

    min-width: 145px;
}


/* =========================================================
   Patient Reviews
========================================================= */

.review-rating-summary {

    padding: 22px;

    border: 1px solid var(--border);

    border-radius: 16px;

    background: #f8fbff;
}


.review-rating-summary .display-4 {

    letter-spacing: -.05em;
}


.review-stars {

    color: #f5a623;

    font-size: 1.35rem;

    letter-spacing: 2px;

    line-height: 1.2;
}


.review-list {

    display: flex;

    flex-direction: column;

    gap: 14px;

    max-height: 420px;

    overflow-y: auto;

    padding-right: 4px;
}


.review-item {

    padding: 16px;

    border: 1px solid var(--border);

    border-radius: 14px;

    background: #fff;
}


.review-item-top {

    display: flex;

    justify-content: space-between;

    align-items: flex-start;

    gap: 15px;
}


.review-patient {

    display: flex;

    align-items: center;

    gap: 10px;
}


.review-patient-avatar {

    width: 36px;

    height: 36px;

    flex: 0 0 36px;

    display: grid;

    place-items: center;

    border-radius: 11px;

    background: #edf5ff;

    color: var(--primary);

    font-size: .78rem;

    font-weight: 800;
}


.review-stars-small {

    color: #f5a623;

    font-size: .75rem;

    letter-spacing: 1px;

    margin-top: 2px;
}


.review-text {

    color: var(--muted);

    font-size: .78rem;

    line-height: 1.6;

    margin: 12px 0 0;
}


.review-empty i {

    font-size: 2rem;

    color: var(--muted);
}


/* =========================================================
   Responsive
========================================================= */

@media (max-width: 768px) {

    .profile-page-header {

        align-items: flex-start;

        flex-direction: column;
    }


    .profile-page-header .btn {

        width: 100%;
    }


    .profile-form-footer {

        align-items: stretch;

        flex-direction: column;
    }


    .save-profile-btn {

        width: 100%;
    }


    .review-item-top {

        flex-direction: column;

        gap: 8px;
    }

}


@media (max-width: 576px) {

    .profile-card {

        padding: 18px;

        border-radius: 16px;
    }


    .profile-verification {

        align-items: flex-start;
    }


    .profile-header-icon {

        display: none;
    }

}

/* Doctor Profile - Improved Text Readability */

/* Page Header */
.profile-page-label {
    font-size: 13px !important;
}

.profile-page-header h1 {
    font-size: 36px !important;
}

.profile-page-header p {
    font-size: 17px !important;
}

/* Verification Banner */
.profile-verification-content strong {
    font-size: 16px !important;
}

.profile-verification-content span {
    font-size: 14px !important;
}

/* Profile Summary */
.profile-summary-card h2 {
    font-size: 22px !important;
}

.profile-specialization {
    font-size: 16px !important;
}

.profile-status {
    font-size: 14px !important;
}

.profile-summary-item span {
    font-size: 14px !important;
}

.profile-summary-item strong {
    font-size: 16px !important;
}

.profile-rating-stars {
    font-size: 17px !important;
}

/* Profile Verification Note */
.profile-info-card strong {
    font-size: 16px !important;
}

.profile-info-card p {
    font-size: 14px !important;
    line-height: 1.6 !important;
}

/* Form Section Headings */
.profile-card-header > div:first-child > span {
    font-size: 13px !important;
}

.profile-card-header h2 {
    font-size: 24px !important;
}

.profile-card-header p {
    font-size: 15px !important;
}

.profile-section-title strong {
    font-size: 17px !important;
}

.profile-section-title span {
    font-size: 14px !important;
}

/* Form Labels and Inputs */
.profile-form label {
    font-size: 15px !important;
}

.profile-form .form-control,
.profile-form .form-select {
    font-size: 16px !important;
}

.profile-form small {
    font-size: 13px !important;
}

.input-with-suffix > span {
    font-size: 14px !important;
}

/* Save Button and Note */
.save-note {
    font-size: 14px !important;
}

.save-profile-btn {
    font-size: 16px !important;
}

/* Reviews */
.review-rating-summary .display-4 {
    font-size: 48px !important;
}

.review-stars {
    font-size: 22px !important;
}

.review-item {
    font-size: 15px !important;
}

.review-patient {
    font-size: 15px !important;
}

.review-text {
    font-size: 15px !important;
    line-height: 1.7 !important;
}

.review-stars-small {
    font-size: 16px !important;
}

</style>


<?php require '../includes/footer.php'; ?>