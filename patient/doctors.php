<?php

$page_title = 'Find Doctors';
$navbar_type = 'doctors';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

// Doctor directory is public.
// Only patient-specific features require a patient account.
$patient_id = null;

if (is_logged_in() && has_role('patient')) {
    $patient_id = get_patient_id((int) $_SESSION['user_id']);

    if (!$patient_id) {
        http_response_code(403);
        exit('Patient profile not found. Please contact support.');
    }
}

require_once __DIR__ . '/../includes/header.php';


/*
|--------------------------------------------------------------------------
| Filter Data
|--------------------------------------------------------------------------
*/

// Fetch filter data from MongoDB
$specializations = [];

$specializationDocs = $mongoDb
    ->selectCollection('specializations')
    ->find([], ['sort' => ['name' => 1]]);

foreach ($specializationDocs as $doc) {
    $specializations[] = $doc->getArrayCopy();
}

$clinics = [];

$clinicDocs = $mongoDb
    ->selectCollection('clinics')
    ->find([], ['sort' => ['name' => 1]]);

foreach ($clinicDocs as $doc) {
    $clinics[] = $doc->getArrayCopy();
}


/*
|--------------------------------------------------------------------------
| Search / Filters
|--------------------------------------------------------------------------
*/

$q = trim($_GET['q'] ?? '');

$specialization_id = (int)(
    $_GET['specialization_id'] ?? 0
);

$clinic_id = (int)(
    $_GET['clinic_id'] ?? 0
);

$min_fee = (float)(
    $_GET['min_fee'] ?? 0
);

$max_fee = (float)(
    $_GET['max_fee'] ?? 0
);

$min_experience = (int)(
    $_GET['min_experience'] ?? 0
);

$rating = (float)(
    $_GET['rating'] ?? 0
);

$department = strtolower(
    trim($_GET['department'] ?? '')
);

$allowedDepartments = [
    'medicine',
    'cardiology',
    'gynecology',
    'other'
];

if (!in_array($department, $allowedDepartments, true)) {
    $department = '';
}


/*
|--------------------------------------------------------------------------
| Doctor Query - MongoDB
|--------------------------------------------------------------------------
*/

$doctors = [];

$doctorDocs = $mongoDb->selectCollection('doctors')->find([
    'verification_status' => 'Approved'
]);

$usersCollection = $mongoDb->selectCollection('users');
$specializationsCollection = $mongoDb->selectCollection('specializations');
$reviewsCollection = $mongoDb->selectCollection('reviews');
$doctorBranchesCollection = $mongoDb->selectCollection('doctor_branches');
$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');
$favoritesCollection = $mongoDb->selectCollection('favorites');

