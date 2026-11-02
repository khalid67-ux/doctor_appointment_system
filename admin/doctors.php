<?php

$page_title = 'Doctor Management';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$admin_user_id = (int) $_SESSION['user_id'];

$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');
$specializationsCollection = $mongoDb->selectCollection('specializations');


/*
|--------------------------------------------------------------------------
| Allowed Filters
|--------------------------------------------------------------------------
*/

$allowed_verifications = [
    'Pending',
    'Approved',
    'Rejected'
];

$allowed_account_statuses = [
    'active',
    'inactive'
];

$search = trim($_GET['q'] ?? '');
$verification_filter = trim($_GET['verification'] ?? '');
$account_status_filter = trim($_GET['account_status'] ?? '');

if (
    $verification_filter !== '' &&
    !in_array($verification_filter, $allowed_verifications, true)
) {
    $verification_filter = '';
}

if (
    $account_status_filter !== '' &&
    !in_array($account_status_filter, $allowed_account_statuses, true)
) {
    $account_status_filter = '';
}


/*
|--------------------------------------------------------------------------
| Doctor Actions
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['action'], $_POST['doctor_id'])
) {

    verify_csrf();

    $doctor_id = (int) $_POST['doctor_id'];
    $action = trim($_POST['action']);


    $doctor = $doctorsCollection->findOne([
        'doctor_id' => $doctor_id
    ]);

    if (!$doctor) {
        flash('danger', 'Doctor account not found.');
        header('Location: doctors.php');
        exit;
    }

    $user_id = (int) ($doctor['user_id'] ?? 0);

    $user = $usersCollection->findOne([
        'user_id' => $user_id
    ]);

    if (!$user) {
        flash('danger', 'Linked user account not found.');
        header('Location: doctors.php');
        exit;
    }

    if (in_array($action, ['approve', 'reject'], true)) {
        $verification_status = $action === 'approve'
            ? 'Approved'
            : 'Rejected';
        $account_status = $action === 'approve'
            ? 'active'
            : 'inactive';

        try {
            $doctorUpdate = $doctorsCollection->updateOne(
                ['doctor_id' => $doctor_id],
                ['$set' => [
                    'verification_status' => $verification_status
                ]]
            );

            $userUpdate = $usersCollection->updateOne(
                ['user_id' => $user_id],
                ['$set' => [
                    'status' => $account_status
                ]]
            );

            if (
                $doctorUpdate->getMatchedCount() !== 1 ||
                $userUpdate->getMatchedCount() !== 1
            ) {
                throw new RuntimeException(
                    'Doctor verification records could not be updated.'
                );
            }

            create_notification(
                $user_id,
                'verification',
                'Doctor Verification Updated',
                "Your doctor verification status is now {$verification_status}."
            );

            audit(
                $admin_user_id,
                ucfirst($action) . ' doctor',
                'doctor',
                $doctor_id
            );

            flash('success', "Doctor {$verification_status} successfully.");
        } catch (Throwable $e) {
            error_log('Doctor verification error: ' . $e->getMessage());
            flash('danger', 'Unable to update doctor verification.');
        }

        header('Location: doctors.php');
        exit;
    }

    if (in_array($action, ['activate', 'deactivate'], true)) {
        $verification_status = $doctor['verification_status'] ?? '';
        if (
            $action === 'activate' &&
            $verification_status !== 'Approved'
        ) {
            flash('warning', 'Only approved doctors can be activated.');
            header('Location: doctors.php');
            exit;
        }

        $new_status = $action === 'activate'
            ? 'active'
            : 'inactive';
        try {
            $userUpdate = $usersCollection->updateOne(
                ['user_id' => $user_id],
                ['$set' => [
                    'status' => $new_status
                ]]
            );

            if ($userUpdate->getMatchedCount() !== 1) {
                throw new RuntimeException('Doctor account could not be updated.');
            }

            create_notification(
                $user_id,
                'account',
                'Account Status Updated',
                'Your doctor account is now ' . ucfirst($new_status) . '.'
            );

            audit(
                $admin_user_id,
                ucfirst($action) . ' doctor account',
                'doctor',
                $doctor_id
            );

            flash('success', 'Doctor account status updated.');
        } catch (Throwable $e) {
            error_log('Doctor account update error: ' . $e->getMessage());
            flash('danger', 'Unable to update doctor account.');
        }

        header('Location: doctors.php');
        exit;
    }

    flash('danger', 'Invalid doctor action.');
    header('Location: doctors.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_doctors = $doctorsCollection->countDocuments([]);

$pending_doctors = $doctorsCollection->countDocuments([
    'verification_status' => 'Pending'
]);

$approved_doctors = $doctorsCollection->countDocuments([
    'verification_status' => 'Approved'
]);

$rejected_doctors = $doctorsCollection->countDocuments([
    'verification_status' => 'Rejected'
]);

$active_doctors = 0;

foreach ($usersCollection->find(
    ['status' => 'active'],
    ['projection' => ['user_id' => 1]]
) as $activeUser) {
    $activeUserId = (int) ($activeUser['user_id'] ?? 0);

    if (
        $activeUserId > 0 &&
        $doctorsCollection->countDocuments([
            'user_id' => $activeUserId
        ]) > 0
    ) {
        $active_doctors++;
    }
}

/*
|--------------------------------------------------------------------------
| Fetch Doctors and Related User/Specialization Documents
|--------------------------------------------------------------------------
*/

