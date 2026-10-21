<?php
$page_title = 'Doctor Details';
$asset_prefix = '../';
$navbar_type = 'doctors';

require '../includes/header.php';

$did = (int)($_GET['id'] ?? 0);

/* =========================
   Doctor Information
========================= */

$doctorDoc = $mongoDb->selectCollection('doctors')->findOne([
    'doctor_id' => $did,
    'verification_status' => 'Approved'
]);

$d = null;

if ($doctorDoc) {
    $doctorData = $doctorDoc->getArrayCopy();

    $userDoc = $mongoDb->selectCollection('users')->findOne([
        'user_id' => (int) ($doctorData['user_id'] ?? 0)
    ]);

    $userData = $userDoc ? $userDoc->getArrayCopy() : [];

    $specialization = 'General';

    if (!empty($doctorData['specialization_id'])) {
        $specializationDoc = $mongoDb
            ->selectCollection('specializations')
            ->findOne([
                'specialization_id' =>
                    (int) $doctorData['specialization_id']
            ]);

        if ($specializationDoc) {
            $specializationData = $specializationDoc->getArrayCopy();
            $specialization = $specializationData['name'] ?? 'General';
        }
    }

    $reviewDocs = $mongoDb->selectCollection('reviews')->find([
        'doctor_id' => $did
    ]);

    $ratings = [];
    $reviewCount = 0;

    foreach ($reviewDocs as $reviewDoc) {
        $reviewData = $reviewDoc->getArrayCopy();
        $ratings[] = (float) ($reviewData['rating'] ?? 0);
        $reviewCount++;
    }

    $averageRating = $reviewCount > 0
        ? array_sum($ratings) / $reviewCount
        : 0;

    $d = array_merge($doctorData, [
        'name' => $userData['name'] ?? 'N/A',
        'email' => $userData['email'] ?? '',
        'specialization' => $specialization,
        'avg_rating' => $averageRating,
        'review_count' => $reviewCount
    ]);
}

if (!$d) {
    echo '<div class="alert alert-danger">Doctor not found.</div>';
    require '../includes/footer.php';
    exit;
}

$average_rating = round((float) ($d['avg_rating'] ?? 0), 1);
$review_count = (int) ($d['review_count'] ?? 0);


/* =========================
   Doctor Branches
========================= */

$branches = [];

$doctorBranchDocs = $mongoDb->selectCollection('doctor_branches')->find([
    'doctor_id' => $did
]);

foreach ($doctorBranchDocs as $doctorBranchDoc) {
    $doctorBranch = $doctorBranchDoc->getArrayCopy();

    $branchDoc = $mongoDb->selectCollection('branches')->findOne([
        'branch_id' => (int) ($doctorBranch['branch_id'] ?? 0)
    ]);

    if (!$branchDoc) {
        continue;
    }

    $branchData = $branchDoc->getArrayCopy();

    $clinicDoc = $mongoDb->selectCollection('clinics')->findOne([
        'clinic_id' => (int) ($branchData['clinic_id'] ?? 0)
    ]);

    if (!$clinicDoc) {
        continue;
    }

    $clinicData = $clinicDoc->getArrayCopy();

    $branches[] = array_merge($branchData, [
        'clinic_name' => $clinicData['name'] ?? 'N/A'
    ]);
}


/* =========================
   Doctor Reviews
========================= */

$reviews = [];

$reviewDocs = $mongoDb->selectCollection('reviews')->find(
    ['doctor_id' => $did],
    [
        'sort' => ['created_at' => -1],
        'limit' => 10
    ]
);

foreach ($reviewDocs as $reviewDoc) {
    $reviewData = $reviewDoc->getArrayCopy();

    $patientDoc = $mongoDb->selectCollection('patients')->findOne([
        'patient_id' => (int) ($reviewData['patient_id'] ?? 0)
    ]);

    $patientData = $patientDoc ? $patientDoc->getArrayCopy() : [];

    $patientUserDoc = null;

    if (!empty($patientData['user_id'])) {
        $patientUserDoc = $mongoDb->selectCollection('users')->findOne([
            'user_id' => (int) $patientData['user_id']
        ]);
    }

    $patientUser = $patientUserDoc
        ? $patientUserDoc->getArrayCopy()
        : [];

    $reviews[] = array_merge($reviewData, [
        'patient_name' => $patientUser['name'] ?? 'Anonymous'
    ]);
}


