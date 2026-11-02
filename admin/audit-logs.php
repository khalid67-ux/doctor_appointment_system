<?php

$page_title = 'Audit Logs';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$auditCollection = $mongoDb->selectCollection('audit_logs');
$usersCollection = $mongoDb->selectCollection('users');

/*
|--------------------------------------------------------------------------
| Filters
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$action_filter = trim($_GET['action'] ?? '');
$entity_filter = trim($_GET['entity_type'] ?? '');

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = 20;


function audit_timestamp_string($value): string
{
    if ($value instanceof MongoDB\BSON\UTCDateTime) {
        return $value->toDateTime()->format('Y-m-d H:i:s');
    }

    if ($value instanceof DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }

    return is_scalar($value) ? (string) $value : '';
}

$allLogs = [];
$actions = [];
$entity_types = [];
$total_logs = 0;
$today_logs = 0;
$activeUserIds = [];
$entityTypesForStats = [];
$today = date('Y-m-d');
$cutoff = strtotime('-30 days');

$logCursor = $auditCollection->find(
    [],
    ['sort' => ['timestamp' => -1, 'log_id' => -1]]
);

foreach ($logCursor as $logDocument) {
    $log = $logDocument->getArrayCopy();
    $total_logs++;

    $action = (string) ($log['action'] ?? '');
    $entityType = (string) ($log['entity_type'] ?? '');
    $timestamp = audit_timestamp_string(
        $log['timestamp'] ?? $log['created_at'] ?? ''
    );

    if ($action !== '') {
        $actions[$action] = true;
    }

    if ($entityType !== '') {
        $entity_types[$entityType] = true;
        $entityTypesForStats[$entityType] = true;
    }

    if ($timestamp !== '' && substr($timestamp, 0, 10) === $today) {
        $today_logs++;
    }

    $timestampValue = $timestamp !== '' ? strtotime($timestamp) : false;

    if (
        $timestampValue !== false &&
        $timestampValue >= $cutoff &&
        isset($log['user_id']) &&
        $log['user_id'] !== null
    ) {
        $activeUserIds[(string) $log['user_id']] = true;
    }

    $userDocument = null;

    if (isset($log['user_id']) && $log['user_id'] !== null) {
        $userDocument = $usersCollection->findOne([
            'user_id' => (int) $log['user_id'],
        ]);
    }

    $user = $userDocument !== null
        ? $userDocument->getArrayCopy()
        : [];

    $log['user_name'] = $user['name'] ?? null;
    $log['user_email'] = $user['email'] ?? null;
    $log['timestamp'] = $timestamp;
    $allLogs[] = $log;
}

usort($allLogs, function ($a, $b) {
    $timeA = !empty($a['timestamp']) ? strtotime($a['timestamp']) : false;
    $timeB = !empty($b['timestamp']) ? strtotime($b['timestamp']) : false;

    if ($timeA !== $timeB) {
        if ($timeA === false) {
            return 1;
        }

        if ($timeB === false) {
            return -1;
        }

        return $timeB <=> $timeA;
    }

    return (int) ($b['log_id'] ?? 0) <=> (int) ($a['log_id'] ?? 0);
});

$actions = array_keys($actions);
$entity_types = array_keys($entity_types);

sort($actions, SORT_NATURAL | SORT_FLAG_CASE);
sort($entity_types, SORT_NATURAL | SORT_FLAG_CASE);

$active_users = count($activeUserIds);
$entity_count = count($entityTypesForStats);

$filteredLogs = [];

foreach ($allLogs as $log) {
    if (
        $action_filter !== '' &&
        ($log['action'] ?? '') !== $action_filter
    ) {
        continue;
    }

    if (
        $entity_filter !== '' &&
        ($log['entity_type'] ?? '') !== $entity_filter
    ) {
        continue;
    }

    if ($search !== '') {
        $searchable = implode(' ', [
            (string) ($log['user_name'] ?? ''),
            (string) ($log['user_email'] ?? ''),
            (string) ($log['action'] ?? ''),
            (string) ($log['entity_type'] ?? ''),
            (string) ($log['entity_id'] ?? ''),
        ]);

        if (stripos($searchable, $search) === false) {
            continue;
        }
    }

    $filteredLogs[] = $log;
}

$total_rows = count($filteredLogs);
$total_pages = max(1, (int) ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;
$logs = array_slice($filteredLogs, $offset, $per_page);


/*
|--------------------------------------------------------------------------
| Pagination Query String
|--------------------------------------------------------------------------
*/