foreach ($doctorDocs as $doctorDoc) {
    $doctor = $doctorDoc->getArrayCopy();

    $doctorId = (int) ($doctor['doctor_id'] ?? 0);

    // Get active doctor user
    $userDoc = $usersCollection->findOne([
        'user_id' => (int) ($doctor['user_id'] ?? 0),
        'status' => 'active'
    ]);

    if (!$userDoc) {
        continue;
    }

    $user = $userDoc->getArrayCopy();
    $doctorName = $user['name'] ?? '';

    // Get specialization
    $specialization = 'General Physician';

    if (!empty($doctor['specialization_id'])) {
        $specializationDoc = $specializationsCollection->findOne([
            'specialization_id' => (int) $doctor['specialization_id']
        ]);

        if ($specializationDoc) {
            $specializationData = $specializationDoc->getArrayCopy();
            $specialization = $specializationData['name'] ?? $specialization;
        }
    }

    // Department filter
    $specializationLower = strtolower(trim($specialization));

    $isMedicine = (
        str_contains($specializationLower, 'medicine') ||
        str_contains($specializationLower, 'general physician') ||
        str_contains($specializationLower, 'general care')
    );

    $isCardiology = str_contains(
        $specializationLower,
        'cardio'
    );

    $isGynecology = (
        str_contains($specializationLower, 'gynecology') ||
        str_contains($specializationLower, 'gynaecology') ||
        str_contains($specializationLower, 'obstetric')
    );

    if ($department === 'medicine' && !$isMedicine) {
        continue;
    }

    if ($department === 'cardiology' && !$isCardiology) {
        continue;
    }

    if ($department === 'gynecology' && !$isGynecology) {
        continue;
    }

    if (
        $department === 'other' &&
        ($isMedicine || $isCardiology || $isGynecology)
    ) {
        continue;
    }

    // Search by name, specialization, or qualification
    if ($q !== '') {
        $searchText = strtolower($q);

        if (
            strpos(strtolower($doctorName), $searchText) === false &&
            strpos(strtolower($specialization), $searchText) === false &&
            strpos(
                strtolower($doctor['qualification'] ?? ''),
                $searchText
            ) === false
        ) {
            continue;
        }
    }

    // Specialization filter
    if (
        $specialization_id > 0 &&
        (int) ($doctor['specialization_id'] ?? 0) !== $specialization_id
    ) {
        continue;
    }

    /* Fee filters */
    $feeValue = $doctor['consultation_fee'] ?? 0;

    if ($feeValue instanceof MongoDB\BSON\Decimal128) {
        $fee = (float) (string) $feeValue;
    } else {
        $fee = (float) $feeValue;
    }

    if ($min_fee > 0 && $fee < $min_fee) {
        continue;
    }

    if ($max_fee > 0 && $fee > $max_fee) {
        continue;
    }

    // Experience filter
    $experience = (int) ($doctor['experience'] ?? 0);

    if ($min_experience > 0 && $experience < $min_experience) {
        continue;
    }

    // Get reviews and calculate average rating
    $reviewDocs = $reviewsCollection->find([
        'doctor_id' => $doctorId
    ]);

    $ratingTotal = 0;
    $reviewCount = 0;

    foreach ($reviewDocs as $reviewDoc) {
        $reviewData = $reviewDoc->getArrayCopy();
        $ratingTotal += (float) ($reviewData['rating'] ?? 0);
        $reviewCount++;
    }

    $averageRating = $reviewCount > 0
        ? $ratingTotal / $reviewCount
        : 0;

    if ($rating > 0 && $averageRating < $rating) {
        continue;
    }

    // Get clinic names and check clinic filter
    $clinicNames = [];
    $matchesClinic = $clinic_id <= 0;

    $doctorBranchDocs = $doctorBranchesCollection->find([
        'doctor_id' => $doctorId
    ]);

    foreach ($doctorBranchDocs as $doctorBranchDoc) {
        $doctorBranch = $doctorBranchDoc->getArrayCopy();

        $branchDoc = $branchesCollection->findOne([
            'branch_id' => (int) ($doctorBranch['branch_id'] ?? 0)
        ]);

        if (!$branchDoc) {
            continue;
        }

        $branch = $branchDoc->getArrayCopy();

        $clinicId = (int) ($branch['clinic_id'] ?? 0);

        $clinicDoc = $clinicsCollection->findOne([
            'clinic_id' => $clinicId
        ]);

        if (!$clinicDoc) {
            continue;
        }

        $clinic = $clinicDoc->getArrayCopy();

        if (!empty($clinic['name'])) {
            $clinicNames[] = $clinic['name'];
        }

        if ($clinicId === $clinic_id) {
            $matchesClinic = true;
        }
    }

    if (!$matchesClinic) {
        continue;
    }

    // Check patient's favorite status
    $favoriteDoc = null;

    if ($patient_id !== null) {
        $favoriteDoc = $favoritesCollection->findOne([
            'patient_id' => (int) $patient_id,
            'doctor_id' => $doctorId
        ]);
    }

    $doctors[] = [
        'doctor_id' => $doctorId,
        'specialization_id' => (int) ($doctor['specialization_id'] ?? 0),
        'qualification' => $doctor['qualification'] ?? '',
        'experience' => $experience,
        'consultation_fee' => $fee,
        'verification_status' => $doctor['verification_status'] ?? '',
        'doctor_name' => $doctorName,
        'specialization' => $specialization,
        'avg_rating' => $averageRating,
        'review_count' => $reviewCount,
        'clinic_names' => implode(', ', array_unique($clinicNames)),
        'is_favorite' => $favoriteDoc ? 1 : 0
    ];
}

// Sort by rating, then experience, then doctor name
usort($doctors, function ($a, $b) {
    $ratingComparison = $b['avg_rating'] <=> $a['avg_rating'];

    if ($ratingComparison !== 0) {
        return $ratingComparison;
    }

    $experienceComparison = $b['experience'] <=> $a['experience'];

    if ($experienceComparison !== 0) {
        return $experienceComparison;
    }

    return strcasecmp($a['doctor_name'], $b['doctor_name']);
});