/* =========================
   Rating Stars
========================= */
function doctorRatingStars(float $rating): string
{
    $fullStars = (int)floor($rating);
    $halfStar = ($rating - $fullStars) >= 0.5 ? 1 : 0;

    $html = '';

    for ($i = 1; $i <= $fullStars; $i++) {
        $html .= '<span class="rating-star">★</span>';
    }

    if ($halfStar) {
        $html .= '<span class="rating-star">★</span>';
    }

    $emptyStars = 5 - $fullStars - $halfStar;

    for ($i = 1; $i <= $emptyStars; $i++) {
        $html .= '<span class="rating-star empty">★</span>';
    }

    return $html;
}
?>

<!-- =========================
     Doctor Header
========================= -->
<div class="doctor-profile-card card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <div class="row align-items-center g-4">

            <div class="col-lg-8">

                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <h2 class="doctor-name mb-0">
                        Dr. <?= h($d['name']) ?>
                    </h2>

                    <span class="badge rounded-pill text-bg-success">
                        ✓ Verified
                    </span>
                </div>

                <div class="doctor-specialization mb-3">
                    <?= h($d['specialization']) ?>
                </div>

                <p class="text-secondary mb-3">
                    <?= h($d['qualification']) ?>
                </p>

                <div class="doctor-meta">

                    <div class="meta-item">
                        <span class="meta-label">Experience</span>
                        <strong>
                            <?= h($d['experience']) ?> years
                        </strong>
                    </div>

                    <div class="meta-item">
                        <span class="meta-label">Consultation Fee</span>
                        <strong>
                            <?php
                            $feeValue = $d['consultation_fee'] ?? 0;

                            $consultationFee = $feeValue instanceof MongoDB\BSON\Decimal128
                                ? (float) (string) $feeValue
                                : (float) $feeValue;
                            ?>

                            ৳<?= number_format($consultationFee, 2) ?>
                        </strong>
                    </div>

                    <div class="meta-item">
                        <span class="meta-label">Patient Rating</span>

                        <div class="rating-inline">
                            <?= doctorRatingStars($average_rating) ?>

                            <strong>
                                <?= number_format($average_rating, 1) ?>
                            </strong>

                            <span class="text-secondary">
                                (<?= $review_count ?> reviews)
                            </span>
                        </div>
                    </div>

                </div>

            </div>


            <!-- Booking -->
            <div class="col-lg-4">

                <?php if (
                    !empty($_SESSION['role']) &&
                    $_SESSION['role'] === 'patient'
                ): ?>

                    <a
                        class="btn btn-primary btn-lg w-100"
                        href="book.php?doctor_id=<?= $did ?>"
                    >
                        <i class="bi bi-calendar-check me-2"></i>
                        Book Appointment
                    </a>

                <?php elseif (empty($_SESSION['user_id'])): ?>

                    <a
                        class="btn btn-primary btn-lg w-100"
                        href="../login.php"
                    >
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Login to Book
                    </a>

                <?php endif; ?>

            </div>

        </div>
    </div>
</div>


<!-- =========================
     Availability + Branch
