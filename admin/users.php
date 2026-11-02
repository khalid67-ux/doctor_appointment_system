<?php

$page_title = 'User Management';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require '../includes/header.php';
require_role('admin');

$admin_user_id = (int) $_SESSION['user_id'];
$usersCollection = $mongoDb->selectCollection('users');

$search = trim($_GET['q'] ?? '');
$role_filter = trim($_GET['role'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$allowed_roles = ['patient', 'doctor', 'admin'];
$allowed_statuses = ['active', 'inactive'];

if (!in_array($role_filter, $allowed_roles, true)) {
    $role_filter = '';
}

if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = '';
}

/*
|--------------------------------------------------------------------------
| Toggle User Status
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'toggle'
) {
    verify_csrf();

    $user_id = (int) ($_POST['user_id'] ?? 0);

    if ($user_id <= 0) {
        flash('danger', 'Invalid user selected.');
        header('Location: users.php');
        exit;
    }

    if ($user_id === $admin_user_id) {
        flash('danger', 'You cannot deactivate your own administrator account.');
        header('Location: users.php');
        exit;
    }

    $user = $usersCollection->findOne([
        'user_id' => $user_id
    ]);

    if (!$user) {
        flash('danger', 'User account not found.');
        header('Location: users.php');
        exit;
    }

    if (($user->role ?? '') === 'admin') {
        flash('danger', 'Administrator accounts cannot be changed from this page.');
        header('Location: users.php');
        exit;
    }

    $current_status = $user->status ?? 'inactive';
    $new_status = $current_status === 'active' ? 'inactive' : 'active';

    try {
        $result = $usersCollection->updateOne(
            [
                'user_id' => $user_id,
                'role' => ['$ne' => 'admin'],
                'status' => $current_status
            ],
            [
                '$set' => ['status' => $new_status]
            ]
        );

        if ($result->getModifiedCount() > 0) {
            if (function_exists('audit')) {
                audit(
                    $admin_user_id,
                    ucfirst($new_status) . ' user account',
                    'user',
                    $user_id
                );
            }

            flash(
                'success',
                'User status changed to ' . ucfirst($new_status) . '.'
            );
        } else {
            flash('danger', 'Unable to update the user status. Please try again.');
        }
    } catch (Throwable $e) {
        error_log('User status update error: ' . $e->getMessage());
        flash('danger', 'Unable to update the user status.');
    }

    header('Location: users.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_users = $usersCollection->countDocuments([]);

$active_users = $usersCollection->countDocuments([
    'status' => 'active'
]);

$inactive_users = $usersCollection->countDocuments([
    'status' => 'inactive'
]);

$patient_users = $usersCollection->countDocuments([
    'role' => 'patient'
]);

$doctor_users = $usersCollection->countDocuments([
    'role' => 'doctor'
]);

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

$per_page = 20;
$page = max(1, (int) ($_GET['page'] ?? 1));

/*
|--------------------------------------------------------------------------
| Build MongoDB Filter
|--------------------------------------------------------------------------
*/

$filter = [];

if ($search !== '') {
    $escapedSearch = preg_quote($search, '/');

    $filter['$or'] = [
        ['name' => ['$regex' => $escapedSearch, '$options' => 'i']],
        ['email' => ['$regex' => $escapedSearch, '$options' => 'i']]
    ];
}

if ($role_filter !== '') {
    $filter['role'] = $role_filter;
}

if ($status_filter !== '') {
    $filter['status'] = $status_filter;
}

/*
|--------------------------------------------------------------------------
| Count Filtered Users
|--------------------------------------------------------------------------
*/

$total_filtered = $usersCollection->countDocuments($filter);

$total_pages = max(
    1,
    (int) ceil($total_filtered / $per_page)
);

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

/*
|--------------------------------------------------------------------------
| Get Users
|--------------------------------------------------------------------------
*/

$cursor = $usersCollection->find(
    $filter,
    [
        'sort' => ['created_at' => -1],
        'skip' => $offset,
        'limit' => $per_page
    ]
);

$users = [];

foreach ($cursor as $document) {
    $u = (array) $document;

    if (!isset($u['user_id']) && isset($u['_id'])) {
        $u['user_id'] = (string) $u['_id'];
    }

    if (
        isset($u['created_at']) &&
        $u['created_at'] instanceof MongoDB\BSON\UTCDateTime
    ) {
        $u['created_at'] = $u['created_at']->toDateTime()->format('Y-m-d H:i:s');
    }

    $u['name'] = (string) ($u['name'] ?? '');
    $u['email'] = (string) ($u['email'] ?? '');
    $u['role'] = (string) ($u['role'] ?? '');
    $u['status'] = (string) ($u['status'] ?? 'inactive');
    $u['created_at'] = (string) ($u['created_at'] ?? '');

    $users[] = $u;
}


/*
|--------------------------------------------------------------------------
| Pagination URL
|--------------------------------------------------------------------------
*/

function users_page_url(
    int $page,
    string $search,
    string $role,
    string $status
): string {

    $query = [
        'page' => $page
    ];

    if ($search !== '') {
        $query['q'] = $search;
    }

    if ($role !== '') {
        $query['role'] = $role;
    }

    if ($status !== '') {
        $query['status'] = $status;
    }

    return 'users.php?' .
        http_build_query($query);
}


/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function user_role_class(string $role): string
{
    return match ($role) {

        'admin' => 'role-admin',

        'doctor' => 'role-doctor',

        'patient' => 'role-patient',

        default => 'role-default'
    };
}


function user_role_icon(string $role): string
{
    return match ($role) {

        'admin' => 'bi-shield-lock',

        'doctor' => 'bi-person-badge',

        'patient' => 'bi-person-heart',

        default => 'bi-person'
    };
}


function user_initials(string $name): string
{
    $parts = preg_split(
        '/\s+/',
        trim($name)
    );

    $initials = '';

    if (!empty($parts[0])) {

        $initials .= strtoupper(
            substr($parts[0], 0, 1)
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

    return $initials ?: 'U';
}

?>

<style>

.user-page {
    padding-bottom: 40px;
    font-size: 1.12rem;
}

.user-page .btn,
.user-page .form-control,
.user-page .form-select,
.user-page .badge,
.user-page .page-link {
    font-size: 1.04rem;
}


/* Header */

.user-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 20px;
    margin-bottom: 25px;
}

.user-eyebrow {
    display: block;
    color: #2563eb;
    font-size: 11px;
    font-weight: 800;
    letter-spacing: 1.4px;
    margin-bottom: 7px;
}

.user-header h1 {
    margin: 0;
    color: #172033;
    font-size: 42px;
    font-weight: 800;
}

.user-header p {
    margin: 7px 0 0;
    color: #64748b;
    font-size: 16px;
}

.user-back {
    white-space: nowrap;
    border: 1px solid #e2e8f0;
    font-weight: 700;
}


/* Stats */

.user-stat {
    height: 100%;
    padding: 19px;
    background: #fff;
    border: 1px solid #e7edf4;
    border-radius: 15px;
}

.user-stat-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.user-stat-icon {
    width: 40px;
    height: 40px;
    border-radius: 11px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.user-stat-icon.blue {
    background: #eff6ff;
    color: #2563eb;
}

.user-stat-icon.green {
    background: #ecfdf5;
    color: #059669;
}

.user-stat-icon.red {
    background: #fef2f2;
    color: #dc2626;
}

.user-stat-icon.purple {
    background: #f5f3ff;
    color: #7c3aed;
}

.user-stat-value {
    margin-top: 14px;
    color: #172033;
    font-size: 36px;
    font-weight: 800;
}

.user-stat-label {
    margin-top: 4px;
    color: #64748b;
    font-size: 14px;
    font-weight: 600;
}


/* Filter */

.filter-card {
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
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .08);
}


/* Table */

.user-table-card {
    border: 1px solid #e7edf4;
    border-radius: 17px;
    background: #fff;
    overflow: hidden;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    padding: 19px 22px;
    border-bottom: 1px solid #edf1f5;
}

.table-header h2 {
    margin: 0;
    color: #172033;
    font-size: 22px;
    font-weight: 800;
}

.table-header p {
    margin: 4px 0 0;
    color: #94a3b8;
    font-size: 14px;
}

.user-table {
    margin: 0;
}

.user-table thead th {
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

.user-table tbody td {
    padding: 15px 16px;
    border-color: #f0f3f7;
    vertical-align: middle;
    font-size: 1.08rem;
}

.user-info {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 180px;
}

.user-avatar {
    width: 39px;
    height: 39px;
    flex: 0 0 39px;
    border-radius: 11px;
    background: #eff6ff;
    color: #2563eb;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: 800;
}

.user-name {
    color: #334155;
    font-size: 18px;
    font-weight: 750;
}

.user-id {
    margin-top: 2px;
    color: #94a3b8;
    font-size: 12px;
}

.user-email {
    color: #64748b;
    font-size: 15px;
}


/* Role */

.role-badge,
.account-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 8px 12px;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 800;
    white-space: nowrap;
}

.role-admin {
    background: #f5f3ff;
    color: #6d28d9;
}

.role-doctor {
    background: #eff6ff;
    color: #1d4ed8;
}

.role-patient {
    background: #ecfdf5;
    color: #047857;
}

.role-default {
    background: #f1f5f9;
    color: #475569;
}

.account-active {
    background: #ecfdf5;
    color: #047857;
}

.account-inactive {
    background: #fef2f2;
    color: #b91c1c;
}


/* Date */

.created-date {
    color: #64748b;
    font-size: 14px;
}


/* Actions */

.action-btn {
    min-width: 95px;
}


/* Empty */

.user-empty {
    padding: 55px 20px;
    text-align: center;
}

.user-empty-icon {
    width: 65px;
    height: 65px;
    margin: 0 auto 15px;
    border-radius: 18px;
    background: #f1f5f9;
    color: #94a3b8;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 27px;
}

.user-empty h3 {
    margin: 0;
    color: #475569;
    font-size: 16px;
    font-weight: 800;
}

.user-empty p {
    margin: 6px 0 0;
    color: #94a3b8;
    font-size: 12px;
}


/* Pagination */

.pagination-wrap {
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
    border: 1px solid #e2e8f0;
    color: #475569;
    font-size: 12px;
    font-weight: 700;
}

.pagination .page-item.active .page-link {
    background: #2563eb;
    border-color: #2563eb;
    color: #fff;
}


/* Responsive */

@media (max-width: 767px) {

    .user-header {
        flex-direction: column;
        align-items: flex-start;
    }

    .user-header h1 {
        font-size: 26px;
    }

    .pagination-wrap {
        flex-direction: column;
        align-items: flex-start;
    }

}

</style>


<div class="user-page">

    <!-- Header -->

    <div class="user-header">

        <div>

            <span class="user-eyebrow">
                <i class="bi bi-people me-1"></i>
                ADMINISTRATION
            </span>

            <h1>
                User Management
            </h1>

            <p>
                Manage patient, doctor and administrator accounts.
            </p>

        </div>

        <a
            href="dashboard.php"
            class="btn btn-light user-back"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Dashboard
        </a>

    </div>


    <!-- Statistics -->

    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">

            <div class="user-stat">

                <div class="user-stat-top">

                    <div class="user-stat-icon blue">
                        <i class="bi bi-people"></i>
                    </div>

                </div>

                <div class="user-stat-value">
                    <?= number_format($total_users) ?>
                </div>

                <div class="user-stat-label">
                    Total Users
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="user-stat">

                <div class="user-stat-top">

                    <div class="user-stat-icon green">
                        <i class="bi bi-person-check"></i>
                    </div>

                </div>

                <div class="user-stat-value">
                    <?= number_format($active_users) ?>
                </div>

                <div class="user-stat-label">
                    Active Accounts
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="user-stat">

                <div class="user-stat-top">

                    <div class="user-stat-icon red">
                        <i class="bi bi-person-x"></i>
                    </div>

                </div>

                <div class="user-stat-value">
                    <?= number_format($inactive_users) ?>
                </div>

                <div class="user-stat-label">
                    Inactive Accounts
                </div>

            </div>

        </div>


        <div class="col-6 col-md-3">

            <div class="user-stat">

                <div class="user-stat-top">

                    <div class="user-stat-icon purple">
                        <i class="bi bi-person-badge"></i>
                    </div>

                </div>

                <div class="user-stat-value">
                    <?= number_format($doctor_users) ?>
                </div>

                <div class="user-stat-label">
                    Doctor Accounts
                </div>

            </div>

        </div>

    </div>


    <!-- Filters -->

    <div class="filter-card mb-4">

        <form
            method="get"
            class="row g-3 align-items-end"
        >

            <div class="col-lg-5">

                <label class="filter-label">
                    Search User
                </label>

                <input
                    type="text"
                    name="q"
                    class="form-control filter-control"
                    value="<?= h($search) ?>"
                    placeholder="Search by name or email..."
                >

            </div>


            <div class="col-md-4 col-lg-2">

                <label class="filter-label">
                    Role
                </label>

                <select
                    name="role"
                    class="form-select filter-control"
                >

                    <option value="">
                        All Roles
                    </option>

                    <?php foreach ($allowed_roles as $role): ?>

                        <option
                            value="<?= h($role) ?>"
                            <?= $role_filter === $role
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= h(ucfirst($role)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-4 col-lg-2">

                <label class="filter-label">
                    Status
                </label>

                <select
                    name="status"
                    class="form-select filter-control"
                >

                    <option value="">
                        All Status
                    </option>

                    <?php foreach ($allowed_statuses as $status): ?>

                        <option
                            value="<?= h($status) ?>"
                            <?= $status_filter === $status
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= h(ucfirst($status)) ?>
                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-md-4 col-lg-3 d-flex gap-2">

                <button
                    type="submit"
                    class="btn btn-primary flex-grow-1"
                >
                    <i class="bi bi-search me-1"></i>
                    Search
                </button>

                <a
                    href="users.php"
                    class="btn btn-light"
                    title="Clear filters"
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                </a>

            </div>

        </form>

    </div>


    <!-- User Table -->

    <div class="user-table-card">

        <div class="table-header">

            <div>

                <h2>
                    System Users
                </h2>

                <p>
                    Showing <?= number_format($total_filtered) ?>
                    matching account<?= $total_filtered !== 1 ? 's' : '' ?>
                </p>

            </div>

            <span class="badge text-bg-light">
                Page <?= $page ?> / <?= $total_pages ?>
            </span>

        </div>


        <?php if ($users): ?>

            <div class="table-responsive">

                <table class="table user-table">

                    <thead>

                        <tr>

                            <th>
                                User
                            </th>

                            <th>
                                Email
                            </th>

                            <th>
                                Role
                            </th>

                            <th>
                                Status
                            </th>

                            <th>
                                Created
                            </th>

                            <th class="text-end">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($users as $u): ?>

                            <tr>

                                <!-- User -->

                                <td>

                                    <div class="user-info">

                                        <div class="user-avatar">
                                            <?= h(
                                                user_initials(
                                                    $u['name']
                                                )
                                            ) ?>
                                        </div>

                                        <div>

                                            <div class="user-name">
                                                <?= h($u['name']) ?>
                                            </div>

                                            <div class="user-id">
                                                ID #<?= (int)$u['user_id'] ?>
                                            </div>

                                        </div>

                                    </div>

                                </td>


                                <!-- Email -->

                                <td>

                                    <span class="user-email">
                                        <?= h($u['email']) ?>
                                    </span>

                                </td>


                                <!-- Role -->

                                <td>

                                    <span
                                        class="role-badge <?= h(
                                            user_role_class(
                                                $u['role']
                                            )
                                        ) ?>"
                                    >

                                        <i class="bi <?= h(
                                            user_role_icon(
                                                $u['role']
                                            )
                                        ) ?>"></i>

                                        <?= h(
                                            ucfirst($u['role'])
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Status -->

                                <td>

                                    <span
                                        class="account-badge <?= $u['status'] === 'active'
                                            ? 'account-active'
                                            : 'account-inactive'
                                        ?>"
                                    >

                                        <i class="bi <?= $u['status'] === 'active'
                                            ? 'bi-check-circle'
                                            : 'bi-x-circle'
                                        ?>"></i>

                                        <?= h(
                                            ucfirst($u['status'])
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Created -->

                                <td>

                                    <span class="created-date">

                                        <?= h(
                                            date(
                                                'd M Y',
                                                strtotime(
                                                    $u['created_at']
                                                )
                                            )
                                        ) ?>

                                    </span>

                                </td>


                                <!-- Action -->

                                <td class="text-end">

                                    <?php if ($u['role'] !== 'admin'): ?>

                                        <form
                                            method="post"
                                            class="d-inline"
                                            onsubmit="return confirm(
                                                'Are you sure you want to <?= $u['status'] === 'active'
                                                    ? 'deactivate'
                                                    : 'activate'
                                                ?> this account?'
                                            );"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="toggle"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int)$u['user_id'] ?>"
                                            >

                                            <?php if ($u['status'] === 'active'): ?>

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-danger action-btn"
                                                >
                                                    <i class="bi bi-person-x me-1"></i>
                                                    Deactivate
                                                </button>

                                            <?php else: ?>

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-success action-btn"
                                                >
                                                    <i class="bi bi-person-check me-1"></i>
                                                    Activate
                                                </button>

                                            <?php endif; ?>

                                        </form>

                                    <?php else: ?>

                                        <span class="text-secondary small">
                                            <i class="bi bi-shield-lock me-1"></i>
                                            Protected
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

                <div class="pagination-wrap">

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

                        users

                    </div>


                    <nav>

                        <ul class="pagination pagination-sm">

                            <?php if ($page > 1): ?>

                                <li class="page-item">

                                    <a
                                        class="page-link"
                                        href="<?= h(
                                            users_page_url(
                                                $page - 1,
                                                $search,
                                                $role_filter,
                                                $status_filter
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
                                            users_page_url(
                                                $i,
                                                $search,
                                                $role_filter,
                                                $status_filter
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
                                            users_page_url(
                                                $page + 1,
                                                $search,
                                                $role_filter,
                                                $status_filter
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

            <div class="user-empty">

                <div class="user-empty-icon">

                    <i class="bi bi-person-x"></i>

                </div>

                <h3>
                    No users found
                </h3>

                <p>
                    Try changing your search or filter criteria.
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>


<?php require '../includes/footer.php'; ?>