// Keep the existing maximum of 50 doctors
$doctors = array_slice($doctors, 0, 50);

?>

<!-- =========================================================
     Page Header
========================================================= -->

<?php if (($_GET['view'] ?? '') !== 'department'): ?>

<div class="doctor-page-header">
    <div>
        <div class="mini-label mb-2">
            Healthcare Directory
        </div>

        <h1 class="doctor-page-title">
            Find the Right Doctor
        </h1>

        <p class="doctor-page-subtitle">
            Search verified doctors by name, specialization,
            clinic, experience, consultation fee and rating.
        </p>
    </div>

    <div class="doctor-count">
        <strong><?= count($doctors) ?></strong>
        <span>
            doctor<?= count($doctors) !== 1 ? 's' : '' ?> found
        </span>
    </div>
</div>

<?php else: ?>

<div class="department-page-heading">
    <div class="department-heading-label">
        MEDIBOOK · HEALTHCARE DIRECTORY
    </div>

    <h1>
        <?= h(
            $department === 'other'
                ? 'Other Specialties'
                : ($department === 'medicine'
                    ? 'Medicine & General Care'
                    : ucfirst($department))
        ) ?>
    </h1>

    <p>Find the right doctor for your healthcare needs.</p>
</div>

<div class="department-results-header">
    <p>
        <?= count($doctors) ?>
        doctor<?= count($doctors) !== 1 ? 's' : '' ?> found
    </p>
</div>

<?php endif; ?>


<?php if (($_GET['view'] ?? '') !== 'department'): ?>

<!-- =========================================================
     Search & Filter
========================================================= -->