$allDoctors = [];

$doctorCursor = $doctorsCollection->find([]);

foreach ($doctorCursor as $doctorDocument) {
    $d = $doctorDocument->getArrayCopy();

    $user = $usersCollection->findOne([
        'user_id' => (int) ($d['user_id'] ?? 0)
    ]);

    if (!$user) {
        continue;
    }

    $specialization = null;
    $specializationId = $d['specialization_id'] ?? null;

    if ($specializationId !== null && $specializationId !== '') {
        $specialization = $specializationsCollection->findOne([
            'specialization_id' => (int) $specializationId
        ]);
    }

    $allDoctors[] = [
        'doctor_id' => (int) ($d['doctor_id'] ?? 0),
        'user_id' => (int) ($d['user_id'] ?? 0),
        'specialization_id' => $specializationId,
        'verification_status' => $d['verification_status'] ?? 'Pending',
        'qualification' => $d['qualification'] ?? '',
        'experience' => $d['experience'] ?? '',
        'consultation_fee' => $d['consultation_fee'] ?? 0,
        'name' => $user['name'] ?? '',
        'email' => $user['email'] ?? '',
        'user_status' => $user['status'] ?? 'inactive',
        'created_at' => $user['created_at'] ?? '',
        'specialization' => $specialization['name'] ?? ''
    ];
}

/*
|--------------------------------------------------------------------------
| Search and Filters
|--------------------------------------------------------------------------
*/

$filteredDoctors = [];

foreach ($allDoctors as $doctor) {
    if (
        $verification_filter !== '' &&
        $doctor['verification_status'] !== $verification_filter
    ) {
        continue;
    }

    if (
        $account_status_filter !== '' &&
        $doctor['user_status'] !== $account_status_filter
    ) {
        continue;
    }

    if ($search !== '') {
        $searchableText = [
            $doctor['name'],
            $doctor['email'],
            $doctor['qualification']
        ];

        $matches = false;

        foreach ($searchableText as $value) {
            if (stripos((string) $value, $search) !== false) {
                $matches = true;
                break;
            }
        }

        if (!$matches) {
            continue;
        }
    }

    $filteredDoctors[] = $doctor;
}

/*
|--------------------------------------------------------------------------
| Sort Doctors
|--------------------------------------------------------------------------
*/

$verificationPriority = [
    'Pending' => 1,
    'Approved' => 2,
    'Rejected' => 3
];

usort($filteredDoctors, function ($a, $b) use ($verificationPriority) {
    $priorityA = $verificationPriority[$a['verification_status']] ?? 4;
    $priorityB = $verificationPriority[$b['verification_status']] ?? 4;

    if ($priorityA !== $priorityB) {
        return $priorityA <=> $priorityB;
    }

    return $b['doctor_id'] <=> $a['doctor_id'];
});

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$per_page = 15;
$page = max(1, (int) ($_GET['page'] ?? 1));

