<?php

$page_title = 'Specialization Management';
$asset_prefix = '../';

require_once '../config/mongodb.php';
require '../includes/header.php';
require_role('admin');

$admin_user_id = (int) $_SESSION['user_id'];

/*
|--------------------------------------------------------------------------
| MongoDB Collections
|--------------------------------------------------------------------------
*/

$specializationsCollection = $mongoDb->selectCollection('specializations');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$countersCollection = $mongoDb->selectCollection('counters');

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search = trim($_GET['q'] ?? '');

/*
|--------------------------------------------------------------------------
| Helper: Find Specialization by ID
|--------------------------------------------------------------------------
*/

if (!function_exists('find_specialization_by_id')) {
    function find_specialization_by_id($collection, int $id): array
    {
        $document = $collection->findOne(['specialization_id' => $id]);

        if (!$document) {
            $document = $collection->findOne([
                'specialization_id' => (string)$id
            ]);
        }

        return $document ? (array)$document : [];
    }
}

/*
|--------------------------------------------------------------------------
| Helper: Generate Numeric Specialization ID
|--------------------------------------------------------------------------
*/

if (!function_exists('next_specialization_id')) {
    function next_specialization_id($specializationsCollection, $countersCollection): int
    {
        $latest = $specializationsCollection->find(
            [],
            [
                'projection' => ['specialization_id' => 1],
                'sort' => ['specialization_id' => -1],
                'limit' => 1
            ]
        )->toArray();

        $maxId = 0;

        if ($latest) {
            $maxId = (int)($latest[0]['specialization_id'] ?? 0);
        }

        $countersCollection->updateOne(
            ['_id' => 'specialization_id'],
            ['$max' => ['seq' => $maxId]],
            ['upsert' => true]
        );

        $counter = $countersCollection->findOneAndUpdate(
            ['_id' => 'specialization_id'],
            ['$inc' => ['seq' => 1]],
            [
                'returnDocument' =>
                    MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        );

        return (int)$counter['seq'];
    }
}

/*
|--------------------------------------------------------------------------
| Add / Edit / Delete
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = trim($_POST['action'] ?? '');

    /*
    |--------------------------------------------------------------------------
    | ADD
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            flash('danger', 'Specialization name is required.');
            header('Location: specializations.php');
            exit;
        }

        $duplicate = $specializationsCollection->findOne([
            'name' => [
                '$regex' => '^' . preg_quote($name, '/') . '$',
                '$options' => 'i'
            ]
        ]);

        if ($duplicate) {
            flash('warning', 'This specialization already exists.');
            header('Location: specializations.php');
            exit;
        }

        try {
            $specialization_id = next_specialization_id(
                $specializationsCollection,
                $countersCollection
            );

            $specializationsCollection->insertOne([
                'specialization_id' => $specialization_id,
                'name' => $name,
                'description' => $description !== '' ? $description : null
            ]);

            // Ensure audit() also uses MongoDB.
            audit(
                $admin_user_id,
                'Added specialization',
                'specialization',
                $specialization_id
            );

            flash('success', 'Specialization added successfully.');
        } catch (Throwable $e) {
            error_log('Add specialization error: ' . $e->getMessage());
            flash('danger', 'Unable to add specialization.');
        }

        header('Location: specializations.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | EDIT
    |--------------------------------------------------------------------------
    */

    if ($action === 'edit') {
        $specialization_id = (int)($_POST['specialization_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($specialization_id <= 0 || $name === '') {
            flash('danger', 'Invalid specialization data.');
            header('Location: specializations.php');
            exit;
        }

        $existing = find_specialization_by_id(
            $specializationsCollection,
            $specialization_id
        );

        if (!$existing) {
            flash('danger', 'Specialization not found.');
            header('Location: specializations.php');
            exit;
        }

        $duplicate = $specializationsCollection->findOne([
            'specialization_id' => ['$ne' => $existing['specialization_id']],
            'name' => [
                '$regex' => '^' . preg_quote($name, '/') . '$',
                '$options' => 'i'
            ]
        ]);

        if ($duplicate) {
            flash('warning', 'Another specialization already uses this name.');
            header('Location: specializations.php');
            exit;
        }

        try {
            $specializationsCollection->updateOne(
                ['_id' => $existing['_id']],
                [
                    '$set' => [
                        'name' => $name,
                        'description' => $description !== '' ? $description : null
                    ]
                ]
            );

            audit(
                $admin_user_id,
                'Updated specialization',
                'specialization',
                $specialization_id
            );

            flash('success', 'Specialization updated successfully.');
        } catch (Throwable $e) {
            error_log('Update specialization error: ' . $e->getMessage());
            flash('danger', 'Unable to update specialization.');
        }

        header('Location: specializations.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | DELETE
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {
        $specialization_id = (int)($_POST['specialization_id'] ?? 0);

        if ($specialization_id <= 0) {
            flash('danger', 'Invalid specialization.');
            header('Location: specializations.php');
            exit;
        }

        $existing = find_specialization_by_id(
            $specializationsCollection,
            $specialization_id
        );

        if (!$existing) {
            flash('danger', 'Specialization not found.');
            header('Location: specializations.php');
            exit;
        }

        $doctor_count = $doctorsCollection->countDocuments([
            'specialization_id' => $existing['specialization_id']
        ]);

        // Support older documents that store the ID as a string.
        if ($doctor_count === 0) {
            $doctor_count = $doctorsCollection->countDocuments([
                'specialization_id' => (string)$specialization_id
            ]);
        }

        if ($doctor_count > 0) {
            flash(
                'warning',
                'This specialization cannot be deleted because ' .
                $doctor_count .
                ' doctor' .
                ($doctor_count !== 1 ? 's are' : ' is') .
                ' currently assigned to it.'
            );

            header('Location: specializations.php');
            exit;
        }

        try {
            $specializationsCollection->deleteOne([
                '_id' => $existing['_id']
            ]);

            audit(
                $admin_user_id,
                'Deleted specialization: ' . $existing['name'],
                'specialization',
                $specialization_id
            );

            flash('success', 'Specialization deleted successfully.');
        } catch (Throwable $e) {
            error_log('Delete specialization error: ' . $e->getMessage());
            flash('danger', 'Unable to delete specialization.');
        }

        header('Location: specializations.php');
        exit;
    }

    flash('danger', 'Invalid action.');
    header('Location: specializations.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_specializations = $specializationsCollection->countDocuments([]);

$used_specializations = count(
    $doctorsCollection->distinct(
        'specialization_id',
        ['specialization_id' => ['$exists' => true, '$ne' => null]]
    )
);

$unassigned_specializations = max(
    0,
    $total_specializations - $used_specializations
);

$total_doctors = $doctorsCollection->countDocuments([]);

/*
|--------------------------------------------------------------------------
| Search Filter
|--------------------------------------------------------------------------
*/

$filter = [];

if ($search !== '') {
    $regex = preg_quote($search, '/');

    $filter['$or'] = [
        ['name' => ['$regex' => $regex, '$options' => 'i']],
        ['description' => ['$regex' => $regex, '$options' => 'i']]
    ];
}

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));

$total_filtered = $specializationsCollection->countDocuments($filter);

$total_pages = max(1, (int)ceil($total_filtered / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

/*
|--------------------------------------------------------------------------
| Fetch Specializations
|--------------------------------------------------------------------------
*/

$cursor = $specializationsCollection->find(
    $filter,
    [
        'sort' => ['name' => 1],
        'skip' => $offset,
        'limit' => $per_page
    ]
);

$specializations = [];

foreach ($cursor as $document) {
    $spec = (array)$document;

    $specializationId = $spec['specialization_id'] ?? null;

    $doctor_count = 0;

    if ($specializationId !== null) {
        $doctor_count = $doctorsCollection->countDocuments([
            'specialization_id' => $specializationId
        ]);

        if ($doctor_count === 0) {
            $doctor_count = $doctorsCollection->countDocuments([
                'specialization_id' => (string)$specializationId
            ]);
        }
    }

    $specializations[] = [
        '_id' => $spec['_id'],
        'specialization_id' => (int)($spec['specialization_id'] ?? 0),
        'name' => (string)($spec['name'] ?? ''),
        'description' => $spec['description'] ?? '',
        'doctor_count' => $doctor_count
    ];
}

/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function specialization_page_url(
    int $page,
    string $search
): string {

    $query = [
        'page' => $page
    ];

    if ($search !== '') {
        $query['q'] = $search;
    }

    return 'specializations.php?' .
        http_build_query($query);
}

?>

<style>

/* ----------------------------------------------------------
   Page
---------------------------------------------------------- */

.specialization-page {
    padding-bottom: 40px;
    font-size: 1.12rem;
}

.specialization-page .btn,
.specialization-page .form-control,
.specialization-page .form-select,
.specialization-page .badge,
.specialization-page .page-link,
.specialization-page .modal-content {
    font-size: 1.04rem;
}


/* ----------------------------------------------------------
   Header
---------------------------------------------------------- */

.specialization-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 25px;
}

.specialization-eyebrow {
    display: block;
    color: #2563eb;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.4px;
    margin-bottom: 7px;
}

.specialization-header h1 {
    margin: 0;
    color: #172033;
    font-size: 42px;
    font-weight: 800;
}

.specialization-header p {
    margin: 7px 0 0;
    color: #64748b;
    font-size: 16px;
}


/* ----------------------------------------------------------
   Stats
---------------------------------------------------------- */

.spec-stat {
    height: 100%;
    padding: 19px;
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 15px;
}

.spec-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 11px;

    display: flex;
    align-items: center;
    justify-content: center;
}

.spec-stat-icon.blue {
    background: #eff6ff;
    color: #2563eb;
}

.spec-stat-icon.green {
    background: #ecfdf5;
    color: #059669;
}

.spec-stat-icon.orange {
    background: #fff7ed;
    color: #ea580c;
}

.spec-stat-icon.purple {
    background: #f5f3ff;
    color: #7c3aed;
}

.spec-stat-value {
    margin-top: 14px;
    color: #172033;
    font-size: 36px;
    font-weight: 800;
}

.spec-stat-label {
    margin-top: 4px;
    color: #64748b;
    font-size: 14px;
    font-weight: 600;
}


/* ----------------------------------------------------------
   Add Form
---------------------------------------------------------- */

.spec-form-card {
    padding: 22px;
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 16px;
}

.spec-form-title {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.spec-form-subtitle {
    margin: 4px 0 18px;
    color: #94a3b8;
    font-size: 14px;
}

.spec-label {
    display: block;
    margin-bottom: 7px;
    color: #475569;
    font-size: 14px;
    font-weight: 800;
}

.spec-control {
    min-height: 50px;
    border: 1px solid #dce3ec;
    border-radius: 10px;
    font-size: 1.05rem;
}

.spec-control:focus {
    border-color: #60a5fa;
    box-shadow:
        0 0 0 3px rgba(37, 99, 235, .08);
}


/* ----------------------------------------------------------
   Search
---------------------------------------------------------- */

.spec-search-card {
    padding: 18px;
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 16px;
}

.spec-search-control {
    min-height: 42px;
    border-radius: 10px;
}


/* ----------------------------------------------------------
   Table
---------------------------------------------------------- */

.spec-table-card {
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 17px;
    overflow: hidden;
}

.spec-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;

    padding: 19px 22px;

    border-bottom: 1px solid #edf1f5;
}

.spec-table-header h2 {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.spec-table-header p {
    margin: 4px 0 0;
    color: #94a3b8;
    font-size: 14px;
}

.spec-table {
    margin: 0;
}

.spec-table thead th {
    padding: 13px 16px;
    background: #f8fafc;
    color: #64748b;

    font-size: 13px;
    font-weight: 800;

    text-transform: uppercase;
    letter-spacing: .06em;

    border-bottom: 1px solid #e8edf3;

    white-space: nowrap;
}

.spec-table tbody td {
    padding: 15px 16px;
    border-color: #f0f3f7;
    vertical-align: middle;
    font-size: 1.08rem;
}


/* ----------------------------------------------------------
   Name
---------------------------------------------------------- */

.spec-name-wrap {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 220px;
}

.spec-icon {
    width: 40px;
    height: 40px;
    flex: 0 0 40px;

    border-radius: 11px;

    background: #eff6ff;
    color: #2563eb;

    display: flex;
    align-items: center;
    justify-content: center;
}

.spec-name {
    color: #334155;
    font-size: 18px;
    font-weight: 800;
}

.spec-id {
    margin-top: 2px;
    color: #94a3b8;
    font-size: 12px;
}


/* ----------------------------------------------------------
   Description
---------------------------------------------------------- */

.spec-description {
    max-width: 390px;
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
}


/* ----------------------------------------------------------
   Doctor Count
---------------------------------------------------------- */

.doctor-count {
    display: inline-flex;
    align-items: center;
    gap: 6px;

    padding: 8px 12px;

    background: #f1f5f9;
    color: #475569;

    border-radius: 999px;

    font-size: 13px;
    font-weight: 800;
}

.doctor-count.used {
    background: #eff6ff;
    color: #1d4ed8;
}


/* ----------------------------------------------------------
   Actions
---------------------------------------------------------- */

.spec-actions {
    white-space: nowrap;
}


/* ----------------------------------------------------------
   Empty
---------------------------------------------------------- */

.spec-empty {
    padding: 60px 20px;
    text-align: center;
}

.spec-empty-icon {
    width: 68px;
    height: 68px;

    margin: 0 auto 15px;

    border-radius: 18px;

    background: #f1f5f9;
    color: #94a3b8;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 28px;
}

.spec-empty h3 {
    margin: 0;
    color: #475569;
    font-size: 16px;
    font-weight: 800;
}

.spec-empty p {
    margin: 6px 0 0;
    color: #94a3b8;
    font-size: 12px;
}


/* ----------------------------------------------------------
   Pagination
---------------------------------------------------------- */

.spec-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;

    padding: 17px 22px;

    border-top: 1px solid #edf1f5;
}

.spec-pagination-info {
    color: #94a3b8;
    font-size: 11px;
}

.pagination {
    margin: 0;
}

.pagination .page-link {
    border-color: #e2e8f0;
    color: #475569;

    font-size: 12px;
    font-weight: 700;
}

.pagination .page-item.active .page-link {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}


/* ----------------------------------------------------------
   Responsive
---------------------------------------------------------- */

@media (max-width: 767px) {

    .specialization-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .specialization-header h1 {
        font-size: 26px;
    }

    .spec-pagination {
        flex-direction: column;
        align-items: flex-start;
    }

}

</style>


<div class="specialization-page">


    <!-- Header -->

    <div class="specialization-header">

        <div>

            <span class="specialization-eyebrow">
                <i class="bi bi-diagram-3 me-1"></i>
                ADMINISTRATION
            </span>

            <h1>
                Specialization Management
            </h1>

            <p>
                Manage medical specialties available across the platform.
            </p>

        </div>

        <a
            href="dashboard.php"
            class="btn btn-light"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Dashboard
        </a>

    </div>


    <!-- Statistics -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">

            <div class="spec-stat">

                <div class="spec-stat-icon blue">
                    <i class="bi bi-diagram-3"></i>
                </div>

                <div class="spec-stat-value">
                    <?= number_format(
                        $total_specializations
                    ) ?>
                </div>

                <div class="spec-stat-label">
                    Total Specializations
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="spec-stat">

                <div class="spec-stat-icon green">
                    <i class="bi bi-person-badge"></i>
                </div>

                <div class="spec-stat-value">
                    <?= number_format(
                        $used_specializations
                    ) ?>
                </div>

                <div class="spec-stat-label">
                    Used by Doctors
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="spec-stat">

                <div class="spec-stat-icon orange">
                    <i class="bi bi-folder-minus"></i>
                </div>

                <div class="spec-stat-value">
                    <?= number_format(
                        $unassigned_specializations
                    ) ?>
                </div>

                <div class="spec-stat-label">
                    Currently Unused
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="spec-stat">

                <div class="spec-stat-icon purple">
                    <i class="bi bi-people"></i>
                </div>

                <div class="spec-stat-value">
                    <?= number_format(
                        $total_doctors
                    ) ?>
                </div>

                <div class="spec-stat-label">
                    Total Doctors
                </div>

            </div>

        </div>

    </div>


    <!-- Add -->

    <div class="spec-form-card mb-4">

        <h2 class="spec-form-title">
            <i class="bi bi-plus-circle me-1"></i>
            Add Specialization
        </h2>

        <p class="spec-form-subtitle">
            Create a new medical specialty for doctor profiles.
        </p>


        <form
            method="post"
            class="row g-3 align-items-end"
        >

            <?= csrf_field() ?>

            <input
                type="hidden"
                name="action"
                value="add"
            >


            <div class="col-md-4">

                <label class="spec-label">
                    Specialization Name
                </label>

                <input
                    type="text"
                    name="name"
                    class="form-control spec-control"
                    maxlength="150"
                    required
                    placeholder="e.g. Cardiology"
                >

            </div>


            <div class="col-md-6">

                <label class="spec-label">
                    Description
                </label>

                <input
                    type="text"
                    name="description"
                    class="form-control spec-control"
                    maxlength="255"
                    placeholder="Short description..."
                >

            </div>


            <div class="col-md-2">

                <button
                    type="submit"
                    class="btn btn-primary w-100"
                >
                    <i class="bi bi-plus-lg me-1"></i>
                    Add
                </button>

            </div>

        </form>

    </div>


    <!-- Search -->

    <div class="spec-search-card mb-4">

        <form
            method="get"
            class="row g-2"
        >

            <div class="col-md-10">

                <input
                    type="text"
                    name="q"
                    class="form-control spec-search-control"
                    value="<?= h($search) ?>"
                    placeholder="Search specialization name or description..."
                >

            </div>


            <div class="col-md-2 d-flex gap-2">

                <button
                    type="submit"
                    class="btn btn-primary flex-grow-1"
                >
                    <i class="bi bi-search me-1"></i>
                    Search
                </button>

                <a
                    href="specializations.php"
                    class="btn btn-light"
                    title="Clear search"
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>

            </div>

        </form>

    </div>


    <!-- Table -->

    <div class="spec-table-card">

        <div class="spec-table-header">

            <div>

                <h2>
                    Medical Specializations
                </h2>

                <p>
                    Showing
                    <?= number_format(
                        $total_filtered
                    ) ?>
                    specialization<?= $total_filtered !== 1 ? 's' : '' ?>.
                </p>

            </div>

            <span class="badge text-bg-light">
                <?= number_format(
                    $total_doctors
                ) ?>
                Doctors
            </span>

        </div>


        <?php if ($specializations): ?>

            <div class="table-responsive">

                <table class="table spec-table">

                    <thead>

                        <tr>

                            <th>
                                Specialization
                            </th>

                            <th>
                                Description
                            </th>

                            <th>
                                Doctors
                            </th>

                            <th class="text-end">
                                Actions
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach (
                            $specializations as $spec
                        ): ?>

                            <tr>

                                <!-- Name -->

                                <td>

                                    <div class="spec-name-wrap">

                                        <div class="spec-icon">
                                            <i class="bi bi-heart-pulse"></i>
                                        </div>

                                        <div>

                                            <div class="spec-name">
                                                <?= h(
                                                    $spec['name']
                                                ) ?>
                                            </div>

                                            <div class="spec-id">
                                                ID #<?= (int)
                                                    $spec[
                                                        'specialization_id'
                                                    ] ?>
                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- Description -->

                                <td>

                                    <div class="spec-description">

                                        <?= h(
                                            $spec['description']
                                                ?: 'No description provided.'
                                        ) ?>

                                    </div>

                                </td>


                                <!-- Doctors -->

                                <td>

                                    <span
                                        class="doctor-count <?= (int)
                                            $spec['doctor_count'] > 0
                                            ? 'used'
                                            : ''
                                        ?>"
                                    >

                                        <i class="bi bi-person-badge"></i>

                                        <?= number_format(
                                            (int)
                                            $spec['doctor_count']
                                        ) ?>

                                        Doctor<?= (int)
                                            $spec['doctor_count'] !== 1
                                            ? 's'
                                            : ''
                                        ?>

                                    </span>

                                </td>


                                <!-- Actions -->

                                <td class="text-end spec-actions">

                                    <!-- Edit -->

                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editSpecModal"
                                        data-id="<?= (int)
                                            $spec[
                                                'specialization_id'
                                            ] ?>"
                                        data-name="<?= h(
                                            $spec['name']
                                        ) ?>"
                                        data-description="<?= h(
                                            $spec['description'] ?? ''
                                        ) ?>"
                                        onclick="fillEditSpecialization(this)"
                                    >
                                        <i class="bi bi-pencil me-1"></i>
                                        Edit
                                    </button>


                                    <!-- Delete -->

                                    <?php if (
                                        (int)
                                        $spec['doctor_count'] === 0
                                    ): ?>

                                        <form
                                            method="post"
                                            class="d-inline"
                                            onsubmit="return confirm(
                                                'Delete this specialization? This action cannot be undone.'
                                            );"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="delete"
                                            >

                                            <input
                                                type="hidden"
                                                name="specialization_id"
                                                value="<?= (int)
                                                    $spec[
                                                        'specialization_id'
                                                    ] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                            >
                                                <i class="bi bi-trash me-1"></i>
                                                Delete
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <button
                                            type="button"
                                            class="btn btn-sm btn-light"
                                            disabled
                                            title="Cannot delete a specialization currently assigned to doctors"
                                        >
                                            <i class="bi bi-lock me-1"></i>
                                            In Use
                                        </button>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <!-- Pagination -->

            <?php if ($total_pages > 1): ?>

                <div class="spec-pagination">

                    <div class="spec-pagination-info">

                        Showing

                        <?= number_format(
                            $offset + 1
                        ) ?>

                        –

                        <?= number_format(
                            min(
                                $offset + $per_page,
                                $total_filtered
                            )
                        ) ?>

                        of

                        <?= number_format(
                            $total_filtered
                        ) ?>

                        specializations

                    </div>


                    <nav>

                        <ul class="pagination pagination-sm">

                            <?php if ($page > 1): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            specialization_page_url(
                                                $page - 1,
                                                $search
                                            )
                                        ) ?>"
                                    >
                                        <i class="bi bi-chevron-left"></i>
                                    </a>

                                </li>

                            <?php endif; ?>


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
                                    class="page-item <?= $i === $page
                                        ? 'active'
                                        : ''
                                    ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            specialization_page_url(
                                                $i,
                                                $search
                                            )
                                        ) ?>"
                                    >
                                        <?= $i ?>
                                    </a>

                                </li>

                            <?php endfor; ?>


                            <?php if ($page < $total_pages): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            specialization_page_url(
                                                $page + 1,
                                                $search
                                            )
                                        ) ?>"
                                    >
                                        <i class="bi bi-chevron-right"></i>
                                    </a>

                                </li>

                            <?php endif; ?>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>


        <?php else: ?>

            <div class="spec-empty">

                <div class="spec-empty-icon">
                    <i class="bi bi-diagram-3"></i>
                </div>

                <h3>
                    No specializations found
                </h3>

                <p>
                    Add a specialization or change your search.
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>


