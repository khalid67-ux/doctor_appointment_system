<?php

$page_title = 'Favorite Doctors';
$asset_prefix = '../';

require '../includes/header.php';
require_role('patient');

$patient_id = get_patient_id((int)$_SESSION['user_id']);


/*
|--------------------------------------------------------------------------
| Favorite Doctors - MongoDB
|--------------------------------------------------------------------------
*/

$doctors = [];

$favoriteDocs = $mongoDb->favorites->find(
    ['patient_id' => (int) $patient_id]
);

foreach ($favoriteDocs as $favorite) {
    $doctorId = (int) ($favorite->doctor_id ?? 0);

    if ($doctorId <= 0) {
        continue;
    }

    // Find approved doctor
    $doctor = $mongoDb->doctors->findOne([
        'doctor_id' => $doctorId,
        'verification_status' => 'Approved'
    ]);

    if (!$doctor) {
        continue;
    }

    // Find doctor user information
    $user = $mongoDb->users->findOne([
        'user_id' => (int) ($doctor->user_id ?? 0),
        'status' => 'Active'
    ]);

    if (!$user) {
        continue;
    }

    // Find specialization
    $specialization = $mongoDb->specializations->findOne([
        'specialization_id' =>
            (int) ($doctor->specialization_id ?? 0)
    ]);

    // Calculate doctor rating
    $reviewCount = 0;
    $ratingTotal = 0;

    $reviews = $mongoDb->reviews->find([
        'doctor_id' => $doctorId
    ]);

    foreach ($reviews as $review) {
        if (isset($review->rating)) {
            $ratingTotal += (float) $review->rating;
            $reviewCount++;
        }
    }

    $averageRating = $reviewCount > 0
        ? $ratingTotal / $reviewCount
        : 0;

    // Find doctor's clinics and branches
    $clinicNames = [];

    $doctorBranches = $mongoDb->doctor_branches->find([
        'doctor_id' => $doctorId
    ]);

    foreach ($doctorBranches as $doctorBranch) {
        $branchId = (int) ($doctorBranch->branch_id ?? 0);

        $branch = $mongoDb->branches->findOne([
            'branch_id' => $branchId
        ]);

        if (!$branch) {
            continue;
        }

        $clinicId = (int) ($branch->clinic_id ?? 0);

        $clinic = $mongoDb->clinics->findOne([
            'clinic_id' => $clinicId
        ]);

        if (!$clinic) {
            continue;
        }

        $clinicName = (string) ($clinic->name ?? '');
        $branchName = (string) ($branch->branch_name ?? '');

        if ($clinicName !== '' || $branchName !== '') {
            $location = $clinicName . ' — ' . $branchName;
            $location = trim($location, " —");

            if (!in_array($location, $clinicNames, true)) {
                $clinicNames[] = $location;
            }
        }
    }

    // Prepare data for the existing doctor cards
    $doctors[] = [
        'doctor_id' => $doctorId,
        'qualification' => $doctor->qualification ?? '',
        'experience' => (int) ($doctor->experience ?? 0),
        'consultation_fee' =>
            (float) ($doctor->consultation_fee ?? 0),
        'verification_status' =>
            (string) ($doctor->verification_status ?? ''),
        'doctor_name' => (string) ($user->name ?? ''),
        'doctor_email' => (string) ($user->email ?? ''),
        'specialization' =>
            $specialization->name ?? 'General',
        'review_count' => $reviewCount,
        'average_rating' => $averageRating,
        'clinic_names' => implode('||', $clinicNames)
    ];
}

// Sort favorite doctors by name
usort($doctors, function ($a, $b) {
    return strcasecmp(
        $a['doctor_name'],
        $b['doctor_name']
    );
});


/*
|--------------------------------------------------------------------------
| Star Helper
|--------------------------------------------------------------------------
*/
function render_favorite_stars(float $rating): string
{
    $html = '';

    for ($i = 1; $i <= 5; $i++) {

        if ($rating >= $i) {
            $html .= '<i class="bi bi-star-fill"></i>';
        } elseif ($rating >= ($i - 0.5)) {
            $html .= '<i class="bi bi-star-half"></i>';
        } else {
            $html .= '<i class="bi bi-star"></i>';
        }
    }

    return $html;
}