<div class="doctor-filter-panel">

    <form method="GET">

        <?php if ($department !== ''): ?>
            <input type="hidden"
                   name="department"
                   value="<?= h($department) ?>">
        <?php endif; ?>

        <div class="doctor-search-row">

            <div class="doctor-search-box">

                <i class="bi bi-search"></i>

                <input
                    type="text"
                    name="q"
                    value="<?= h($q) ?>"
                    placeholder="Search doctor, specialization or qualification..."
                >

            </div>

            <button
                type="submit"
                class="btn btn-primary doctor-search-btn"
            >
                Search Doctors
            </button>

        </div>


        <div class="doctor-filter-grid">

            <div>

                <label>
                    Specialization
                </label>

                <select
                    name="specialization_id"
                    class="form-select"
                >

                    <option value="0">
                        All Specializations
                    </option>

                    <?php foreach ($specializations as $specialization): ?>

                        <option
                            value="<?= (int)$specialization['specialization_id'] ?>"
                            <?= $specialization_id === (int)$specialization['specialization_id']
                                ? 'selected'
                                : '' ?>
                        >

                            <?= h($specialization['name']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div>

                <label>
                    Clinic
                </label>

                <select
                    name="clinic_id"
                    class="form-select"
                >

                    <option value="0">
                        All Clinics
                    </option>

                    <?php foreach ($clinics as $clinic): ?>

                        <option
                            value="<?= (int)$clinic['clinic_id'] ?>"
                            <?= $clinic_id === (int)$clinic['clinic_id']
                                ? 'selected'
                                : '' ?>
                        >

                            <?= h($clinic['name']) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div>

                <label>
                    Minimum Fee
                </label>

                <input
                    type="number"
                    name="min_fee"
                    class="form-control"
                    min="0"
                    step="0.01"
                    value="<?= $min_fee > 0 ? h($min_fee) : '' ?>"
                    placeholder="৳ 0"
                >

            </div>


            <div>

                <label>
                    Maximum Fee
                </label>

                <input
                    type="number"
                    name="max_fee"
                    class="form-control"
                    min="0"
                    step="0.01"
                    value="<?= $max_fee > 0 ? h($max_fee) : '' ?>"
                    placeholder="৳ 5000"
                >

            </div>


            <div>

                <label>
                    Minimum Experience
                </label>

                <input
                    type="number"
                    name="min_experience"
                    class="form-control"
                    min="0"
                    value="<?= $min_experience > 0 ? h($min_experience) : '' ?>"
                    placeholder="Years"
                >

            </div>


            <div>

                <label>
                    Minimum Rating
                </label>

                <select
                    name="rating"
                    class="form-select"
                >

                    <option value="0">
                        Any Rating
                    </option>

                    <option value="4" <?= $rating == 4 ? 'selected' : '' ?>>
                        ⭐ 4.0+
                    </option>

                    <option value="4.5" <?= $rating == 4.5 ? 'selected' : '' ?>>
                        ⭐ 4.5+
                    </option>

                    <option value="4.8" <?= $rating == 4.8 ? 'selected' : '' ?>>
                        ⭐ 4.8+
                    </option>

                </select>

            </div>

        </div>


        <div class="filter-bottom">

            <div class="filter-info">

                <i class="bi bi-funnel"></i>

                Use multiple filters to narrow your search.

            </div>

            <a
                href="doctors.php"
                class="clear-filter"
            >
                Clear Filters
            </a>

        </div>

    </form>

</div>

<?php endif; ?>

<!-- =========================================================
     Doctor Results
========================================================= -->

<?php if ($doctors): ?>

    <div class="doctor-results-grid">

        <?php foreach ($doctors as $doctor): ?>

            <?php
                $isFavorite = (int)$doctor['is_favorite'] === 1;
            ?>

            <div class="professional-doctor-card">

                <!-- Doctor Top -->

                <div class="doctor-card-top">

                    <div class="doctor-avatar">

                        <?= h(
                            strtoupper(
                                substr(
                                    $doctor['doctor_name'],
                                    0,
                                    1
                                )
                            )
                        ) ?>

                    </div>


                    <div class="doctor-main-info">

                        <div class="verified-label">

                            <i class="bi bi-patch-check-fill"></i>

                            Verified Doctor

                        </div>

                        <h3>

                            Dr. <?= h($doctor['doctor_name']) ?>

                        </h3>

                        <p class="doctor-specialization">

                            <?= h(
                                $doctor['specialization']
                                ?? 'General Physician'
                            ) ?>

                        </p>

                    </div>


                    <!-- Favorite Button -->

                    <button
                        type="button"
                        class="favorite-btn <?= $isFavorite ? 'active' : '' ?>"
                        data-doctor-id="<?= (int)$doctor['doctor_id'] ?>"
                        aria-label="<?= $isFavorite ? 'Remove from favorites' : 'Add to favorites' ?>"
                        title="<?= $isFavorite ? 'Remove from favorites' : 'Add to favorites' ?>"
                    >

                        <i class="bi <?= $isFavorite ? 'bi-heart-fill' : 'bi-heart' ?>"></i>

                    </button>

                </div>


                <!-- Doctor Stats -->

                <div class="doctor-stats-row">

                    <div>

                        <span class="doctor-stat-label">
                            Experience
                        </span>

                        <strong>
                            <?= h($doctor['experience']) ?> yrs
                        </strong>

                    </div>


                    <div>

                        <span class="doctor-stat-label">
                            Rating
                        </span>

                        <strong class="rating-value">

                            <i class="bi bi-star-fill"></i>

                            <?= number_format(
                                (float)$doctor['avg_rating'],
                                1
                            ) ?>

                        </strong>

                        <small>
                            (<?= (int)$doctor['review_count'] ?>)
                        </small>

                    </div>


                    <div>

                        <span class="doctor-stat-label">
                            Consultation
                        </span>

                        <strong>

                            ৳<?= number_format(
                                (float)$doctor['consultation_fee'],
                                0
                            ) ?>

                        </strong>

                    </div>

                </div>


                <!-- Qualification -->

                <div class="doctor-qualification">

                    <i class="bi bi-mortarboard"></i>

                    <span>

                        <?= h(
                            $doctor['qualification']
                            ?: 'Professional qualification not listed'
                        ) ?>

                    </span>

                </div>


                <!-- Clinics -->

                <div class="doctor-clinic">

                    <i class="bi bi-hospital"></i>

                    <span>

                        <?= h(
                            $doctor['clinic_names']
                            ?: 'Clinic information not available'
                        ) ?>

                    </span>

                </div>


                <!-- Actions -->

                <div class="doctor-card-actions">

                    <a
                        href="doctor-details.php?id=<?= (int)$doctor['doctor_id'] ?>"
                        class="btn btn-outline-primary"
                    >
                        View Profile
                    </a>

                    <a
                        href="book.php?doctor_id=<?= (int)$doctor['doctor_id'] ?>"
                        class="btn btn-primary"
                    >

                        <i class="bi bi-calendar-plus me-1"></i>

                        Book

                    </a>

                </div>

            </div>

        <?php endforeach; ?>

    </div>

<?php else: ?>

    <!-- No Results -->

    <div class="no-doctor-result">

        <div class="no-doctor-icon">

            <i class="bi bi-person-x"></i>

        </div>

        <h3>
            No doctors found
        </h3>

        <p>
            Try changing your search terms or removing some filters.
        </p>

        <a
            href="doctors.php"
            class="btn btn-primary"
        >
            Reset Search
        </a>

    </div>

<?php endif; ?>


<style>

/* =========================================================
   Doctor Directory
========================================================= */

.doctor-page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 25px;
    margin-bottom: 25px;
}