<!-- Edit Modal -->

<div
    class="modal fade"
    id="editSpecModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content border-0 shadow">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">
                        Edit Specialization
                    </h5>

                    <small class="text-secondary">
                        Update specialization information.
                    </small>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form method="post">

                <?= csrf_field() ?>

                <input
                    type="hidden"
                    name="action"
                    value="edit"
                >

                <input
                    type="hidden"
                    name="specialization_id"
                    id="edit_specialization_id"
                >


                <div class="modal-body">

                    <div class="mb-3">

                        <label class="spec-label">
                            Specialization Name
                        </label>

                        <input
                            type="text"
                            name="name"
                            id="edit_specialization_name"
                            class="form-control spec-control"
                            maxlength="150"
                            required
                        >

                    </div>


                    <div>

                        <label class="spec-label">
                            Description
                        </label>

                        <textarea
                            name="description"
                            id="edit_specialization_description"
                            class="form-control"
                            rows="4"
                            maxlength="255"
                        ></textarea>

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        Cancel
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-check-lg me-1"></i>
                        Save Changes
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script>

function fillEditSpecialization(button) {

    const id =
        button.getAttribute('data-id');

    const name =
        button.getAttribute('data-name');

    const description =
        button.getAttribute('data-description');


    document.getElementById(
        'edit_specialization_id'
    ).value = id;


    document.getElementById(
        'edit_specialization_name'
    ).value = name;


    document.getElementById(
        'edit_specialization_description'
    ).value = description;
}

</script>


<?php require '../includes/footer.php'; ?>