?>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">

    <div>

        <span class="eyebrow">
            <i class="bi bi-heart-fill"></i>
            Patient portal
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            Favorite Doctors
        </h1>

        <p class="text-secondary mb-0">
            Quickly access doctors you have saved for future appointments.
        </p>

    </div>

    <a
        href="doctors.php"
        class="btn btn-primary"
    >
        <i class="bi bi-search me-1"></i>
        Find Doctors
    </a>

</div>


<!-- Summary -->
<div class="row g-3 mb-4">

    <div class="col-12 col-md-4">

        <div class="summary-card">

            <div class="summary-icon">
                <i class="bi bi-heart-fill"></i>
            </div>

            <div>
                <div class="summary-label">
                    Favorite Doctors
                </div>

                <div class="summary-value">
                    <?= count($doctors) ?>
                </div>
            </div>

        </div>

    </div>

</div>


<?php if ($doctors): ?>

<div class="row g-4">

    <?php foreach ($doctors as $doctor): ?>

        <?php

        $rating = round((float)$doctor['average_rating'], 1);

        $review_count = (int)$doctor['review_count'];

        $clinics = [];

        if (!empty($doctor['clinic_names'])) {

            $clinics = array_filter(
                explode('||', $doctor['clinic_names'])
            );
        }

        ?>

        <div
            class="col-12 col-md-6 col-xl-4"
            id="favorite-card-<?= (int)$doctor['doctor_id'] ?>"
        >

            <div class="doctor-card h-100">

                <!-- Top -->
                <div class="doctor-card-top">

                    <div class="doctor-avatar">
                        <i class="bi bi-person-fill"></i>
                    </div>

                    <div class="doctor-heading">

                        <div class="d-flex align-items-center gap-2 flex-wrap">

                            <h2 class="doctor-name mb-0">
                                Dr. <?= h($doctor['doctor_name']) ?>
                            </h2>

                            <?php if ($doctor['verification_status'] === 'Approved'): ?>

                                <span
                                    class="verified-badge"
                                    title="Verified Doctor"
                                >
                                    <i class="bi bi-patch-check-fill"></i>
                                </span>

                            <?php endif; ?>

                        </div>

                        <div class="doctor-specialization">

                            <?= h($doctor['specialization'] ?? 'General') ?>

                        </div>

                    </div>

                    <!-- Favorite -->
                    <button
                        type="button"
                        class="favorite-btn active"
                        title="Remove from favorites"
                        data-doctor-id="<?= (int)$doctor['doctor_id'] ?>"
                        aria-label="Remove Dr. <?= h($doctor['doctor_name']) ?> from favorites"
                    >
                        <i class="bi bi-heart-fill"></i>
                    </button>

                </div>


                <!-- Rating -->
                <div class="rating-row">

                    <span class="rating-stars">

                        <?= render_favorite_stars($rating) ?>

                    </span>

                    <strong>
                        <?= number_format($rating, 1) ?>
                    </strong>

                    <span class="text-secondary">
                        (<?= $review_count ?> review<?= $review_count === 1 ? '' : 's' ?>)
                    </span>

                </div>


                <!-- Details -->
                <div class="doctor-details">

                    <div class="detail-item">

                        <i class="bi bi-mortarboard"></i>

                        <div>

                            <span class="detail-label">
                                Qualification
                            </span>

                            <span class="detail-value">
                                <?= h($doctor['qualification'] ?? 'Not specified') ?>
                            </span>

                        </div>

                    </div>


                    <div class="detail-item">

                        <i class="bi bi-briefcase"></i>

                        <div>

                            <span class="detail-label">
                                Experience
                            </span>

                            <span class="detail-value">

                                <?php if ((int)$doctor['experience'] > 0): ?>

                                    <?= (int)$doctor['experience'] ?> year<?= (int)$doctor['experience'] === 1 ? '' : 's' ?>

                                <?php else: ?>

                                    Not specified

                                <?php endif; ?>

                            </span>

                        </div>

                    </div>


                    <div class="detail-item">

                        <i class="bi bi-cash-stack"></i>

                        <div>

                            <span class="detail-label">
                                Consultation Fee
                            </span>

                            <span class="detail-value">

                                <?= number_format((float)$doctor['consultation_fee'], 2) ?>

                            </span>

                        </div>

                    </div>

                </div>


                <!-- Clinics -->
                <?php if ($clinics): ?>

                    <div class="clinic-section">

                        <div class="clinic-title">

                            <i class="bi bi-geo-alt-fill"></i>

                            Available Locations

                        </div>

                        <div class="clinic-list">

                            <?php foreach (array_slice($clinics, 0, 3) as $clinic): ?>

                                <div class="clinic-item">

                                    <i class="bi bi-building"></i>

                                    <span>
                                        <?= h($clinic) ?>
                                    </span>

                                </div>

                            <?php endforeach; ?>

                        </div>

                        <?php if (count($clinics) > 3): ?>

                            <small class="text-secondary">
                                +<?= count($clinics) - 3 ?> more location<?= count($clinics) - 3 === 1 ? '' : 's' ?>
                            </small>

                        <?php endif; ?>

                    </div>

                <?php endif; ?>


                <!-- Actions -->
                <div class="doctor-actions">

                    <a
                        href="doctor-details.php?id=<?= (int)$doctor['doctor_id'] ?>"
                        class="btn btn-outline-secondary"
                    >
                        <i class="bi bi-person-lines-fill me-1"></i>
                        View Profile
                    </a>

                    <a
                        href="book.php?doctor_id=<?= (int)$doctor['doctor_id'] ?>"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-calendar-plus me-1"></i>
                        Quick Book
                    </a>

                </div>

            </div>

        </div>

    <?php endforeach; ?>