========================= -->
<div class="row g-4">

    <!-- Live Availability -->
    <div class="col-lg-7">

        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4">

                <div class="section-heading">
                    <div>
                        <h4 class="mb-1">
                            <i class="bi bi-clock-history me-2"></i>
                            Live Availability
                        </h4>

                        <p class="text-secondary mb-0">
                            Select a date to view available appointment slots.
                        </p>
                    </div>
                </div>

                <label
                    for="slotDate"
                    class="form-label fw-semibold mt-3"
                >
                    Appointment Date
                </label>

                <input
                    id="slotDate"
                    type="date"
                    class="form-control"
                    min="<?= date('Y-m-d') ?>"
                    value="<?= date('Y-m-d') ?>"
                >

                <div
                    id="slotBox"
                    class="slot-container mt-4"
                >
                    <span class="text-secondary">
                        Loading slots...
                    </span>
                </div>

            </div>
        </div>

    </div>


    <!-- Clinic / Branch -->
    <div class="col-lg-5">

        <div class="card border-0 shadow-sm h-100">
            <div class="card-body p-4">

                <div class="section-heading">
                    <div>
                        <h4 class="mb-1">
                            <i class="bi bi-hospital me-2"></i>
                            Clinic / Branch
                        </h4>

                        <p class="text-secondary mb-0">
                            Available consultation locations.
                        </p>
                    </div>
                </div>

                <div class="branch-list mt-3">

                    <?php if ($branches): ?>

                        <?php foreach ($branches as $b): ?>

                            <div class="branch-item">

                                <div class="branch-icon">
                                    <i class="bi bi-building"></i>
                                </div>

                                <div class="branch-info">

                                    <h6 class="mb-1">
                                        <?= h($b['clinic_name']) ?>
                                    </h6>

                                    <div class="fw-semibold">
                                        <?= h($b['branch_name']) ?>
                                    </div>

                                    <small class="text-secondary">
                                        <i class="bi bi-geo-alt me-1"></i>
                                        <?= h($b['address']) ?>
                                    </small>

                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <div class="empty-state">
                            <i class="bi bi-hospital"></i>
                            <p class="mb-0">
                                No clinic branch information available.
                            </p>
                        </div>

                    <?php endif; ?>

                </div>

            </div>
        </div>

    </div>

</div>


<!-- =========================
     Ratings & Reviews
========================= -->
<div class="card border-0 shadow-sm mt-4">

    <div class="card-body p-4">

        <div class="section-heading mb-4">

            <div>
                <h4 class="mb-1">
                    <i class="bi bi-star me-2"></i>
                    Ratings & Reviews
                </h4>

                <p class="text-secondary mb-0">
                    Feedback from patients who completed appointments.
                </p>
            </div>

        </div>


        <!-- Rating Summary -->
        <div class="rating-summary">

            <div class="rating-score">

                <div class="rating-number">
                    <?= number_format($average_rating, 1) ?>
                </div>

                <div class="rating-stars">
                    <?= doctorRatingStars($average_rating) ?>
                </div>

                <div class="rating-count">
                    <?= $review_count ?>
                    <?= $review_count === 1 ? 'review' : 'reviews' ?>
                </div>

            </div>


            <div class="rating-description">

                <?php if ($review_count > 0): ?>

                    <h5 class="mb-2">
                        Patient Feedback
                    </h5>

                    <p class="text-secondary mb-0">
                        This rating is based on
                        <?= $review_count ?>
                        patient
                        <?= $review_count === 1 ? 'review' : 'reviews' ?>.
                    </p>

                <?php else: ?>

                    <h5 class="mb-2">
                        No reviews yet
                    </h5>

                    <p class="text-secondary mb-0">
                        Patient reviews will appear here after completed
                        appointments.
                    </p>

                <?php endif; ?>

            </div>

        </div>


        <!-- Review List -->
        <div class="reviews-list mt-4">

            <?php if ($reviews): ?>

                <?php foreach ($reviews as $r): ?>

                    <div class="review-card">

                        <div class="review-header">

                            <div class="reviewer">

                                <div class="reviewer-avatar">
                                    <?= strtoupper(
                                        mb_substr(
                                            $r['patient_name'],
                                            0,
                                            1
                                        )
                                    ) ?>
                                </div>

                                <div>

                                    <div class="reviewer-name">
                                        <?= h($r['patient_name']) ?>
                                    </div>

                                    <div class="review-date">
                                        <?= date(
                                            'd M Y',
                                            strtotime($r['created_at'])
                                        ) ?>
                                    </div>

                                </div>

                            </div>


                            <div class="review-rating">

                                <?= str_repeat(
                                    '<span class="rating-star">★</span>',
                                    (int)$r['rating']
                                ) ?>

                                <?= str_repeat(
                                    '<span class="rating-star empty">★</span>',
                                    5 - (int)$r['rating']
                                ) ?>

                            </div>

                        </div>


                        <div class="review-text">

                            <?= nl2br(h($r['review_text'])) ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-review-state">

                    <div class="empty-review-icon">
                        <i class="bi bi-chat-square-text"></i>
                    </div>

                    <h5>No patient reviews yet</h5>

                    <p class="text-secondary mb-0">
                        Reviews will appear here when patients submit
                        feedback after completing their appointments.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<!-- =========================
     Page CSS