$total_filtered = count($filteredDoctors);
$total_pages = max(1, (int) ceil($total_filtered / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

$doctors = array_slice(
    $filteredDoctors,
    $offset,
    $per_page
);


/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function doctor_page_url(
    int $page,
    string $search,
    string $verification,
    string $account_status
): string {

    $query = [
        'page' => $page
    ];

    if ($search !== '') {
        $query['q'] = $search;
    }

    if ($verification !== '') {
        $query['verification'] = $verification;
    }

    if ($account_status !== '') {
        $query['account_status'] = $account_status;
    }

    return 'doctors.php?' .
        http_build_query($query);
}


/*
|--------------------------------------------------------------------------
| UI Helpers
|--------------------------------------------------------------------------
*/

function verification_class(string $status): string
{
    return match ($status) {

        'Pending' => 'verification-pending',

        'Approved' => 'verification-approved',

        'Rejected' => 'verification-rejected',

        default => 'verification-default'
    };
}


function verification_icon(string $status): string
{
    return match ($status) {

        'Pending' => 'bi-hourglass-split',

        'Approved' => 'bi-patch-check',

        'Rejected' => 'bi-x-circle',

        default => 'bi-question-circle'
    };
}


function doctor_initials(string $name): string
{
    $parts = preg_split(
        '/\s+/',
        trim($name)
    );

    $initials = '';

    if (!empty($parts[0])) {

        $initials .= strtoupper(
            substr(
                $parts[0],
                0,
                1
            )
        );
    }

    if (
        count($parts) > 1 &&
        !empty($parts[count($parts) - 1])
    ) {

        $initials .= strtoupper(
            substr(
                $parts[count($parts) - 1],
                0,
                1
            )
        );
    }

    return $initials ?: 'D';
}

require '../includes/header.php';

?>

<style>

/* ----------------------------------------------------------
   Page
---------------------------------------------------------- */

.doctor-page {
    padding-bottom: 40px;
    font-size: 1.12rem;
}

.doctor-page .btn,
.doctor-page .form-control,
.doctor-page .form-select,
.doctor-page .badge,
.doctor-page .page-link {
    font-size: 1.04rem;
}


/* ----------------------------------------------------------
   Header
---------------------------------------------------------- */

.doctor-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 25px;
}

.doctor-eyebrow {
    display: block;
    color: #2563eb;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.4px;
    margin-bottom: 7px;
}

.doctor-header h1 {
    margin: 0;
    color: #172033;
    font-size: 42px;
    font-weight: 800;
}

.doctor-header p {
    margin: 7px 0 0;
    color: #64748b;
    font-size: 16px;
}


/* ----------------------------------------------------------
   Stats
---------------------------------------------------------- */

.doctor-stat {
    height: 100%;
    padding: 19px;
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 15px;
}

.doctor-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 11px;

    display: flex;
    align-items: center;
    justify-content: center;
}

.doctor-stat-icon.blue {
    background: #eff6ff;
    color: #2563eb;
}

.doctor-stat-icon.orange {
    background: #fff7ed;
    color: #ea580c;
}

.doctor-stat-icon.green {
    background: #ecfdf5;
    color: #059669;
}

.doctor-stat-icon.red {
    background: #fef2f2;
    color: #dc2626;
}

.doctor-stat-value {
    margin-top: 14px;
    color: #172033;
    font-size: 36px;
    font-weight: 800;
}

.doctor-stat-label {
    margin-top: 4px;
    color: #64748b;
    font-size: 14px;
    font-weight: 600;
}


/* ----------------------------------------------------------
   Filter
---------------------------------------------------------- */

.doctor-filter {
    padding: 20px;
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 16px;
}

.filter-label {
    display: block;
    color: #475569;
    font-size: 14px;
    font-weight: 800;
    margin-bottom: 7px;
}

.filter-control {
    min-height: 50px;
    border: 1px solid #dce3ec;
    border-radius: 10px;
    font-size: 1.05rem;
}

.filter-control:focus {
    border-color: #60a5fa;
    box-shadow:
        0 0 0 3px rgba(37, 99, 235, .08);
}


/* ----------------------------------------------------------
   Table
---------------------------------------------------------- */

.doctor-table-card {
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 17px;
    overflow: hidden;
}