</div>


<?php else: ?>

<!-- Empty State -->
<div class="empty-favorites">

    <div class="empty-icon">

        <i class="bi bi-heart"></i>

    </div>

    <h3>
        No Favorite Doctors Yet
    </h3>

    <p>
        Save doctors you frequently visit so you can quickly access
        their profiles and book appointments later.
    </p>

    <a
        href="doctors.php"
        class="btn btn-primary"
    >
        <i class="bi bi-search me-1"></i>
        Explore Doctors
    </a>

</div>

<?php endif; ?>


<style>

/*
|--------------------------------------------------------------------------
| Summary
|--------------------------------------------------------------------------
*/

.summary-card {

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 16px;

    padding: 1rem 1.15rem;

    display: flex;

    align-items: center;

    gap: .9rem;

    height: 100%;

}

.summary-icon {

    width: 46px;

    height: 46px;

    border-radius: 12px;

    display: flex;

    align-items: center;

    justify-content: center;

    background: #fff1f2;

    color: #e11d48;

    font-size: 1.2rem;

}

.summary-label {

    font-size: .78rem;

    color: #64748b;

}

.summary-value {

    font-size: 1.35rem;

    font-weight: 700;

    color: #0f172a;

}


/*
|--------------------------------------------------------------------------
| Doctor Card
|--------------------------------------------------------------------------
*/

.doctor-card {

    background: #ffffff;

    border: 1px solid #e5e7eb;

    border-radius: 18px;

    padding: 1.25rem;

    box-shadow: 0 6px 20px rgba(15, 23, 42, .04);

    transition:
        transform .2s ease,
        box-shadow .2s ease,
        border-color .2s ease;

    display: flex;

    flex-direction: column;

}

.doctor-card:hover {

    transform: translateY(-3px);

    box-shadow: 0 14px 30px rgba(15, 23, 42, .08);

    border-color: #dbe3ef;

}


/*
|--------------------------------------------------------------------------
| Doctor Header
|--------------------------------------------------------------------------
*/