========================= -->
<style>

.doctor-profile-card {
    border-radius: 18px;
}

.doctor-name {
    font-weight: 700;
    letter-spacing: -0.4px;
}

.doctor-specialization {
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--bs-primary);
}

.doctor-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 28px;
}

.meta-item {
    display: flex;
    flex-direction: column;
    gap: 3px;
}

.meta-label {
    font-size: .78rem;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: .4px;
}

.rating-inline {
    display: flex;
    align-items: center;
    gap: 4px;
    flex-wrap: wrap;
}

.rating-star {
    color: #f5b301;
    font-size: 1rem;
}

.rating-star.empty {
    color: #d9dee3;
}

.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.slot-container {
    min-height: 70px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: flex-start;
}

.slot {
    min-width: 100px;
    border-radius: 10px;
    font-weight: 600;
}

.branch-list {
    display: flex;
    flex-direction: column;
}

.branch-item {
    display: flex;
    gap: 14px;
    padding: 16px 0;
    border-bottom: 1px solid #edf0f2;
}

.branch-item:last-child {
    border-bottom: 0;
}

.branch-icon {
    width: 42px;
    height: 42px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(13, 110, 253, .08);
    color: var(--bs-primary);
    flex-shrink: 0;
}

.branch-info {
    min-width: 0;
}

.branch-info h6 {
    font-weight: 700;
}

.rating-summary {
    display: flex;
    align-items: center;
    gap: 35px;
    padding: 22px;
    border-radius: 16px;
    background: #f8f9fa;
}

.rating-score {
    min-width: 180px;
    text-align: center;
}

.rating-number {
    font-size: 3rem;
    line-height: 1;
    font-weight: 700;
}

.rating-stars {
    margin: 8px 0;
}

.rating-stars .rating-star {
    font-size: 1.25rem;
}

.rating-count {
    color: #6c757d;
    font-size: .9rem;
}

.rating-description {
    padding-left: 25px;
    border-left: 1px solid #dee2e6;
}

.reviews-list {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.review-card {
    padding: 20px;
    border: 1px solid #edf0f2;
    border-radius: 14px;
    background: #fff;
    transition: .2s ease;
}

.review-card:hover {
    box-shadow: 0 5px 18px rgba(0,0,0,.05);
}

.review-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
}

.reviewer {
    display: flex;
    align-items: center;
    gap: 12px;
}

.reviewer-avatar {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #e9f2ff;
    color: var(--bs-primary);
    font-weight: 700;
}

.reviewer-name {
    font-weight: 700;
}

.review-date {
    font-size: .8rem;
    color: #6c757d;
    margin-top: 2px;
}

.review-rating {
    white-space: nowrap;
}

.review-rating .rating-star {
    font-size: .95rem;
}

.review-text {
    margin-top: 15px;
    color: #495057;
    line-height: 1.7;
}

.empty-state {
    text-align: center;
    padding: 30px 10px;
    color: #6c757d;
}

.empty-state i {
    font-size: 2rem;
    display: block;
    margin-bottom: 10px;
}

.empty-review-state {
    text-align: center;
    padding: 45px 20px;
    border: 1px dashed #dce1e5;
    border-radius: 14px;
}

