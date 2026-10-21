<?php

$page_title = 'Reviews';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require '../includes/header.php';
require_role('admin');

/*
|--------------------------------------------------------------------------
| MongoDB Collections
|--------------------------------------------------------------------------
*/

$reviewsCollection = $mongoDb->selectCollection('reviews');
$patientsCollection = $mongoDb->selectCollection('patients');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');

$currentAdminId = (int)($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| Helper: Find Record by Numeric or String ID
|--------------------------------------------------------------------------
*/

if (!function_exists('review_find_by_id')) {
    function review_find_by_id($collection, string $field, $id): array
    {
        $document = $collection->findOne([$field => $id]);

        if (!$document) {
            $stringId = (string)$id;

            if ($stringId !== (string)(int)$id) {
                $document = $collection->findOne([
                    $field => $stringId
                ]);
            } else {
                $document = $collection->findOne([
                    $field => (int)$id
                ]);

                if (!$document) {
                    $document = $collection->findOne([
                        $field => $stringId
                    ]);
                }
            }
        }

        return $document ? (array)$document : [];
    }
}

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'delete_review') {
        $review_id = (int)($_POST['review_id'] ?? 0);

        if ($review_id <= 0) {
            flash('danger', 'Invalid review.');
            header('Location: reviews.php');
            exit;
        }

        $review = review_find_by_id(
            $reviewsCollection,
            'review_id',
            $review_id
        );

        if (!$review) {
            flash('warning', 'Review not found.');
            header('Location: reviews.php');
            exit;
        }

        $reviewsCollection->deleteOne([
            'review_id' => $review['review_id']
        ]);

        audit(
            $currentAdminId,
            'Deleted review',
            'review',
            $review_id
        );

        flash('success', 'Review deleted successfully.');

        header('Location: reviews.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$rating_filter = (int)($_GET['rating'] ?? 0);

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = 15;
$offset = ($page - 1) * $per_page;

/*
|--------------------------------------------------------------------------
| Load Reviews and Related Names
|--------------------------------------------------------------------------
*/

$reviewDocuments = $reviewsCollection->find(
    [],
    [
        'sort' => [
            'created_at' => -1,
            'review_id' => -1
        ]
    ]
)->toArray();

$allRows = [];
$patientCache = [];
$doctorCache = [];
$userCache = [];

foreach ($reviewDocuments as $document) {
    $r = (array)$document;

    $patientId = $r['patient_id'] ?? null;
    $doctorId = $r['doctor_id'] ?? null;

    $patientName = 'Unknown';
    $patientEmail = '';
    $doctorName = 'Unknown';

    if ($patientId !== null) {
        $patientKey = (string)$patientId;

        if (!isset($patientCache[$patientKey])) {
            $patientCache[$patientKey] = review_find_by_id(
                $patientsCollection,
                'patient_id',
                $patientId
            );
        }

        $patient = $patientCache[$patientKey];
        $patientUserId = $patient['user_id'] ?? null;

        if ($patientUserId !== null) {
            $userKey = (string)$patientUserId;

            if (!isset($userCache[$userKey])) {
                $userCache[$userKey] = review_find_by_id(
                    $usersCollection,
                    'user_id',
                    $patientUserId
                );
            }

            $patientUser = $userCache[$userKey];

            $patientName = $patientUser['name'] ?? 'Unknown';
            $patientEmail = $patientUser['email'] ?? '';
        }
    }

    if ($doctorId !== null) {
        $doctorKey = (string)$doctorId;

        if (!isset($doctorCache[$doctorKey])) {
            $doctorCache[$doctorKey] = review_find_by_id(
                $doctorsCollection,
                'doctor_id',
                $doctorId
            );
        }

        $doctor = $doctorCache[$doctorKey];
        $doctorUserId = $doctor['user_id'] ?? null;

        if ($doctorUserId !== null) {
            $userKey = (string)$doctorUserId;

            if (!isset($userCache[$userKey])) {
                $userCache[$userKey] = review_find_by_id(
                    $usersCollection,
                    'user_id',
                    $doctorUserId
                );
            }

            $doctorName = $userCache[$userKey]['name'] ?? 'Unknown';
        }
    }

    $createdAt = $r['created_at'] ?? '';

    if ($createdAt instanceof MongoDB\BSON\UTCDateTime) {
        $createdAt = $createdAt
            ->toDateTime()
            ->format('Y-m-d H:i:s');
    } elseif ($createdAt instanceof DateTimeInterface) {
        $createdAt = $createdAt->format('Y-m-d H:i:s');
    } else {
        $createdAt = (string)$createdAt;
    }

    $allRows[] = [
        'review_id' => $r['review_id'] ?? 0,
        'appointment_id' => $r['appointment_id'] ?? null,
        'patient_id' => $patientId,
        'doctor_id' => $doctorId,
        'rating' => (int)($r['rating'] ?? 0),
        'review_text' => (string)($r['review_text'] ?? ''),
        'created_at' => $createdAt,
        'patient_name' => $patientName,
        'patient_email' => $patientEmail,
        'doctor_name' => $doctorName
    ];
}

/*
|--------------------------------------------------------------------------
| Search and Rating Filter
|--------------------------------------------------------------------------
*/

$filteredRows = [];

foreach ($allRows as $row) {
    if ($rating_filter >= 1 && $rating_filter <= 5 &&
        $row['rating'] !== $rating_filter) {
        continue;
    }

    if ($search !== '') {
        $searchText = strtolower(
            $row['patient_name'] . ' ' .
            $row['doctor_name'] . ' ' .
            $row['review_text']
        );

        if (strpos($searchText, strtolower($search)) === false) {
            continue;
        }
    }

    $filteredRows[] = $row;
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$total_rows = count($filteredRows);

$total_pages = max(
    1,
    (int)ceil($total_rows / $per_page)
);

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

$rows = array_slice(
    $filteredRows,
    $offset,
    $per_page
);

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_reviews = count($allRows);

$ratingSum = 0;

$rating_counts = [
    5 => 0,
    4 => 0,
    3 => 0,
    2 => 0,
    1 => 0
];

foreach ($allRows as $row) {
    $rating = (int)$row['rating'];

    $ratingSum += $rating;

    if ($rating >= 1 && $rating <= 5) {
        $rating_counts[$rating]++;
    }
}

$average_rating = $total_reviews > 0
    ? round($ratingSum / $total_reviews, 1)
    : 0;

/*
|--------------------------------------------------------------------------
| Pagination Query
|--------------------------------------------------------------------------
*/

function review_query(array $extra = []): string
{
    $query = [
        'search' => $_GET['search'] ?? '',
        'rating' => $_GET['rating'] ?? '',
        'page'   => $_GET['page'] ?? 1
    ];


    foreach ($extra as $key => $value) {

        $query[$key] = $value;
    }


    $query = array_filter(
        $query,
        static fn($value) => $value !== ''
    );


    return http_build_query($query);
}

?>

<!-- Page Header -->

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

    <div>

        <span class="eyebrow">
            <i class="bi bi-star"></i>
            Administration
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            Review Management
        </h1>

        <p class="text-secondary mb-0">
            Monitor patient feedback and doctor ratings.
        </p>

    </div>

</div>


<!-- Statistics -->

<div class="row g-3 mb-4">

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Total Reviews
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_reviews)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Average Rating
            </div>

            <div class="fs-3 fw-bold mt-1">

                <?=number_format(
                    $average_rating,
                    1
                )?>

                <span class="fs-5 text-warning">
                    ★
                </span>

            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                5-Star Reviews
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($rating_counts[5])?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                1-Star Reviews
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($rating_counts[1])?>
            </div>

        </div>

    </div>

</div>


<!-- Rating Distribution -->

<div class="card border-0 shadow-sm p-4 mb-4">

    <div class="row g-4 align-items-center">

        <div class="col-lg-4">

            <div class="text-center">

                <div class="display-5 fw-bold">
                    <?=number_format(
                        $average_rating,
                        1
                    )?>
                </div>

                <div class="text-warning fs-4">
                    ★★★★★
                </div>

                <div class="text-secondary small mt-1">
                    Based on <?=number_format($total_reviews)?> reviews
                </div>

            </div>

        </div>


        <div class="col-lg-8">

            <?php for ($rating = 5; $rating >= 1; $rating--): ?>

                <?php

                $count = $rating_counts[$rating];

                $percentage = $total_reviews > 0
                    ? round(
                        ($count / $total_reviews) * 100,
                        1
                    )
                    : 0;

                ?>

                <div class="d-flex align-items-center gap-2 mb-2">

                    <div
                        style="width:55px;"
                        class="small fw-semibold"
                    >
                        <?=$rating?> Star
                    </div>


                    <div
                        class="progress flex-grow-1"
                        style="height:8px;"
                    >

                        <div
                            class="progress-bar"
                            style="width:<?=$percentage?>%"
                        ></div>

                    </div>


                    <div
                        style="width:55px;"
                        class="text-end small text-secondary"
                    >
                        <?=$count?>
                    </div>

                </div>

            <?php endfor; ?>

        </div>

    </div>

</div>


<!-- Filters -->

<div class="card border-0 shadow-sm p-3 mb-4">

    <form method="get" class="row g-2">

        <div class="col-lg-6">

            <div class="input-group">

                <span class="input-group-text bg-white">
                    <i class="bi bi-search"></i>
                </span>

                <input
                    type="text"
                    name="search"
                    value="<?=h($search)?>"
                    class="form-control"
                    placeholder="Search patient, doctor or review..."
                >

            </div>

        </div>


        <div class="col-lg-4">

            <select
                name="rating"
                class="form-select"
            >

                <option value="">
                    All Ratings
                </option>

                <?php for ($rating = 5; $rating >= 1; $rating--): ?>

                    <option
                        value="<?=$rating?>"
                        <?=$rating_filter === $rating ? 'selected' : ''?>
                    >

                        <?=$rating?> Star
                        (<?=$rating_counts[$rating]?>)

                    </option>

                <?php endfor; ?>

            </select>

        </div>


        <div class="col-lg-2">

            <button class="btn btn-primary w-100">

                <i class="bi bi-filter me-1"></i>

                Filter

            </button>

        </div>

    </form>

</div>


<!-- Review Table -->

<div class="card border-0 shadow-sm overflow-hidden">

    <div class="card-header bg-white py-3">

        <div class="d-flex justify-content-between align-items-center">

            <div>

                <h5 class="fw-bold mb-1">
                    Patient Reviews
                </h5>

                <small class="text-secondary">

                    Showing
                    <?=count($rows)?>
                    of
                    <?=number_format($total_rows)?>
                    reviews

                </small>

            </div>


            <span class="badge text-bg-light border">

                Page <?=$page?> / <?=$total_pages?>

            </span>

        </div>

    </div>


    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>

                    <th>Patient</th>

                    <th>Doctor</th>

                    <th>Rating</th>

                    <th>Review</th>

                    <th>Date</th>

                    <th class="text-end">
                        Action
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($rows as $r): ?>

                    <tr>

                        <!-- Patient -->

                        <td>

                            <strong>
                                <?=h($r['patient_name'])?>
                            </strong>

                            <br>

                            <small class="text-secondary">

                                <?=h($r['patient_email'])?>

                            </small>

                        </td>


                        <!-- Doctor -->

                        <td>

                            <strong>
                                Dr. <?=h($r['doctor_name'])?>
                            </strong>

                        </td>


                        <!-- Rating -->

                        <td>

                            <div
                                class="text-warning"
                                style="white-space:nowrap;"
                            >

                                <?=str_repeat(
                                    '★',
                                    (int)$r['rating']
                                )?>

                                <span class="text-secondary ms-1">
                                    <?=h($r['rating'])?>/5
                                </span>

                            </div>

                        </td>


                        <!-- Review -->

                        <td style="min-width:260px;">

                            <?php if (trim($r['review_text'] ?? '') !== ''): ?>

                                <div>
                                    <?=nl2br(
                                        h($r['review_text'])
                                    )?>
                                </div>

                            <?php else: ?>

                                <span class="text-secondary">
                                    No written review.
                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- Date -->

                        <td>

                            <strong>

                                <?=h(
                                    date(
                                        'd M Y',
                                        strtotime(
                                            $r['created_at']
                                        )
                                    )
                                )?>

                            </strong>

                            <br>

                            <small class="text-secondary">

                                <?=h(
                                    date(
                                        'h:i A',
                                        strtotime(
                                            $r['created_at']
                                        )
                                    )
                                )?>

                            </small>

                        </td>


                        <!-- Action -->

                        <td class="text-end">

                            <form
                                method="post"
                                class="d-inline"
                                onsubmit="return confirm('Delete this review permanently?');"
                            >

                                <?=csrf_field()?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="delete_review"
                                >

                                <input
                                    type="hidden"
                                    name="review_id"
                                    value="<?=$r['review_id']?>"
                                >

                                <button
                                    class="btn btn-sm btn-outline-danger"
                                    title="Delete Review"
                                >

                                    <i class="bi bi-trash"></i>

                                </button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$rows): ?>

                    <tr>

                        <td colspan="6">

                            <div class="empty-state p-5 text-center">

                                <i class="bi bi-star fs-1 d-block mb-3"></i>

                                <h5 class="fw-bold">
                                    No reviews found
                                </h5>

                                <p class="text-secondary mb-0">
                                    Try changing your search or rating filter.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>


    <!-- Pagination -->

    <?php if ($total_pages > 1): ?>

        <div class="card-footer bg-white">

            <nav>

                <ul class="pagination justify-content-center mb-0">


                    <!-- Previous -->

                    <li
                        class="page-item <?=$page <= 1 ? 'disabled' : ''?>"
                    >

                        <a
                            class="page-link"
                            href="?<?=h(
                                review_query([
                                    'page' => max(
                                        1,
                                        $page - 1
                                    )
                                ])
                            )?>"
                        >
                            Previous
                        </a>

                    </li>


                    <?php

                    $start_page = max(
                        1,
                        $page - 2
                    );

                    $end_page = min(
                        $total_pages,
                        $page + 2
                    );

                    for (
                        $i = $start_page;
                        $i <= $end_page;
                        $i++
                    ):
                    ?>

                        <li
                            class="page-item <?=$i === $page ? 'active' : ''?>"
                        >

                            <a
                                class="page-link"
                                href="?<?=h(
                                    review_query([
                                        'page' => $i
                                    ])
                                )?>"
                            >
                                <?=$i?>
                            </a>

                        </li>

                    <?php endfor; ?>


                    <!-- Next -->

                    <li
                        class="page-item <?=$page >= $total_pages ? 'disabled' : ''?>"
                    >

                        <a
                            class="page-link"
                            href="?<?=h(
                                review_query([
                                    'page' => min(
                                        $total_pages,
                                        $page + 1
                                    )
                                ])
                            )?>"
                        >
                            Next
                        </a>

                    </li>

                </ul>

            </nav>

        </div>

    <?php endif; ?>

</div>


<?php require '../includes/footer.php'; ?>