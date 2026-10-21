<?php

$page_title = 'Notifications';
$asset_prefix = '../';

require_once '../config/mongodb.php';
require '../includes/header.php';
require_role('admin');

/*
|--------------------------------------------------------------------------
| MongoDB Collection
|--------------------------------------------------------------------------
*/

$notificationsCollection = $mongoDb->selectCollection('notifications');

$currentUserId = (int)($_SESSION['user_id'] ?? 0);

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);

        if ($notificationId > 0) {
            $notificationsCollection->updateOne(
                [
                    'notification_id' => $notificationId,
                    'user_id' => $currentUserId
                ],
                ['$set' => ['is_read' => 1]]
            );

            flash('success', 'Notification marked as read.');
        }

        header('Location: notifications.php');
        exit;
    }

    if ($action === 'mark_all_read') {
        $notificationsCollection->updateMany(
            [
                'user_id' => $currentUserId,
                'is_read' => 0
            ],
            ['$set' => ['is_read' => 1]]
        );

        flash('success', 'All notifications marked as read.');

        header('Location: notifications.php');
        exit;
    }
}

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$read_filter = $_GET['read'] ?? '';
$type_filter = trim($_GET['type'] ?? '');

$page = max(1, (int)($_GET['page'] ?? 1));

$per_page = 15;
$offset = ($page - 1) * $per_page;

/*
|--------------------------------------------------------------------------
| MongoDB Filter
|--------------------------------------------------------------------------
*/

$filter = [
    'user_id' => $currentUserId
];

if ($search !== '') {
    $filter['$or'] = [
        ['title' => ['$regex' => preg_quote($search), '$options' => 'i']],
        ['message' => ['$regex' => preg_quote($search), '$options' => 'i']],
        ['type' => ['$regex' => preg_quote($search), '$options' => 'i']]
    ];
}

if ($read_filter === 'read') {
    $filter['is_read'] = 1;
} elseif ($read_filter === 'unread') {
    $filter['is_read'] = 0;
}

if ($type_filter !== '') {
    $filter['type'] = $type_filter;
}

/*
|--------------------------------------------------------------------------
| Total Records and Pagination
|--------------------------------------------------------------------------
*/

$total_rows = $notificationsCollection->countDocuments($filter);