.empty-review-icon {
    width: 55px;
    height: 55px;
    margin: 0 auto 15px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f1f3f5;
    color: #6c757d;
    font-size: 1.4rem;
}

.empty-review-state h5 {
    font-weight: 700;
}


/* =========================
   Responsive
========================= */

@media (max-width: 767px) {

    .doctor-meta {
        flex-direction: column;
        gap: 15px;
    }

    .rating-summary {
        flex-direction: column;
        text-align: center;
        gap: 20px;
    }

    .rating-description {
        padding-left: 0;
        padding-top: 20px;
        border-left: 0;
        border-top: 1px solid #dee2e6;
    }

    .review-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .review-rating {
        margin-left: 54px;
    }

}

</style>


<script>
const did = <?= json_encode($did) ?>;

let slotRefreshTimer = null;
let activeSlotRequest = 0;

async function loadSlots() {
    const dateInput = document.getElementById('slotDate');
    const box = document.getElementById('slotBox');
    const date = dateInput.value;
    const requestId = ++activeSlotRequest;

    if (!date) {
        box.innerHTML =
            '<span class="text-secondary">Please select a date.</span>';
        return;
    }

    box.innerHTML =
        '<span class="text-secondary">Loading available slots...</span>';

    try {
        const response = await fetch(
            '../api/slots.php?doctor_id=' +
            encodeURIComponent(did) +
            '&date=' +
            encodeURIComponent(date) +
            '&_=' + Date.now(),
            { cache: 'no-store' }
        );

        if (!response.ok) {
            throw new Error('Failed to load slots.');
        }

        const data = await response.json();

        // Ignore an older request if a newer one has started.
        if (requestId !== activeSlotRequest) {
            return;
        }

        box.innerHTML = '';

        if (!Array.isArray(data.slots) || data.slots.length === 0) {
            box.innerHTML =
                '<span class="text-secondary">' +
                'No slots available for this date.' +
                '</span>';
            return;
        }

        const now = new Date();

        const today =
            now.getFullYear() + '-' +
            String(now.getMonth() + 1).padStart(2, '0') + '-' +
            String(now.getDate()).padStart(2, '0');

        const currentTime =
            String(now.getHours()).padStart(2, '0') + ':' +
            String(now.getMinutes()).padStart(2, '0');

        data.slots.forEach(slot => {
            const time = String(slot.time || '').substring(0, 5);

            if (!time) {
                return;
            }

            // A slot is locked if already booked or its time has passed.
            const isPastSlot =
                date === today && time <= currentTime;

            const available =
                slot.available === true && !isPastSlot;

            const a = document.createElement('a');

            a.className =
                'btn slot ' +
                (available
                    ? 'btn-outline-success'
                    : 'btn-secondary disabled');

            a.textContent =
                time + (available
                    ? ''
                    : (isPastSlot ? ' Passed' : ' Booked'));

            if (available) {
                a.href =
                    'book.php?doctor_id=' +
                    encodeURIComponent(did) +
                    '&date=' +
                    encodeURIComponent(date) +
                    '&time=' +
                    encodeURIComponent(time);
            } else {
                a.setAttribute('aria-disabled', 'true');
                a.tabIndex = -1;
            }

            box.appendChild(a);
        });

    } catch (error) {
        if (requestId !== activeSlotRequest) {
            return;
        }

        console.error(error);

        box.innerHTML =
            '<span class="text-danger">' +
            'Unable to load availability. Please try again.' +
            '</span>';
    }
}

document
    .getElementById('slotDate')
    .addEventListener('change', loadSlots);

// Refresh availability every 30 seconds.
function startSlotAutoRefresh() {
    if (slotRefreshTimer !== null) {
        clearInterval(slotRefreshTimer);
    }

    slotRefreshTimer = setInterval(() => {
        if (document.visibilityState === 'visible') {
            loadSlots();
        }
    }, 30000);
}

document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
        loadSlots();
    }
});

startSlotAutoRefresh();
loadSlots();
</script>


<?php require '../includes/footer.php'; ?>