.doctor-card-top {

    display: flex;

    align-items: flex-start;

    gap: .85rem;

}

.doctor-avatar {

    width: 52px;

    height: 52px;

    min-width: 52px;

    border-radius: 14px;

    background: #eff6ff;

    color: #2563eb;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 1.35rem;

}

.doctor-heading {

    min-width: 0;

    flex: 1;

}

.doctor-name {

    font-size: 1rem;

    font-weight: 700;

    color: #0f172a;

    word-break: break-word;

}

.doctor-specialization {

    margin-top: .2rem;

    font-size: .82rem;

    color: #64748b;

}

.verified-badge {

    color: #2563eb;

    font-size: .95rem;

}


/*
|--------------------------------------------------------------------------
| Favorite Button
|--------------------------------------------------------------------------
*/

.favorite-btn {

    width: 38px;

    height: 38px;

    min-width: 38px;

    border-radius: 50%;

    border: 1px solid #fecdd3;

    background: #fff1f2;

    color: #e11d48;

    display: flex;

    align-items: center;

    justify-content: center;

    cursor: pointer;

    transition:
        background .2s ease,
        color .2s ease,
        transform .2s ease;

}

.favorite-btn:hover {

    background: #ffe4e6;

    transform: scale(1.05);

}


/*
|--------------------------------------------------------------------------
| Rating
|--------------------------------------------------------------------------
*/

.rating-row {

    display: flex;

    align-items: center;

    flex-wrap: wrap;

    gap: .35rem;

    margin-top: 1rem;

    padding-bottom: 1rem;

    border-bottom: 1px solid #f1f5f9;

    font-size: .82rem;

}

.rating-stars {

    color: #f59e0b;

    letter-spacing: 1px;

}


/*
|--------------------------------------------------------------------------
| Doctor Details
|--------------------------------------------------------------------------
*/

.doctor-details {

    display: grid;

    gap: .85rem;

    margin-top: 1rem;

}

.detail-item {

    display: flex;

    align-items: flex-start;

    gap: .75rem;

}

.detail-item > i {

    width: 32px;

    height: 32px;

    min-width: 32px;

    border-radius: 9px;

    background: #f8fafc;

    color: #475569;

    display: flex;

    align-items: center;

    justify-content: center;

}

.detail-item > div {

    min-width: 0;

}

.detail-label {

    display: block;

    font-size: .72rem;

    color: #94a3b8;

    margin-bottom: .1rem;

}

.detail-value {

    display: block;

    font-size: .84rem;

    font-weight: 600;

    color: #334155;

    word-break: break-word;

}


/*
|--------------------------------------------------------------------------
| Clinics
|--------------------------------------------------------------------------
*/

.clinic-section {

    margin-top: 1rem;

    padding-top: 1rem;

    border-top: 1px solid #f1f5f9;

}

.clinic-title {

    font-size: .8rem;

    font-weight: 700;

    color: #334155;

    margin-bottom: .55rem;

}

.clinic-title i {

    color: #2563eb;

    margin-right: .25rem;

}

.clinic-list {

    display: grid;

    gap: .4rem;

}

.clinic-item {

    display: flex;

    align-items: flex-start;

    gap: .45rem;

    font-size: .78rem;

    color: #64748b;

}

.clinic-item i {

    color: #94a3b8;

    margin-top: .1rem;

}


/*
|--------------------------------------------------------------------------
| Actions
|--------------------------------------------------------------------------
*/

.doctor-actions {

    display: grid;

    grid-template-columns: 1fr 1fr;

    gap: .65rem;

    margin-top: auto;

    padding-top: 1.25rem;

}

.doctor-actions .btn {

    font-size: .8rem;

    white-space: nowrap;

}


/*
|--------------------------------------------------------------------------
| Empty State
|--------------------------------------------------------------------------
*/

.empty-favorites {

    background: #ffffff;

    border: 1px dashed #cbd5e1;

    border-radius: 20px;

    padding: 4rem 1.5rem;

    text-align: center;

}

