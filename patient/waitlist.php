<?php

$page_title = 'Waiting List';
$asset_prefix = '../';

require '../includes/header.php';
require_role('patient');

$pid = get_patient_id((int)$_SESSION['user_id']);

if (!$pid) {
    echo '
        <div class="container py-5">
            <div class="alert alert-danger">
                Patient profile not found.
            </div>
        </div>
    ';

    require '../includes/footer.php';
    exit;
}


/*
|--------------------------------------------------------------------------
| Handle POST actions
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? 'join';


    /*
    |--------------------------------------------------------------------------
    | Cancel waitlist
    |--------------------------------------------------------------------------
    */
    if ($action === 'cancel') {

        $wid = (int)($_POST['waitlist_id'] ?? 0);

        if (!$wid) {

            flash('danger', 'Invalid waiting-list request.');

        } else {

            $cancelResult = $mongoDb->waitlists->updateOne(
                [
                    'waitlist_id' => $wid,
                    'patient_id' => (int) $pid,
                    'status' => ['$in' => ['Waiting', 'Notified']]
                ],
                [
                    '$set' => ['status' => 'Cancelled']
                ]
            );

            if ($cancelResult->getModifiedCount() > 0) {
                flash('success', 'Waiting-list request cancelled successfully.');
            } else {
                flash('danger', 'This waiting-list request could not be cancelled.');
            }
        }

        header('Location: waitlist.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Join waitlist
    |--------------------------------------------------------------------------
    */
    if ($action === 'join') {

        $did  = (int)($_POST['doctor_id'] ?? 0);
        $date = trim($_POST['date'] ?? '');
        $time = trim($_POST['time'] ?? '');

        /*
        |--------------------------------------------------------------------------
        | Validate doctor
        |--------------------------------------------------------------------------
        */
        $doctorDoc = $mongoDb->doctors->findOne([
            'doctor_id' => $did,
            'verification_status' => 'Approved'
        ]);

        $doctorIsValid = false;

        if ($doctorDoc) {
            $doctorData = (array) $doctorDoc;

            $doctorUser = $mongoDb->users->findOne([
                'user_id' => (int) ($doctorData['user_id'] ?? 0),
                'status' => 'active'
            ]);

            $doctorIsValid = (bool) $doctorUser;
        }

        /*
        |--------------------------------------------------------------------------
        | Validate date/time
        |--------------------------------------------------------------------------
        */
        $validTime = ($time === '' || valid_time($time));

        if (
            !$doctorIsValid
            || !valid_date($date)
            || $date < date('Y-m-d')
            || !$validTime
        ) {

            flash(
                'danger',
                'Please provide valid waiting-list details.'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | Prevent duplicate active request
            |--------------------------------------------------------------------------
            */
            $duplicateFilter = [
                'patient_id' => (int) $pid,
                'doctor_id' => $did,
                'preferred_date' => $date,
                'status' => ['$in' => ['Waiting', 'Notified']]
            ];

            if ($time === '') {
                $duplicateFilter['$or'] = [
                    ['preferred_time' => null],
                    ['preferred_time' => ['$exists' => false]],
                    ['preferred_time' => '']
                ];
            } else {
                $duplicateFilter['preferred_time'] = $time;
            }

            $duplicate = $mongoDb->waitlists->findOne($duplicateFilter);

            if ($duplicate) {

                flash(
                    'warning',
                    'You already have an active waiting-list request for this preference.'
                );

            } else {

                /*
                |--------------------------------------------------------------------------
                | Create waitlist request
                |--------------------------------------------------------------------------
                */
                // Atomically generate a numeric waiting-list ID
                $counter = $mongoDb->counters->findOneAndUpdate(
                    ['_id' => 'waitlist_id'],
                    ['$inc' => ['sequence_value' => 1]],
                    [
                        'upsert' => true,
                        'returnDocument' =>
                            MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                    ]
                );

                if (!$counter) {
                    throw new RuntimeException(
                        'Unable to generate waiting-list ID.'
                    );
                }

                $waitlistId = (int) $counter->sequence_value;

                $mongoDb->waitlists->insertOne([
                    'waitlist_id' => $waitlistId,
                    'patient_id' => (int) $pid,
                    'doctor_id' => $did,
                    'preferred_date' => $date,
                    'preferred_time' => $time !== '' ? $time : null,
                    'status' => 'Waiting',
                    'created_at' => new MongoDB\BSON\UTCDateTime()
                ]);

                audit(
                    (int) $_SESSION['user_id'],
                    'Joined waiting list',
                    'waitlist',
                    $waitlistId
                );

                flash('success', 'You have been added to the waiting list.');
            }
        }

        header('Location: waitlist.php');
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Fetch approved doctors
|--------------------------------------------------------------------------
*/
$doctors = [];

$doctorDocs = $mongoDb->doctors->find(
    ['verification_status' => 'Approved']
);

foreach ($doctorDocs as $doctorDoc) {
    $d = (array) $doctorDoc;

    $userDoc = $mongoDb->users->findOne([
        'user_id' => (int) ($d['user_id'] ?? 0),
        'status' => 'active'
    ]);

    if (!$userDoc) {
        continue;
    }

    $u = (array) $userDoc;

    $specDoc = $mongoDb->specializations->findOne([
        'specialization_id' => (int) ($d['specialization_id'] ?? 0)
    ]);

    $doctors[] = [
        'doctor_id' => (int) $d['doctor_id'],
        'name' => $u['name'] ?? 'Doctor',
        'specialization' => $specDoc
            ? ((array) $specDoc)['name'] ?? 'General'
            : 'General'
    ];
}

usort($doctors, fn($a, $b) =>
    strcasecmp($a['name'], $b['name'])
);


/*
|--------------------------------------------------------------------------
| Fetch patient's waitlist requests
|--------------------------------------------------------------------------
*/
$rows = [];

$waitlistDocs = $mongoDb->waitlists->find(
    ['patient_id' => (int) $pid],
    ['sort' => ['created_at' => -1]]
);

foreach ($waitlistDocs as $waitlistDoc) {
    $w = (array) $waitlistDoc;

    $doctorDoc = $mongoDb->doctors->findOne([
        'doctor_id' => (int) ($w['doctor_id'] ?? 0)
    ]);

    $doctorData = $doctorDoc ? (array) $doctorDoc : [];

    $userDoc = $doctorData
        ? $mongoDb->users->findOne([
            'user_id' => (int) ($doctorData['user_id'] ?? 0)
        ])
        : null;

    $specDoc = $doctorData
        ? $mongoDb->specializations->findOne([
            'specialization_id' =>
                (int) ($doctorData['specialization_id'] ?? 0)
        ])
        : null;

    $rows[] = [
        'waitlist_id' => (int) ($w['waitlist_id'] ?? 0),
        'doctor_id' => (int) ($w['doctor_id'] ?? 0),
        'preferred_date' => $w['preferred_date'] ?? '',
        'preferred_time' => $w['preferred_time'] ?? null,
        'status' => $w['status'] ?? 'Waiting',
        'created_at' => $w['created_at'] ?? null,
        'doctor_name' => $userDoc
            ? ((array) $userDoc)['name'] ?? 'Doctor'
            : 'Doctor',
        'specialization' => $specDoc
            ? ((array) $specDoc)['name'] ?? 'General'
            : 'General'
    ];
}


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/
$activeCount = 0;
$notifiedCount = 0;
$cancelledCount = 0;

foreach ($rows as $row) {

    if ($row['status'] === 'Waiting') {
        $activeCount++;
    }

    if ($row['status'] === 'Notified') {
        $notifiedCount++;
    }

    if ($row['status'] === 'Cancelled') {
        $cancelledCount++;
    }
}

?>


<div class="container py-4 py-lg-5">

    <!-- Header -->
    <div class="mb-4">

        <span class="eyebrow">
            <i class="bi bi-hourglass-split"></i>
            Dynamic Availability
        </span>

        <h1 class="h3 fw-bold mt-2 mb-2">
            Waiting List
        </h1>

        <p class="text-secondary mb-0">
            Request a notification when your preferred doctor or time becomes available.
        </p>

    </div>


    <!-- Statistics -->
    <div class="row g-3 mb-4">

        <div class="col-md-4">

            <div class="card border-0 shadow-sm p-4 h-100">

                <div class="d-flex align-items-center gap-3">

                    <div
                        class="rounded-3 bg-primary-subtle text-primary d-flex align-items-center justify-content-center"
                        style="width:48px;height:48px;"
                    >
                        <i class="bi bi-hourglass-split fs-5"></i>
                    </div>

                    <div>

                        <div class="mini-label">
                            Waiting
                        </div>

                        <div class="h4 fw-bold mb-0">
                            <?= $activeCount ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-0 shadow-sm p-4 h-100">

                <div class="d-flex align-items-center gap-3">

                    <div
                        class="rounded-3 bg-success-subtle text-success d-flex align-items-center justify-content-center"
                        style="width:48px;height:48px;"
                    >
                        <i class="bi bi-bell fs-5"></i>
                    </div>

                    <div>

                        <div class="mini-label">
                            Notified
                        </div>

                        <div class="h4 fw-bold mb-0">
                            <?= $notifiedCount ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>


        <div class="col-md-4">

            <div class="card border-0 shadow-sm p-4 h-100">

                <div class="d-flex align-items-center gap-3">

                    <div
                        class="rounded-3 bg-secondary-subtle text-secondary d-flex align-items-center justify-content-center"
                        style="width:48px;height:48px;"
                    >
                        <i class="bi bi-list-check fs-5"></i>
                    </div>

                    <div>

                        <div class="mini-label">
                            Total Requests
                        </div>

                        <div class="h4 fw-bold mb-0">
                            <?= count($rows) ?>
                        </div>

                    </div>

                </div>

            </div>

        </div>

    </div>


    <!-- Join Waitlist -->
    <div class="card border-0 shadow-sm p-4 p-lg-5 mb-4">

        <div class="mb-4">

            <h2 class="h5 fw-bold mb-1">
                Join the Waiting List
            </h2>

            <p class="text-secondary small mb-0">
                Choose a doctor and preferred appointment date. Time is optional.
            </p>

        </div>


        <form method="post">

            <?= csrf_field() ?>

            <input
                type="hidden"
                name="action"
                value="join"
            >


            <div class="row g-3">

                <!-- Doctor -->
                <div class="col-lg-5">

                    <label
                        for="doctor_id"
                        class="form-label fw-semibold"
                    >
                        Doctor
                    </label>

                    <select
                        class="form-select"
                        name="doctor_id"
                        id="doctor_id"
                        required
                    >

                        <option value="">
                            Select doctor
                        </option>

                        <?php foreach ($doctors as $doctor): ?>

                            <option
                                value="<?= (int)$doctor['doctor_id'] ?>"
                            >
                                Dr. <?= h($doctor['name']) ?>
                                ·
                                <?= h($doctor['specialization'] ?? 'General') ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <!-- Date -->
                <div class="col-sm-6 col-lg-3">

                    <label
                        for="waitlist_date"
                        class="form-label fw-semibold"
                    >
                        Preferred Date
                    </label>

                    <input
                        type="date"
                        class="form-control"
                        name="date"
                        id="waitlist_date"
                        min="<?= date('Y-m-d') ?>"
                        required
                    >

                </div>


                <!-- Time -->
                <div class="col-sm-6 col-lg-2">

                    <label
                        for="waitlist_time"
                        class="form-label fw-semibold"
                    >
                        Preferred Time
                    </label>

                    <input
                        type="time"
                        class="form-control"
                        name="time"
                        id="waitlist_time"
                    >

                </div>


                <!-- Button -->
                <div class="col-lg-2 d-flex align-items-end">

                    <button
                        type="submit"
                        class="btn btn-primary w-100"
                    >
                        <i class="bi bi-plus-circle me-2"></i>
                        Join List
                    </button>

                </div>

            </div>

        </form>

    </div>


    <!-- Existing Requests -->
    <div class="card border-0 shadow-sm overflow-hidden">

        <div class="card-header bg-white border-bottom p-4">

            <div>

                <h2 class="h5 fw-bold mb-1">
                    My Waiting-List Requests
                </h2>

                <p class="text-secondary small mb-0">
                    Track your active and previous waiting-list requests.
                </p>

            </div>

        </div>


        <?php if ($rows): ?>

            <div class="table-responsive">

                <table class="table align-middle mb-0">

                    <thead>

                        <tr>

                            <th class="px-4">
                                Doctor
                            </th>

                            <th>
                                Preferred Date
                            </th>

                            <th>
                                Time
                            </th>

                            <th>
                                Status
                            </th>

                            <th class="text-end px-4">
                                Action
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($rows as $row): ?>

                            <tr>

                                <!-- Doctor -->
                                <td class="px-4">

                                    <div class="fw-semibold">
                                        Dr. <?= h($row['doctor_name']) ?>
                                    </div>

                                    <small class="text-secondary">
                                        <?= h(
                                            $row['specialization']
                                            ?? 'General'
                                        ) ?>
                                    </small>

                                </td>


                                <!-- Date -->
                                <td>

                                    <div class="fw-semibold">
                                        <?= h(
                                            date(
                                                'M d, Y',
                                                strtotime(
                                                    $row['preferred_date']
                                                )
                                            )
                                        ) ?>
                                    </div>

                                </td>


                                <!-- Time -->
                                <td>

                                    <?php if ($row['preferred_time']): ?>

                                        <span class="badge bg-light text-dark border">
                                            <?= h(
                                                date(
                                                    'h:i A',
                                                    strtotime(
                                                        $row['preferred_time']
                                                    )
                                                )
                                            ) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="text-secondary">
                                            Any time
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- Status -->
                                <td>

                                    <?php if ($row['status'] === 'Waiting'): ?>

                                        <span class="badge rounded-pill bg-primary-subtle text-primary">
                                            <i class="bi bi-hourglass-split me-1"></i>
                                            Waiting
                                        </span>

                                    <?php elseif ($row['status'] === 'Notified'): ?>

                                        <span class="badge rounded-pill bg-success-subtle text-success">
                                            <i class="bi bi-bell me-1"></i>
                                            Notified
                                        </span>

                                    <?php elseif ($row['status'] === 'Cancelled'): ?>

                                        <span class="badge rounded-pill bg-secondary-subtle text-secondary">
                                            <i class="bi bi-x-circle me-1"></i>
                                            Cancelled
                                        </span>

                                    <?php else: ?>

                                        <span class="badge rounded-pill bg-light text-dark border">
                                            <?= h($row['status']) ?>
                                        </span>

                                    <?php endif; ?>

                                </td>


                                <!-- Action -->
                                <td class="text-end px-4">

                                    <?php if (
                                        in_array(
                                            $row['status'],
                                            ['Waiting', 'Notified'],
                                            true
                                        )
                                    ): ?>

                                        <form
                                            method="post"
                                            class="d-inline"
                                            onsubmit="return confirm('Cancel this waiting-list request?');"
                                        >

                                            <?= csrf_field() ?>

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="cancel"
                                            >

                                            <input
                                                type="hidden"
                                                name="waitlist_id"
                                                value="<?= (int)$row['waitlist_id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                class="btn btn-sm btn-outline-danger"
                                            >
                                                <i class="bi bi-x-lg me-1"></i>
                                                Cancel
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="text-muted small">
                                            —
                                        </span>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty-state p-5 text-center">

                <div
                    class="rounded-circle bg-light d-inline-flex align-items-center justify-content-center mb-3"
                    style="width:70px;height:70px;"
                >
                    <i class="bi bi-hourglass fs-3 text-secondary"></i>
                </div>

                <h3 class="h5 fw-bold">
                    No waiting-list requests
                </h3>

                <p class="text-secondary mb-0">
                    When your preferred appointment is unavailable,
                    you can join the waiting list here.
                </p>

            </div>

        <?php endif; ?>

    </div>

</div>


<style>

.table > :not(caption) > * > * {
    padding-top: 1rem;
    padding-bottom: 1rem;
}

.table thead th {
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: .04em;
    color: #64748b;
    font-weight: 700;
    background: #f8fafc;
    white-space: nowrap;
}

.card {
    border-radius: 18px;
}

.form-select,
.form-control {
    border-radius: 12px;
}

.btn {
    border-radius: 12px;
}

@media (max-width: 767.98px) {

    .table {
        min-width: 760px;
    }

}

</style>


<?php require '../includes/footer.php'; ?>