$total_pages = max(1, (int)ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

/*
|--------------------------------------------------------------------------
| Notifications
|--------------------------------------------------------------------------
*/

$cursor = $notificationsCollection->find(
    $filter,
    [
        'projection' => [
            '_id' => 0,
            'notification_id' => 1,
            'type' => 1,
            'title' => 1,
            'message' => 1,
            'is_read' => 1,
            'created_at' => 1
        ],
        'sort' => [
            'is_read' => 1,
            'created_at' => -1
        ],
        'skip' => $offset,
        'limit' => $per_page
    ]
);

$notifications = [];

foreach ($cursor as $document) {
    $notification = (array)$document;

    $notification['is_read'] = (int)($notification['is_read'] ?? 0);

    $createdAt = $notification['created_at'] ?? '';

    if ($createdAt instanceof MongoDB\BSON\UTCDateTime) {
        $notification['created_at'] = $createdAt
            ->toDateTime()
            ->format('Y-m-d H:i:s');
    } elseif ($createdAt instanceof DateTimeInterface) {
        $notification['created_at'] = $createdAt->format('Y-m-d H:i:s');
    } else {
        $notification['created_at'] = (string)$createdAt;
    }

    $notifications[] = $notification;
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_notifications = $notificationsCollection->countDocuments([
    'user_id' => $currentUserId
]);

$unread_notifications = $notificationsCollection->countDocuments([
    'user_id' => $currentUserId,
    'is_read' => 0
]);

$read_notifications = $notificationsCollection->countDocuments([
    'user_id' => $currentUserId,
    'is_read' => 1
]);

/*
|--------------------------------------------------------------------------
| Notification Types
|--------------------------------------------------------------------------
*/

$typeDocuments = $notificationsCollection->distinct(
    'type',
    [
        'user_id' => $currentUserId,
        'type' => [
            '$exists' => true,
            '$nin' => [null, '']
        ]
    ]
);

$types = [];

foreach ($typeDocuments as $type) {
    if (is_string($type) && $type !== '') {
        $types[] = $type;
    }
}

sort($types);

$notification_types_count = count($types);

/*
|--------------------------------------------------------------------------
| Pagination Query Helper
|--------------------------------------------------------------------------
*/

function notification_query(array $extra = []): string
{
    $query = [
        'search' => $_GET['search'] ?? '',
        'read'   => $_GET['read'] ?? '',
        'type'   => $_GET['type'] ?? '',
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

<!-- Header -->

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

    <div>

        <span class="eyebrow">
            <i class="bi bi-bell"></i>
            Administration
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            Notifications
        </h1>

        <p class="text-secondary mb-0">
            Review important system notifications and administrative updates.
        </p>

    </div>


    <?php if ($unread_notifications > 0): ?>

        <form method="post">

            <?=csrf_field()?>

            <input
                type="hidden"
                name="action"
                value="mark_all_read"
            >

            <button class="btn btn-outline-primary">

                <i class="bi bi-check2-all me-1"></i>

                Mark All as Read

            </button>

        </form>

    <?php endif; ?>

</div>


<!-- Statistics -->

<div class="row g-3 mb-4">

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Total Notifications
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_notifications)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Unread
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($unread_notifications)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Read
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($read_notifications)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Notification Types
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($notification_types_count)?>
            </div>

        </div>

    </div>

</div>


<!-- Filters -->

<div class="card border-0 shadow-sm p-3 mb-4">

    <form method="get" class="row g-2">

        <div class="col-lg-4">

            <div class="input-group">

                <span class="input-group-text bg-white">
                    <i class="bi bi-search"></i>
                </span>

                <input
                    type="text"
                    name="search"
                    value="<?=h($search)?>"
                    class="form-control"
                    placeholder="Search notifications..."
                >

            </div>

        </div>


        <div class="col-lg-3">

            <select
                name="read"
                class="form-select"
            >

                <option value="">
                    All Status
                </option>

                <option
                    value="unread"
                    <?=$read_filter === 'unread' ? 'selected' : ''?>
                >
                    Unread
                </option>

                <option
                    value="read"
                    <?=$read_filter === 'read' ? 'selected' : ''?>
                >
                    Read
                </option>

            </select>

        </div>


        <div class="col-lg-3">

            <select
                name="type"
                class="form-select"
            >

                <option value="">
                    All Types
                </option>

                <?php foreach ($types as $type): ?>

                    <option
                        value="<?=h($type)?>"
                        <?=$type_filter === $type ? 'selected' : ''?>
                    >
                        <?=h(
                            ucwords(
                                str_replace(
                                    '_',
                                    ' ',
                                    $type
                                )
                            )
                        )?>
                    </option>

                <?php endforeach; ?>

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


<!-- Notifications -->

<div class="card border-0 shadow-sm overflow-hidden">

    <div class="card-header bg-white py-3">

        <div class="d-flex justify-content-between align-items-center">

            <div>

                <h5 class="fw-bold mb-1">
                    Notification Center
                </h5>

                <small class="text-secondary">

                    Showing
                    <?=count($notifications)?>
                    of
                    <?=number_format($total_rows)?>
                    notifications

                </small>

            </div>

            <span class="badge text-bg-light border">

                Page <?=$page?> / <?=$total_pages?>

            </span>

        </div>

    </div>


    <div class="list-group list-group-flush">

        <?php foreach ($notifications as $notification): ?>

            <div
                class="list-group-item p-4
                <?=$notification['is_read'] ? '' : 'bg-primary-subtle'?>"
            >

                <div class="d-flex gap-3">

                    <!-- Icon -->

                    <div>

                        <div
                            class="rounded-circle
                            bg-light
                            border
                            d-flex
                            align-items-center
                            justify-content-center"
                            style="width:44px;height:44px;"
                        >

                            <i class="bi bi-bell text-primary"></i>

                        </div>

                    </div>


                    <!-- Content -->

                    <div class="flex-grow-1">

                        <div class="d-flex flex-wrap justify-content-between gap-2">

                            <div>

                                <h6 class="fw-bold mb-1">

                                    <?=h($notification['title'])?>

                                    <?php if (!$notification['is_read']): ?>

                                        <span class="badge text-bg-primary ms-1">
                                            New
                                        </span>

                                    <?php endif; ?>

                                </h6>

                                <span class="badge text-bg-light border">

                                    <?=h(
                                        ucwords(
                                            str_replace(
                                                '_',
                                                ' ',
                                                $notification['type']
                                            )
                                        )
                                    )?>

                                </span>

                            </div>


                            <small class="text-secondary">

                                <?=h(
                                    date(
                                        'd M Y, h:i A',
                                        strtotime(
                                            $notification['created_at']
                                        )
                                    )
                                )?>

                            </small>

                        </div>


                        <p class="text-secondary mt-2 mb-3">

                            <?=nl2br(
                                h($notification['message'])
                            )?>

                        </p>


                        <?php if (!$notification['is_read']): ?>

                            <form method="post">

                                <?=csrf_field()?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="mark_read"
                                >

                                <input
                                    type="hidden"
                                    name="notification_id"
                                    value="<?=$notification['notification_id']?>"
                                >

                                <button
                                    class="btn btn-sm btn-outline-primary"
                                >

                                    <i class="bi bi-check2 me-1"></i>

                                    Mark as Read

                                </button>

                            </form>

                        <?php else: ?>

                            <span class="small text-success">

                                <i class="bi bi-check2-all me-1"></i>

                                Read

                            </span>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        <?php endforeach; ?>


        <?php if (!$notifications): ?>

            <div class="p-5 text-center">

                <div class="empty-state">

                    <i class="bi bi-bell-slash fs-1 d-block mb-3"></i>

                    <h5 class="fw-bold">
                        No notifications found
                    </h5>

                    <p class="text-secondary mb-0">
                        Try changing your search or filters.
                    </p>

                </div>

            </div>

        <?php endif; ?>

    </div>


    <!-- Pagination -->

    <?php if ($total_pages > 1): ?>

        <div class="card-footer bg-white">

            <nav>

                <ul class="pagination justify-content-center mb-0">


                    <li
                        class="page-item <?=$page <= 1 ? 'disabled' : ''?>"
                    >

                        <a
                            class="page-link"
                            href="?<?=h(
                                notification_query([
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
                                    notification_query([
                                        'page' => $i
                                    ])
                                )?>"
                            >
                                <?=$i?>
                            </a>

                        </li>

                    <?php endfor; ?>


                    <li
                        class="page-item <?=$page >= $total_pages ? 'disabled' : ''?>"
                    >

                        <a
                            class="page-link"
                            href="?<?=h(
                                notification_query([
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