function audit_query(array $extra = []): string
{
    $query = [
        'search' => $_GET['search'] ?? '',
        'action' => $_GET['action'] ?? '',
        'entity_type' => $_GET['entity_type'] ?? '',
        'page' => $_GET['page'] ?? 1
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

require '../includes/header.php';

?>

<!-- Header -->

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

    <div>

        <span class="eyebrow">
            <i class="bi bi-shield-check"></i>
            Administration
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            Audit Logs
        </h1>

        <p class="text-secondary mb-0">
            Track important administrative and system actions.
        </p>

    </div>

</div>


<!-- Statistics -->

<div class="row g-3 mb-4">

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Total Logs
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_logs)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Today's Logs
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($today_logs)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Active Users
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($active_users)?>
            </div>

            <small class="text-secondary">
                Last 30 days
            </small>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Entity Types
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($entity_count)?>
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
                    placeholder="Search user, action, entity..."
                >

            </div>

        </div>


        <div class="col-lg-3">

            <select
                name="action"
                class="form-select"
            >

                <option value="">
                    All Actions
                </option>

                <?php foreach ($actions as $action): ?>

                    <option
                        value="<?=h($action)?>"
                        <?=$action_filter === $action ? 'selected' : ''?>
                    >
                        <?=h($action)?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-lg-3">

            <select
                name="entity_type"
                class="form-select"
            >

                <option value="">
                    All Entity Types
                </option>

                <?php foreach ($entity_types as $entity): ?>

                    <option
                        value="<?=h($entity)?>"
                        <?=$entity_filter === $entity ? 'selected' : ''?>
                    >
                        <?=h(ucwords(str_replace('_', ' ', $entity)))?>
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


<!-- Logs Table -->

<div class="card border-0 shadow-sm overflow-hidden">

    <div class="card-header bg-white py-3">

        <div class="d-flex justify-content-between align-items-center">

            <div>

                <h5 class="fw-bold mb-1">
                    Activity History
                </h5>

                <small class="text-secondary">

                    Showing
                    <?=count($logs)?>
                    of
                    <?=number_format($total_rows)?>
                    records

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

                    <th>#</th>

                    <th>User</th>

                    <th>Action</th>

                    <th>Entity</th>

                    <th>Entity ID</th>

                    <th>Timestamp</th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($logs as $log): ?>

                    <tr>

                        <td>

                            <span class="text-secondary">
                                #<?=number_format((int)$log['log_id'])?>
                            </span>

                        </td>


                        <td>

                            <?php if ($log['user_name']): ?>

                                <strong>
                                    <?=h($log['user_name'])?>
                                </strong>

                                <br>

                                <small class="text-secondary">
                                    <?=h($log['user_email'])?>
                                </small>

                            <?php else: ?>

                                <span class="text-secondary">
                                    System / Unknown
                                </span>

                            <?php endif; ?>

                        </td>


                        <td>

                            <span class="badge text-bg-light border">

                                <?=h($log['action'])?>

                            </span>

                        </td>


                        <td>

                            <?php

                            $entity_label = $log['entity_type']
                                ? ucwords(
                                    str_replace(
                                        '_',
                                        ' ',
                                        $log['entity_type']
                                    )
                                )
                                : '—';

                            ?>

                            <?=h($entity_label)?>

                        </td>


                        <td>

                            <?php if ($log['entity_id'] !== null): ?>

                                <code>
                                    <?=h((string)$log['entity_id'])?>
                                </code>

                            <?php else: ?>

                                —

                            <?php endif; ?>

                        </td>


                        <td>

                            <?php
                            $timestampValue = $log['timestamp'] ?? '';
                            $timestampParsed = $timestampValue !== ''
                                ? strtotime($timestampValue)
                                : false;
                            ?>

                            <?php if ($timestampParsed !== false): ?>
                                <strong>
                                    <?= h(date('d M Y', $timestampParsed)) ?>
                                </strong>
                                <br>
                                <small class="text-secondary">
                                    <?= h(date('h:i A', $timestampParsed)) ?>
                                </small>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$logs): ?>

                    <tr>

                        <td colspan="6">

                            <div class="empty-state p-5 text-center">

                                <i class="bi bi-shield-check fs-1 d-block mb-3"></i>

                                <h5 class="fw-bold">
                                    No audit logs found
                                </h5>

                                <p class="text-secondary mb-0">
                                    Try changing your search or filters.
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
                                audit_query([
                                    'page' => max(1, $page - 1)
                                ])
                            )?>"
                        >
                            Previous
                        </a>

                    </li>


                    <?php

                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

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
                                    audit_query([
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
                                audit_query([
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