.doctor-page-title {
    margin: 0;
    font-size: clamp(2rem, 4vw, 2.8rem);
    font-weight: 800;
    letter-spacing: -0.04em;
}

.doctor-page-subtitle {
    color: var(--muted);
    max-width: 700px;
    margin: 8px 0 0;
}

.doctor-count {
    min-width: 120px;
    text-align: right;
}

.doctor-count strong {
    display: block;
    font-size: 1.8rem;
    font-weight: 800;
}

.doctor-count span {
    color: var(--muted);
    font-size: .78rem;
}


/* =========================================================
   Filter
========================================================= */

.doctor-filter-panel {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow);
    padding: 20px;
    margin-bottom: 30px;
}

.doctor-search-row {
    display: flex;
    gap: 10px;
    margin-bottom: 18px;
}

.doctor-search-box {
    position: relative;
    flex: 1;
}

.doctor-search-box i {
    position: absolute;
    left: 15px;
    top: 50%;
    transform: translateY(-50%);
    color: #8a99aa;
}

.doctor-search-box input {
    width: 100%;
    min-height: 48px;
    padding: 12px 15px 12px 43px;
    border: 1px solid #dbe5ef;
    border-radius: 12px;
    outline: none;
}

.doctor-search-box input:focus {
    border-color: #83b8ff;
    box-shadow: 0 0 0 .2rem rgba(22,119,255,.1);
}

.doctor-search-btn {
    min-width: 145px;
}

.doctor-filter-grid {
    display: grid;
    grid-template-columns:
        repeat(3, minmax(0, 1fr));
    gap: 14px;
}

.doctor-filter-grid label {
    display: block;
    margin-bottom: 6px;
    font-size: .76rem;
    font-weight: 700;
    color: var(--muted);
}

.filter-bottom {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-top: 16px;
    padding-top: 15px;
    border-top: 1px solid var(--border);
}

.filter-info {
    color: var(--muted);
    font-size: .78rem;
}

.filter-info i {
    color: var(--primary);
    margin-right: 4px;
}

.clear-filter {
    color: var(--primary);
    text-decoration: none;
    font-size: .8rem;
    font-weight: 700;
}


/* =========================================================
   Doctor Cards
========================================================= */

.doctor-results-grid {
    display: grid;
    grid-template-columns:
        repeat(3, minmax(0, 1fr));
    gap: 20px;
}

.professional-doctor-card {
    position: relative;
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 20px;
    box-shadow: var(--shadow);
    transition:
        transform .2s ease,
        box-shadow .2s ease,
        border-color .2s ease;
}

.professional-doctor-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 20px 45px rgba(19,46,82,.12);
    border-color: #cbdcf0;
}

.doctor-card-top {
    display: flex;
    gap: 14px;
    align-items: center;
}

.doctor-avatar {
    width: 64px;
    height: 64px;
    flex: 0 0 64px;
    border-radius: 18px;
    display: grid;
    place-items: center;
    background: linear-gradient(
        135deg,
        #e9f2ff,
        #e6fbf7
    );
    color: var(--primary);
    font-size: 1.4rem;
    font-weight: 800;
}

.doctor-main-info {
    min-width: 0;
    flex: 1;
}

.verified-label {
    color: #0b9b79;
    font-size: .68rem;
    font-weight: 800;
    margin-bottom: 3px;
}