.doctor-table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;

    padding: 19px 22px;

    border-bottom: 1px solid #edf1f5;
}

.doctor-table-header h2 {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.doctor-table-header p {
    margin: 4px 0 0;
    color: #94a3b8;
    font-size: 14px;
}

.doctor-table {
    margin: 0;
}

.doctor-table thead th {
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

.doctor-table tbody td {
    padding: 15px 16px;
    border-color: #f0f3f7;
    vertical-align: middle;
    font-size: 1.08rem;
}


/* ----------------------------------------------------------
   Doctor Identity
---------------------------------------------------------- */

.doctor-info {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 220px;
}

.doctor-avatar {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;

    border-radius: 12px;

    background: #eff6ff;
    color: #2563eb;

    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 12px;
    font-weight: 800;
}

.doctor-name {
    color: #334155;
    font-size: 18px;
    font-weight: 800;
}

.doctor-email {
    margin-top: 2px;
    color: #94a3b8;
    font-size: 13px;
}

.doctor-id {
    margin-top: 2px;
    color: #cbd5e1;
    font-size: 12px;
}


/* ----------------------------------------------------------
   Verification
---------------------------------------------------------- */

.verification-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;

    padding: 8px 12px;

    border-radius: 999px;

    font-size: 13px;
    font-weight: 800;

    white-space: nowrap;
}

.verification-pending {
    background: #fff7ed;
    color: #c2410c;
}

.verification-approved {
    background: #ecfdf5;
    color: #047857;
}

.verification-rejected {
    background: #fef2f2;
    color: #b91c1c;
}

.verification-default {
    background: #f1f5f9;
    color: #475569;
}


/* ----------------------------------------------------------
   Account
---------------------------------------------------------- */

.account-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;

    padding: 7px 11px;

    border-radius: 999px;

    font-size: 13px;
    font-weight: 800;
}

.account-active {
    background: #ecfdf5;
    color: #047857;
}

.account-inactive {
    background: #fef2f2;
    color: #b91c1c;
}


/* ----------------------------------------------------------
   Information
---------------------------------------------------------- */

.doctor-specialization {
    color: #475569;
    font-size: 15px;
    font-weight: 700;
}

.doctor-qualification {
    color: #64748b;
    font-size: 14px;
    max-width: 220px;
}

.doctor-experience {
    color: #64748b;
    font-size: 14px;
}

.doctor-fee {
    color: #334155;
    font-size: 15px;
    font-weight: 800;
}


/* ----------------------------------------------------------
   Empty
---------------------------------------------------------- */

.doctor-empty {
    padding: 60px 20px;
    text-align: center;
}

.doctor-empty-icon {
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

.doctor-empty h3 {
    margin: 0;
    color: #475569;
    font-size: 16px;
    font-weight: 800;
}

.doctor-empty p {
    margin: 6px 0 0;
    color: #94a3b8;
    font-size: 12px;
}


/* ----------------------------------------------------------
   Pagination
---------------------------------------------------------- */

.doctor-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;

    padding: 17px 22px;

    border-top: 1px solid #edf1f5;
}

.pagination-info {
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

    .doctor-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .doctor-header h1 {
        font-size: 26px;
    }

    .doctor-pagination {
        flex-direction: column;
        align-items: flex-start;
    }

}


</style>


