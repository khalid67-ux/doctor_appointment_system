<?php

$page_title = 'Notifications';
$asset_prefix = '../';

/*
|--------------------------------------------------------------------------
| Load Core Files
|--------------------------------------------------------------------------
*/

require '../config/mongodb.php';
require '../includes/functions.php';
require '../includes/auth.php';

require_role('patient');

$user_id = (int)$_SESSION['user_id'];


/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

verify_csrf();


/*
|--------------------------------------------------------------------------
| Mark All Notifications As Read
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'mark_all_read'
) {

    $mongoDb->notifications->updateMany(
        [
            'user_id' => $user_id,
            'is_read' => 0
        ],
        [
            '$set' => ['is_read' => 1]
        ]
    );

    flash(
        'success',
        'All notifications marked as read.'
    );

    header('Location: notifications.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Mark Single Notification As Read
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    ($_POST['action'] ?? '') === 'mark_read'
) {

    $notification_id = (int) ($_POST['notification_id'] ?? 0);

    if ($notification_id > 0) {
        $mongoDb->notifications->updateOne(
            [
                'notification_id' => $notification_id,
                'user_id' => $user_id
            ],
            [
                '$set' => ['is_read' => 1]
            ]
        );
    }

    header('Location: notifications.php');
    exit;
}


/*
|--------------------------------------------------------------------------
| Notification Filter - MongoDB
|--------------------------------------------------------------------------
*/

$filter = $_GET['filter'] ?? 'all';

$allowed_filters = [
    'all',
    'unread',
    'appointment',
    'reschedule',
    'cancellation',
    'reminder',
    'queue',
    'waitlist'
];

if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'all';
}

$notificationFilter = [
    'user_id' => $user_id
];

if ($filter === 'unread') {
    $notificationFilter['is_read'] = 0;
} elseif ($filter !== 'all') {
    $notificationFilter['type'] = $filter;
}

/*
|--------------------------------------------------------------------------
| Get Notifications
|--------------------------------------------------------------------------
*/

$notifications = [];

$notificationCursor = $mongoDb->notifications->find(
    $notificationFilter,
    [
        'sort' => ['created_at' => -1],
        'limit' => 100
    ]
);

foreach ($notificationCursor as $notification) {
    $createdAt = $notification->created_at ?? '';

    if ($createdAt instanceof MongoDB\BSON\UTCDateTime) {
        $createdAt = $createdAt->toDateTime()
            ->format('Y-m-d H:i:s');
    }

    $notifications[] = [
        'notification_id' => (int) (
            $notification->notification_id ?? 0
        ),
        'type' => (string) ($notification->type ?? ''),
        'title' => (string) ($notification->title ?? ''),
        'message' => (string) ($notification->message ?? ''),
        'is_read' => (int) ($notification->is_read ?? 0),
        'created_at' => (string) $createdAt
    ];
}

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total = $mongoDb->notifications->countDocuments([
    'user_id' => $user_id
]);

$unread = $mongoDb->notifications->countDocuments([
    'user_id' => $user_id,
    'is_read' => 0
]);

$sevenDaysAgo = new MongoDB\BSON\UTCDateTime(
    (time() - (7 * 24 * 60 * 60)) * 1000
);

$recent = $mongoDb->notifications->countDocuments([
    'user_id' => $user_id,
    'created_at' => ['$gte' => $sevenDaysAgo]
]);

$stats = [
    'total' => $total,
    'unread' => $unread,
    'recent' => $recent
];


/*
|--------------------------------------------------------------------------
| Notification Icon
|--------------------------------------------------------------------------
*/

function notification_icon(string $type): string
{
    return match ($type) {

        'appointment'
            => 'bi-calendar-check',

        'reschedule'
            => 'bi-arrow-repeat',

        'cancellation'
            => 'bi-calendar-x',

        'reminder'
            => 'bi-alarm',

        'queue'
            => 'bi-people',

        'waitlist'
            => 'bi-hourglass-split',

        default
            => 'bi-bell'
    };
}


/*
|--------------------------------------------------------------------------
| Notification Style
|--------------------------------------------------------------------------
*/

function notification_style(string $type): string
{
    return match ($type) {

        'appointment'
            => 'primary',

        'reschedule'
            => 'info',

        'cancellation'
            => 'danger',

        'reminder'
            => 'warning',

        'queue'
            => 'success',

        'waitlist'
            => 'secondary',

        default
            => 'primary'
    };
}