.doctor-main-info h3 {
    font-size: 1rem;
    font-weight: 800;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.doctor-specialization {
    color: var(--primary);
    font-size: .8rem;
    font-weight: 600;
    margin: 2px 0 0;
}


/* =========================================================
   Favorite Button
========================================================= */

.favorite-btn {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;

    border: 1px solid #dbe5ef;
    border-radius: 12px;

    background: #fff;
    color: #7b8a9a;

    display: grid;
    place-items: center;

    font-size: 1.1rem;

    cursor: pointer;

    transition:
        background .2s ease,
        color .2s ease,
        border-color .2s ease,
        transform .2s ease;
}

.favorite-btn:hover {
    background: #fff5f6;
    color: #dc3545;
    border-color: #f2b8bf;
    transform: scale(1.04);
}

.favorite-btn.active {
    background: #fff1f3;
    color: #dc3545;
    border-color: #f0b2ba;
}

.favorite-btn.active:hover {
    background: #ffe5e9;
}

.favorite-btn.loading {
    pointer-events: none;
    opacity: .6;
}


/* =========================================================
   Stats
========================================================= */

.doctor-stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 8px;
    margin: 20px 0 15px;
    padding: 14px 0;
    border-top: 1px solid var(--border);
    border-bottom: 1px solid var(--border);
}

.doctor-stats-row > div {
    min-width: 0;
    text-align: center;
}

.doctor-stat-label {
    display: block;
    color: var(--muted);
    font-size: .65rem;
    margin-bottom: 4px;
}

.doctor-stats-row strong {
    font-size: .82rem;
    font-weight: 800;
}

.doctor-stats-row small {
    color: var(--muted);
    font-size: .65rem;
}

.rating-value {
    color: #b97800;
}

.rating-value i {
    font-size: .68rem;
}


/* =========================================================
   Qualification / Clinic
========================================================= */

.doctor-qualification,
.doctor-clinic {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    color: var(--muted);
    font-size: .76rem;
    line-height: 1.5;
    margin-bottom: 10px;
}

.doctor-qualification i,
.doctor-clinic i {
    color: var(--primary);
    margin-top: 2px;
    flex: 0 0 auto;
}


/* =========================================================
   Actions
========================================================= */

.doctor-card-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 9px;
    margin-top: 18px;
}

.doctor-card-actions .btn {
    min-height: 42px;
    font-size: .82rem;
}


/* =========================================================
   No Result
========================================================= */

.no-doctor-result {
    background: #fff;
    border: 1px solid var(--border);
    border-radius: 20px;
    box-shadow: var(--shadow);
    text-align: center;
    padding: 65px 20px;
}

.no-doctor-icon {
    width: 68px;
    height: 68px;
    margin: 0 auto 15px;
    border-radius: 20px;
    display: grid;
    place-items: center;
    background: #f1f6fb;
    color: var(--primary);
    font-size: 28px;
}

.no-doctor-result h3 {
    font-size: 1.2rem;
    font-weight: 800;
}

.no-doctor-result p {
    color: var(--muted);
}


/* =========================================================
   Toast
========================================================= */

.favorite-toast {
    position: fixed;
    right: 24px;
    bottom: 24px;
    z-index: 9999;

    max-width: 320px;

    background: #fff;
    border: 1px solid var(--border);
    border-radius: 12px;

    box-shadow: 0 15px 40px rgba(19,46,82,.15);

    padding: 13px 16px;

    font-size: .85rem;
    font-weight: 600;

    opacity: 0;
    transform: translateY(15px);

    pointer-events: none;

    transition:
        opacity .2s ease,
        transform .2s ease;
}

.favorite-toast.show {
    opacity: 1;
    transform: translateY(0);
}

.favorite-toast.success {
    border-left: 4px solid #198754;
}

.favorite-toast.error {
    border-left: 4px solid #dc3545;
}


/* =========================================================
   Responsive
========================================================= */

@media (max-width: 1100px) {

    .doctor-results-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

}


@media (max-width: 900px) {

    .doctor-filter-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

}


@media (max-width: 700px) {

    .doctor-page-header {
        align-items: flex-start;
        flex-direction: column;
    }

    .doctor-count {
        text-align: left;
    }

    .doctor-search-row {
        flex-direction: column;
    }

    .doctor-search-btn {
        width: 100%;
    }

    .doctor-results-grid {
        grid-template-columns: 1fr;
    }

}