<div class="doctor-page">


    <!-- Header -->

    <div class="doctor-header">

        <div>

            <span class="doctor-eyebrow">
                <i class="bi bi-patch-check me-1"></i>
                ADMINISTRATION
            </span>

            <h1>
                Doctor Management
            </h1>

            <p>
                Review doctor applications and manage professional accounts.
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

            <div class="doctor-stat">

                <div class="doctor-stat-icon blue">
                    <i class="bi bi-person-badge"></i>
                </div>

                <div class="doctor-stat-value">
                    <?= number_format($total_doctors) ?>
                </div>

                <div class="doctor-stat-label">
                    Total Doctors
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="doctor-stat">

                <div class="doctor-stat-icon orange">
                    <i class="bi bi-hourglass-split"></i>
                </div>

                <div class="doctor-stat-value">
                    <?= number_format($pending_doctors) ?>
                </div>

                <div class="doctor-stat-label">
                    Pending Verification
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="doctor-stat">

                <div class="doctor-stat-icon green">
                    <i class="bi bi-patch-check"></i>
                </div>

                <div class="doctor-stat-value">
                    <?= number_format($approved_doctors) ?>
                </div>

                <div class="doctor-stat-label">
                    Approved Doctors
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="doctor-stat">

                <div class="doctor-stat-icon red">
                    <i class="bi bi-x-circle"></i>
                </div>

                <div class="doctor-stat-value">
                    <?= number_format($rejected_doctors) ?>
                </div>

                <div class="doctor-stat-label">
                    Rejected Applications
                </div>

            </div>

        </div>

    </div>


    <!-- Filters -->

    <div class="doctor-filter mb-4">

        <form
            method="get"
            class="row g-3 align-items-end"
        >

            <div class="col-lg-5">

                <label class="filter-label">
                    Search Doctor
                </label>

                <input
                    type="text"
                    name="q"
                    class="form-control filter-control"
                    value="<?= h($search) ?>"
                    placeholder="Name, email or qualification..."
                >

            </div>


            <div class="col-md-4 col-lg-3">

                <label class="filter-label">
                    Verification
                </label>

                <select
                    name="verification"
                    class="form-select filter-control"
                >

                    <option value="">
                        All Verification
                    </option>

                    <?php foreach ($allowed_verifications as $status): ?>

                        <option
                            value="<?= h($status) ?>"
                            <?= $verification_filter === $status
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= h($status) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-4 col-lg-2">

                <label class="filter-label">
                    Account
                </label>

                <select
                    name="account_status"
                    class="form-select filter-control"
                >

                    <option value="">
                        All Accounts
                    </option>

                    <?php foreach ($allowed_account_statuses as $status): ?>

                        <option
                            value="<?= h($status) ?>"
                            <?= $account_status_filter === $status
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= h(ucfirst($status)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-4 col-lg-2 d-flex gap-2">

                <button
                    type="submit"
                    class="btn btn-primary flex-grow-1"
                >
                    <i class="bi bi-search me-1"></i>
                    Search
                </button>

                <a
                    href="doctors.php"
                    class="btn btn-light"
                    title="Clear filters"
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>

            </div>

        </form>

    </div>


    <!-- Doctor Table -->

    <div class="doctor-table-card">

        <div class="doctor-table-header">

            <div>

                <h2>
                    Doctor Applications
                </h2>

                <p>
                    Showing
                    <?= number_format($total_filtered) ?>
                    matching doctor<?= $total_filtered !== 1 ? 's' : '' ?>.
                </p>

            </div>

            <span class="badge text-bg-light">
                Active: <?= number_format($active_doctors) ?>
            </span>

        </div>


        <?php if ($doctors): ?>

            <div class="table-responsive">

                <table class="table doctor-table">

                    <thead>

                        <tr>

                            <th>
                                Doctor
                            </th>

                            <th>
                                Specialization
                            </th>

                            <th>
                                Qualification
                            </th>

                            <th>
                                Verification
                            </th>

                            <th>
                                Account
                            </th>

                            <th>
                                Experience
                            </th>

                            <th class="text-end">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($doctors as $d): ?>

                            <tr>

                                <!-- Doctor -->

                                <td>

                                    <div class="doctor-info">

                                        <div class="doctor-avatar">
                                            <?= h(
                                                doctor_initials(
                                                    $d['name']
                                                )
                                            ) ?>
                                        </div>

                                        <div>

                                            <div class="doctor-name">
                                                Dr. <?= h($d['name']) ?>
                                            </div>

                                            <div class="doctor-email">
                                                <?= h($d['email']) ?>
                                            </div>

                                            <div class="doctor-id">
                                                Doctor #<?= (int)$d['doctor_id'] ?>
                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- Specialization -->

                                <td>

                                    <span class="doctor-specialization">

                                        <?= h(
                                            $d['specialization']
                                                ?: 'Not set'
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Qualification -->

                                <td>

                                    <span class="doctor-qualification">

                                        <?= h(
                                            $d['qualification']
                                                ?: 'Not provided'
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Verification -->

                                <td>

                                    <span
                                        class="verification-pill <?= h(
                                            verification_class(
                                                $d['verification_status']
                                            )
                                        ) ?>"
                                    >

                                        <i class="bi <?= h(
                                            verification_icon(
                                                $d['verification_status']
                                            )
                                        ) ?>"></i>

                                        <?= h(
                                            $d['verification_status']
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Account -->

                                <td>

                                    <span
                                        class="account-pill <?= $d['user_status'] === 'active'
                                            ? 'account-active'
                                            : 'account-inactive'
                                        ?>"
                                    >

                                        <i class="bi <?= $d['user_status'] === 'active'
                                            ? 'bi-check-circle'
                                            : 'bi-x-circle'
                                        ?>"></i>

                                        <?= h(
                                            ucfirst(
                                                $d['user_status']
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Experience -->

                                <td>

                                    <span class="doctor-experience">

                                        <?= $d['experience'] !== null &&
                                            $d['experience'] !== ''
                                            ? h($d['experience']) . ' years'
                                            : '—'
                                        ?>

                                    </span>

                                </td>


                                <!-- Action -->

                                <td class="text-end">

                                    <?php if (
                                        $d['verification_status']
                                            === 'Pending'
                                    ): ?>

                                        <div class="d-flex justify-content-end gap-1">

                                            <!-- Approve -->

                                            <form
                                                method="post"
                                                onsubmit="return confirm(
                                                    'Approve this doctor?'
                                                );"
                                            >

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="doctor_id"
                                                    value="<?= (int)$d['doctor_id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="approve"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-success"
                                                    title="Approve doctor"
                                                >
                                                    <i class="bi bi-check-lg"></i>
                                                    Approve
                                                </button>

                                            </form>


                                            <!-- Reject -->

                                            <form
                                                method="post"
                                                onsubmit="return confirm(
                                                    'Reject this doctor application?'
                                                );"
                                            >

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="doctor_id"
                                                    value="<?= (int)$d['doctor_id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="reject"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                    title="Reject doctor"
                                                >
                                                    <i class="bi bi-x-lg"></i>
                                                    Reject
                                                </button>

                                            </form>

                                        </div>


                                    <?php elseif (
                                        $d['verification_status']
                                            === 'Approved'
                                    ): ?>

                                        <?php if (
                                            $d['user_status'] === 'active'
                                        ): ?>

                                            <form
                                                method="post"
                                                class="d-inline"
                                                onsubmit="return confirm(
                                                    'Deactivate this doctor account?'
                                                );"
                                            >

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="doctor_id"
                                                    value="<?= (int)$d['doctor_id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="deactivate"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger"
                                                >
                                                    <i class="bi bi-person-x me-1"></i>
                                                    Deactivate
                                                </button>

                                            </form>

                                        <?php else: ?>

                                            <form
                                                method="post"
                                                class="d-inline"
                                                onsubmit="return confirm(
                                                    'Activate this doctor account?'
                                                );"
                                            >

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="doctor_id"
                                                    value="<?= (int)$d['doctor_id'] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="activate"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-success"
                                                >
                                                    <i class="bi bi-person-check me-1"></i>
                                                    Activate
                                                </button>

                                            </form>

                                        <?php endif; ?>


                                    <?php else: ?>

                                        <span class="text-secondary small">
                                            Rejected
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>


            <!-- Pagination -->

            <?php if ($total_pages > 1): ?>

                <div class="doctor-pagination">

                    <div class="pagination-info">

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

                        doctors

                    </div>


                    <nav>

                        <ul class="pagination pagination-sm">

                            <?php if ($page > 1): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            doctor_page_url(
                                                $page - 1,
                                                $search,
                                                $verification_filter,
                                                $account_status_filter
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
                                            doctor_page_url(
                                                $i,
                                                $search,
                                                $verification_filter,
                                                $account_status_filter
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
                                            doctor_page_url(
                                                $page + 1,
                                                $search,
                                                $verification_filter,
                                                $account_status_filter
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

            <div class="doctor-empty">

                <div class="doctor-empty-icon">
                    <i class="bi bi-person-badge"></i>
                </div>

                <h3>
                    No doctors found
                </h3>

                <p>
                    No doctor matches the current search or filter.
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>


<?php require '../includes/footer.php'; ?>