.empty-icon {

    width: 72px;

    height: 72px;

    margin: 0 auto 1rem;

    border-radius: 50%;

    background: #fff1f2;

    color: #e11d48;

    display: flex;

    align-items: center;

    justify-content: center;

    font-size: 1.8rem;

}

.empty-favorites h3 {

    font-size: 1.15rem;

    font-weight: 700;

    color: #0f172a;

}

.empty-favorites p {

    max-width: 520px;

    margin: .5rem auto 1.25rem;

    color: #64748b;

    font-size: .9rem;

}


/*
|--------------------------------------------------------------------------
| Responsive
|--------------------------------------------------------------------------
*/

@media (max-width: 575.98px) {

    .doctor-card {

        padding: 1rem;

    }

    .doctor-actions {

        grid-template-columns: 1fr;

    }

}

/* Increase text size on Favorite Doctors page */

.summary-label {
    font-size: 0.95rem;
}

.summary-value {
    font-size: 1.5rem;
}

.doctor-name {
    font-size: 1.15rem;
}

.doctor-specialization {
    font-size: 0.95rem;
}

.rating-row {
    font-size: 0.95rem;
}

.detail-label {
    font-size: 0.9rem;
}

.detail-value {
    font-size: 1rem;
}

.clinic-title {
    font-size: 0.95rem;
}

.clinic-item {
    font-size: 0.95rem;
}

.doctor-actions .btn {
    font-size: 0.95rem;
}

.empty-favorites h3 {
    font-size: 1.3rem;
}

.empty-favorites p {
    font-size: 1rem;
}

</style>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const favoriteButtons =
        document.querySelectorAll('.favorite-btn');

    favoriteButtons.forEach(function (button) {

        button.addEventListener('click', async function () {

            const doctorId =
                this.dataset.doctorId;

            if (!doctorId) {
                return;
            }

            const card =
                document.getElementById(
                    'favorite-card-' + doctorId
                );

            const originalHTML =
                this.innerHTML;

            this.disabled = true;

            this.innerHTML =
                '<span class="spinner-border spinner-border-sm"></span>';

            try {

                const response = await fetch(
                    '../api/favorite.php',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded',
                            'Accept':
                                'application/json'
                        },

                        body:
                            'doctor_id=' +
                            encodeURIComponent(doctorId) +
                            '&csrf_token=' +
                            encodeURIComponent(
                                '<?= h($_SESSION['csrf_token'] ?? '') ?>'
                            )
                    }
                );

                const data =
                    await response.json();

                if (!response.ok || !data.success) {

                    throw new Error(
                        data.message ||
                        'Unable to update favorite.'
                    );
                }


                /*
                 * On this page, successful toggle from
                 * favorite -> unfavorite means removing
                 * the doctor card.
                 */
                if (
                    data.action === 'removed' ||
                    data.favorite === false
                ) {

                    if (card) {

                        card.style.transition =
                            'opacity .25s ease, transform .25s ease';

                        card.style.opacity = '0';

                        card.style.transform =
                            'scale(.97)';

                        setTimeout(function () {

                            card.remove();

                            updateFavoriteCount();

                            /*
                             * If no cards remain,
                             * reload to show empty state.
                             */
                            const remaining =
                                document.querySelectorAll(
                                    '[id^="favorite-card-"]'
                                ).length;

                            if (remaining === 0) {
                                window.location.reload();
                            }

                        }, 250);

                    }

                    return;
                }


                this.innerHTML =
                    originalHTML;

            } catch (error) {

                console.error(error);

                alert(
                    error.message ||
                    'Something went wrong. Please try again.'
                );

                this.innerHTML =
                    originalHTML;

            } finally {

                this.disabled = false;

            }

        });

    });


    function updateFavoriteCount() {

        const cards =
            document.querySelectorAll(
                '[id^="favorite-card-"]'
            );

        const summaryValue =
            document.querySelector('.summary-value');

        if (summaryValue) {

            summaryValue.textContent =
                cards.length;

        }

    }

});

</script>


<?php require '../includes/footer.php'; ?>