@media (max-width: 520px) {

    .doctor-filter-grid {
        grid-template-columns: 1fr;
    }

    .doctor-filter-panel {
        padding: 16px;
    }

    .filter-bottom {
        align-items: flex-start;
        flex-direction: column;
    }

    .doctor-card-actions {
        grid-template-columns: 1fr;
    }

    .favorite-toast {
        left: 15px;
        right: 15px;
        bottom: 15px;
        max-width: none;
    }

}


/* Improve Text Readability */
.doctor-page-subtitle {
    font-size: 1.1rem;
}

.doctor-count span {
    font-size: 0.9rem;
}

.doctor-search-box input {
    font-size: 1rem;
}

.doctor-filter-grid label {
    font-size: 0.9rem;
}

.doctor-filter-grid .form-control,
.doctor-filter-grid .form-select {
    font-size: 1rem;
}

.doctor-search-btn {
    font-size: 1rem;
}

.filter-info {
    font-size: 0.95rem;
}

.clear-filter {
    font-size: 0.95rem;
}

/* Doctor Card Text */
.verified-label {
    font-size: 0.85rem;
}

.doctor-main-info h3 {
    font-size: 1.15rem;
}

.doctor-specialization {
    font-size: 0.95rem;
}

.doctor-stat-label {
    font-size: 0.85rem;
}

.doctor-stats-row strong {
    font-size: 1rem;
}

.doctor-stats-row small {
    font-size: 0.85rem;
}

.doctor-qualification,
.doctor-clinic {
    font-size: 0.95rem;
}

.doctor-card-actions .btn {
    font-size: 0.95rem;
}

.no-doctor-result p {
    font-size: 1rem;
}

/* Department page layout */
.department-results-header {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    width: 100%;
    margin: 0 0 18px;
    padding: 0 8px;
    min-height: 36px;
}

.department-results-header p {
    margin: 0;
    text-align: right;
    font-weight: 600;
    color: #2563eb;
}

.department-page-heading {
    text-align: center;
    padding: 30px 16px 22px;
    margin-bottom: 12px;
}

.department-heading-label {
    color: #2563eb;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 1.5px;
    margin-bottom: 10px;
}

.department-page-heading h1 {
    color: #172033;
    font-size: clamp(28px, 4vw, 42px);
    font-weight: 800;
    margin: 0 0 10px;
}

.department-page-heading p {
    color: #64748b;
    font-size: 16px;
    margin: 0;
}

</style>


<!-- =========================================================
     Favorite JavaScript
========================================================= -->

<div
    id="favoriteToast"
    class="favorite-toast"
></div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const favoriteButtons =
        document.querySelectorAll('.favorite-btn');

    const toast =
        document.getElementById('favoriteToast');


    function showToast(message, type = 'success') {

        toast.textContent = message;

        toast.className =
            'favorite-toast show ' + type;

        setTimeout(function () {

            toast.className =
                'favorite-toast';

        }, 2500);
    }


    favoriteButtons.forEach(function (button) {

        button.addEventListener('click', async function () {

            const doctorId =
                this.dataset.doctorId;

            if (!doctorId) {
                return;
            }


            this.classList.add('loading');


            try {

                const response = await fetch(
                    '../api/favorite.php',
                    {
                        method: 'POST',

                        headers: {
                            'Content-Type':
                                'application/x-www-form-urlencoded',
                            'X-Requested-With':
                                'XMLHttpRequest'
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


                if (data.favorite) {

                    this.classList.add('active');

                    this.querySelector('i').className =
                        'bi bi-heart-fill';

                    this.setAttribute(
                        'aria-label',
                        'Remove from favorites'
                    );

                    this.setAttribute(
                        'title',
                        'Remove from favorites'
                    );

                    showToast(
                        'Doctor added to favorites.',
                        'success'
                    );

                } else {

                    this.classList.remove('active');

                    this.querySelector('i').className =
                        'bi bi-heart';

                    this.setAttribute(
                        'aria-label',
                        'Add to favorites'
                    );

                    this.setAttribute(
                        'title',
                        'Add to favorites'
                    );

                    showToast(
                        'Doctor removed from favorites.',
                        'success'
                    );

                }

            } catch (error) {

                console.error(error);

                showToast(
                    error.message ||
                    'Something went wrong.',
                    'error'
                );

            } finally {

                this.classList.remove('loading');

            }

        });

    });

});

</script>


<?php require '../includes/footer.php'; ?>