/*
|--------------------------------------------------------------------------
| Header
|--------------------------------------------------------------------------
|
| IMPORTANT:
| Header is loaded only AFTER all possible redirects.
|
*/

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
                Notifications
            </h2>

            <p class="text-secondary mb-0">
                Stay updated with your appointments and queue activity.
            </p>

        </div>


        <?php if ((int)$stats['unread'] > 0): ?>

            <form method="post">

                <?= csrf_field() ?>

                <input
                    type="hidden"
                    name="action"
                    value="mark_all_read"
                >

                <button
                    type="submit"
                    class="btn btn-outline-primary"
                >
                    <i class="bi bi-check2-all me-1"></i>
                    Mark All Read
                </button>

            </form>

        <?php endif; ?>

    </div>


    <!-- Statistics -->

    <div class="row g-3 mb-4">

        <div class="col-md-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        Total Notifications
                    </div>

                    <div class="fs-2 fw-bold">
                        <?= (int)$stats['total'] ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        Unread
                    </div>

                    <div class="fs-2 fw-bold text-primary">
                        <?= (int)$stats['unread'] ?>
                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body p-4">

                    <div class="small text-secondary mb-2">
                        Last 7 Days
                    </div>

                    <div class="fs-2 fw-bold text-success">
                        <?= (int)$stats['recent'] ?>
                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Filters -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body p-3">

            <div class="d-flex flex-wrap gap-2">

                <?php

                $filters = [
                    'all' => 'All',
                    'unread' => 'Unread',
                    'appointment' => 'Appointments',
                    'reschedule' => 'Rescheduled',
                    'cancellation' => 'Cancelled',
                    'reminder' => 'Reminders',
                    'queue' => 'Queue',
                    'waitlist' => 'Waitlist'
                ];

                ?>

                <?php foreach ($filters as $key => $label): ?>

                    <a
                        href="notifications.php?filter=<?= urlencode($key) ?>"
                        class="btn btn-sm <?= $filter === $key
                            ? 'btn-primary'
                            : 'btn-outline-primary' ?>"
                    >
                        <?= h($label) ?>
                    </a>

                <?php endforeach; ?>

            </div>

        </div>

    </div>


    <!-- Notification List -->

    <div class="card border-0 shadow-sm">

        <div class="card-body p-0">

            <?php if (!$notifications): ?>

                <div class="text-center py-5 px-3">

                    <div class="display-5 text-secondary mb-3">
                        <i class="bi bi-bell-slash"></i>
                    </div>

                    <h5 class="fw-bold">
                        No notifications
                    </h5>

                    <p class="text-secondary mb-0">
                        There are no notifications for this filter.
                    </p>

                </div>

            <?php else: ?>

                <div class="list-group list-group-flush">

                    <?php foreach ($notifications as $notification): ?>

                        <?php

                        $type =
                            (string)$notification['type'];

                        $style =
                            notification_style($type);

                        $icon =
                            notification_icon($type);

                        $is_unread =
                            (int)$notification['is_read'] === 0;

                        ?>

                        <div
                            class="list-group-item px-4 py-4
                            <?= $is_unread
                                ? 'bg-primary-subtle'
                                : '' ?>"
                        >

                            <div class="d-flex gap-3">


                                <!-- Icon -->

                                <div
                                    class="rounded-circle
                                    bg-<?= $style ?>-subtle
                                    text-<?= $style ?>
                                    d-flex
                                    align-items-center
                                    justify-content-center
                                    flex-shrink-0"
                                    style="
                                        width:46px;
                                        height:46px;
                                    "
                                >

                                    <i
                                        class="bi <?= $icon ?>"
                                    ></i>

                                </div>


                                <!-- Content -->

                                <div class="flex-grow-1">

                                    <div
                                        class="d-flex
                                        flex-wrap
                                        justify-content-between
                                        gap-2"
                                    >

                                        <div>

                                            <h6 class="fw-bold mb-1">

                                                <?= h(
                                                    $notification['title']
                                                ) ?>

                                                <?php if ($is_unread): ?>

                                                    <span
                                                        class="badge text-bg-primary ms-1"
                                                    >
                                                        New
                                                    </span>

                                                <?php endif; ?>

                                            </h6>


                                            <p class="text-secondary mb-2">

                                                <?= h(
                                                    $notification['message']
                                                ) ?>

                                            </p>


                                            <small class="text-secondary">

                                                <i
                                                    class="bi bi-clock me-1"
                                                ></i>

                                                <?= date(
                                                    'd M Y, h:i A',
                                                    strtotime(
                                                        $notification['created_at']
                                                    )
                                                ) ?>

                                            </small>

                                        </div>


                                        <!-- Mark Read -->

                                        <?php if ($is_unread): ?>

                                            <form method="post">

                                                <?= csrf_field() ?>

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="mark_read"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="notification_id"
                                                    value="<?= (int)$notification['notification_id'] ?>"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn btn-sm btn-outline-primary"
                                                >
                                                    <i class="bi bi-check2 me-1"></i>
                                                    Mark Read
                                                </button>

                                            </form>

                                        <?php endif; ?>

                                    </div>

                                </div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>


<?php require '../includes/footer.php'; ?>