<?php

$page_title = 'My Schedule';
$asset_prefix = '../';

/*
|--------------------------------------------------------------------------
| Core Files
|--------------------------------------------------------------------------
| IMPORTANT:
| header.php is loaded only AFTER all POST/redirect processing.
|--------------------------------------------------------------------------
*/

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('doctor');

$did = get_doctor_id((int) $_SESSION['user_id']);


/*
|--------------------------------------------------------------------------
| Days
|--------------------------------------------------------------------------
*/

$validDays = [
    'sunday',
    'monday',
    'tuesday',
    'wednesday',
    'thursday',
    'friday',
    'saturday'
];

$dayLabels = [
    'sunday'    => 'Sunday',
    'monday'    => 'Monday',
    'tuesday'   => 'Tuesday',
    'wednesday' => 'Wednesday',
    'thursday'  => 'Thursday',
    'friday'    => 'Friday',
    'saturday'  => 'Saturday'
];


/*
|--------------------------------------------------------------------------
| Get Doctor Assigned Branches
|--------------------------------------------------------------------------
*/

$doctorBranchesCollection = $mongoDb->selectCollection('doctor_branches');
$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');

$branches = [];

$branchCursor = $doctorBranchesCollection->find(
    ['doctor_id' => (int) $did]
);

foreach ($branchCursor as $branchAssignment) {
    $branchId = (int) ($branchAssignment['branch_id'] ?? 0);

    $branchDocument = $branchesCollection->findOne([
        'branch_id' => $branchId,
    ]);

    if ($branchDocument === null) {
        continue;
    }

    $branch = $branchDocument->getArrayCopy();

    $clinicDocument = $clinicsCollection->findOne([
        'clinic_id' => (int) ($branch['clinic_id'] ?? 0),
    ]);

    $branch['clinic_name'] = $clinicDocument['name'] ?? '';

    $branches[] = $branch;
}

usort($branches, function ($a, $b) {
    $clinicCompare = strcmp(
        (string) ($a['clinic_name'] ?? ''),
        (string) ($b['clinic_name'] ?? '')
    );

    return $clinicCompare !== 0
        ? $clinicCompare
        : strcmp(
            (string) ($a['branch_name'] ?? ''),
            (string) ($b['branch_name'] ?? '')
        );
});


/*
|--------------------------------------------------------------------------
| Check Branch Ownership
|--------------------------------------------------------------------------
*/

function doctor_branch_exists(
    $doctorBranchesCollection,
    int $doctorId,
    int $branchId
): bool {
    return $doctorBranchesCollection->findOne([
        'doctor_id' => $doctorId,
        'branch_id' => $branchId,
    ]) !== null;
}


/*
|--------------------------------------------------------------------------
| Schedule Collection and ID Counter
|--------------------------------------------------------------------------
*/

$schedulesCollection = $mongoDb->selectCollection('schedules');
$countersCollection = $mongoDb->selectCollection('counters');

$lastSchedule = $schedulesCollection->findOne(
    [],
    [
        'sort' => ['schedule_id' => -1],
        'projection' => ['schedule_id' => 1],
    ]
);

$maxScheduleId = $lastSchedule !== null
    ? (int) ($lastSchedule['schedule_id'] ?? 0)
    : 0;

$countersCollection->updateOne(
    ['_id' => 'schedule_id'],
    ['$max' => ['sequence_value' => $maxScheduleId]],
    ['upsert' => true]
);

function next_schedule_id($countersCollection): int
{
    $result = $countersCollection->findOneAndUpdate(
        ['_id' => 'schedule_id'],
        ['$inc' => ['sequence_value' => 1]],
        [
            'upsert' => true,
            'returnDocument' =>
                MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ]
    );

    if ($result === null) {
        throw new RuntimeException('Unable to generate schedule ID.');
    }

    return (int) $result['sequence_value'];
}


/*
|--------------------------------------------------------------------------
| POST ACTIONS
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';


    /*
    |--------------------------------------------------------------------------
    | ADD SCHEDULE
    |--------------------------------------------------------------------------
    */

    if ($action === 'add') {

        $day = strtolower(trim($_POST['day'] ?? ''));
        $start = trim($_POST['start'] ?? '');
        $end = trim($_POST['end'] ?? '');
        $duration = (int) ($_POST['duration'] ?? 30);
        $branchId = (int) ($_POST['branch_id'] ?? 0);


        $valid = (
            in_array($day, $validDays, true)
            && valid_time($start)
            && valid_time($end)
            && $start < $end
            && $duration >= 5
            && $duration <= 240
            && doctor_branch_exists(
                $doctorBranchesCollection,
                (int) $did,
                $branchId
            )
        );


        if (!$valid) {

            flash(
                'danger',
                'Please enter valid schedule information.'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | Check Overlap
            |--------------------------------------------------------------------------
            */

            $overlap = $schedulesCollection->findOne([
                'doctor_id' => (int) $did,
                'branch_id' => (int) $branchId,
                'day_of_week' => $day,
                'start_time' => ['$lt' => $end],
                'end_time' => ['$gt' => $start],
            ]);

            if ($overlap !== null) {
                flash(
                    'danger',
                    'This schedule overlaps with an existing schedule for this branch and day.'
                );
            } else {
                $scheduleId = next_schedule_id($countersCollection);

                $schedulesCollection->insertOne([
                    'schedule_id' => $scheduleId,
                    'doctor_id' => (int) $did,
                    'branch_id' => (int) $branchId,
                    'day_of_week' => $day,
                    'start_time' => $start,
                    'end_time' => $end,
                    'slot_duration' => (int) $duration,
                ]);

                audit(
                    (int) $_SESSION['user_id'],
                    'Added schedule',
                    'schedule',
                    $scheduleId
                );

                flash('success', 'Working schedule added successfully.');
            }
        }


        header('Location: schedule.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | UPDATE SCHEDULE
    |--------------------------------------------------------------------------
    */

    if ($action === 'update') {

        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        $day = strtolower(trim($_POST['day'] ?? ''));
        $start = trim($_POST['start'] ?? '');
        $end = trim($_POST['end'] ?? '');
        $duration = (int) ($_POST['duration'] ?? 30);
        $branchId = (int) ($_POST['branch_id'] ?? 0);


        /*
        |--------------------------------------------------------------------------
        | Verify Schedule Ownership
        |--------------------------------------------------------------------------
        */

        $owner = $schedulesCollection->findOne([
            'schedule_id' => (int) $scheduleId,
            'doctor_id' => (int) $did,
        ]);

        if ($owner === null) {

            flash(
                'danger',
                'Invalid schedule selected.'
            );

        } elseif (
            !in_array($day, $validDays, true)
            || !valid_time($start)
            || !valid_time($end)
            || $start >= $end
            || $duration < 5
            || $duration > 240
            || !doctor_branch_exists(
                $doctorBranchesCollection,
                (int) $did,
                $branchId
            )
        ) {

            flash(
                'danger',
                'Please enter valid schedule information.'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | Check Overlap
            |--------------------------------------------------------------------------
            */

            $overlap = $schedulesCollection->findOne([
                'doctor_id' => (int) $did,
                'branch_id' => (int) $branchId,
                'day_of_week' => $day,
                'schedule_id' => ['$ne' => (int) $scheduleId],
                'start_time' => ['$lt' => $end],
                'end_time' => ['$gt' => $start],
            ]);

            if ($overlap !== null) {
                flash(
                    'danger',
                    'This schedule overlaps with another existing schedule.'
                );
            } else {
                $result = $schedulesCollection->updateOne(
                    [
                        'schedule_id' => (int) $scheduleId,
                        'doctor_id' => (int) $did,
                    ],
                    [
                        '$set' => [
                            'branch_id' => (int) $branchId,
                            'day_of_week' => $day,
                            'start_time' => $start,
                            'end_time' => $end,
                            'slot_duration' => (int) $duration,
                        ],
                    ]
                );

                if ($result->getMatchedCount() !== 1) {
                    flash('danger', 'Unable to update the selected schedule.');
                    header('Location: schedule.php');
                    exit;
                }

                audit(
                    (int) $_SESSION['user_id'],
                    'Updated schedule',
                    'schedule',
                    $scheduleId
                );


                flash('success', 'Working schedule updated successfully.');
            }
        }


        header('Location: schedule.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | DELETE SCHEDULE
    |--------------------------------------------------------------------------
    */

    if ($action === 'delete') {

        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);


        $owner = $schedulesCollection->findOne([
            'schedule_id' => (int) $scheduleId,
            'doctor_id' => (int) $did,
        ]);

        if ($owner === null) {
            flash('danger', 'Invalid schedule selected.');
        } else {
            $result = $schedulesCollection->deleteOne([
                'schedule_id' => (int) $scheduleId,
                'doctor_id' => (int) $did,
            ]);

            if ($result->getDeletedCount() === 1) {
                audit(
                    (int) $_SESSION['user_id'],
                    'Deleted schedule',
                    'schedule',
                    $scheduleId
                );

                flash('success', 'Working schedule deleted successfully.');
            } else {
                flash('danger', 'Unable to delete schedule.');
            }
        }


        header('Location: schedule.php');
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Get Existing Schedules
|--------------------------------------------------------------------------
*/

$rows = [];

$scheduleCursor = $schedulesCollection->find(
    ['doctor_id' => (int) $did]
);

$dayOrder = array_flip($validDays);

foreach ($scheduleCursor as $scheduleDocument) {
    $row = $scheduleDocument->getArrayCopy();

    $branchDocument = $branchesCollection->findOne([
        'branch_id' => (int) ($row['branch_id'] ?? 0),
    ]);

    $row['branch_name'] = $branchDocument['branch_name'] ?? null;
    $row['clinic_name'] = '';

    if ($branchDocument !== null) {
        $clinicDocument = $clinicsCollection->findOne([
            'clinic_id' => (int) ($branchDocument['clinic_id'] ?? 0),
        ]);

        $row['clinic_name'] = $clinicDocument['name'] ?? '';
    }

    $rows[] = $row;
}

usort($rows, function ($a, $b) use ($dayOrder) {
    $dayA = $dayOrder[$a['day_of_week'] ?? ''] ?? 999;
    $dayB = $dayOrder[$b['day_of_week'] ?? ''] ?? 999;

    if ($dayA !== $dayB) {
        return $dayA <=> $dayB;
    }

    return strcmp(
        (string) ($a['start_time'] ?? ''),
        (string) ($b['start_time'] ?? '')
    );
});


/*
|--------------------------------------------------------------------------
| Group By Day
|--------------------------------------------------------------------------
*/

$grouped = [];

foreach ($rows as $row) {

    $grouped[$row['day_of_week']][] = $row;
}


/*
|--------------------------------------------------------------------------
| IMPORTANT
|--------------------------------------------------------------------------
| Header is loaded only AFTER all redirect operations.
|--------------------------------------------------------------------------
*/

require '../includes/header.php';

?>


<style>

.schedule-hero {
    background: linear-gradient(
        135deg,
        #eef7ff 0%,
        #f7fbff 100%
    );

    border: 1px solid #dcecf9;
    border-radius: 22px;
    padding: 28px;
}


.schedule-icon {
    width: 52px;
    height: 52px;
    border-radius: 15px;

    display: flex;
    align-items: center;
    justify-content: center;

    background: #e3f2fd;
    color: #0d6efd;

    font-size: 22px;
}


.schedule-form-card,
.schedule-table-card {

    border: 0;
    border-radius: 20px;

    box-shadow:
        0 8px 30px rgba(20, 55, 90, .07);
}


.schedule-table-card {
    overflow: hidden;
}


.schedule-table thead th {

    background: #f7fafc;

    color: #64748b;

    font-size: 13px;
    font-weight: 700;

    text-transform: uppercase;

    letter-spacing: .04em;

    border-bottom: 1px solid #e8eef5;

    padding: 15px;

    white-space: nowrap;
}


.schedule-table tbody td {

    padding: 16px 15px;

    vertical-align: middle;

    border-color: #edf2f7;
}


.day-badge {

    display: inline-flex;

    align-items: center;

    padding: 7px 11px;

    border-radius: 10px;

    background: #eef6ff;

    color: #1769aa;

    font-weight: 700;

    font-size: 13px;
}


.time-box {

    font-weight: 700;

    color: #1e293b;
}


.duration-badge {

    display: inline-flex;

    padding: 6px 10px;

    border-radius: 9px;

    background: #f1f5f9;

    color: #475569;

    font-size: 13px;

    font-weight: 600;
}


.branch-name {

    font-weight: 600;

    color: #334155;
}


.clinic-name {

    color: #94a3b8;

    font-size: 12px;

    margin-top: 2px;
}


.empty-schedule {

    padding: 55px 20px;

    text-align: center;
}


.empty-schedule-icon {

    width: 70px;
    height: 70px;

    border-radius: 20px;

    background: #f1f5f9;

    display: flex;

    align-items: center;
    justify-content: center;

    margin: 0 auto 18px;

    font-size: 28px;

    color: #94a3b8;
}


.schedule-count {

    font-size: 13px;

    color: #64748b;
}


.modal-content {

    border: 0;

    border-radius: 20px;

    box-shadow:
        0 20px 60px rgba(15, 23, 42, .18);
}


.modal-header {

    border-bottom:
        1px solid #edf2f7;
}


.modal-footer {

    border-top:
        1px solid #edf2f7;
}

</style>


<!-- =====================================================
     PAGE HEADER
     ===================================================== -->

<div class="schedule-hero mb-4">

    <div
        class="d-flex
               flex-column
               flex-md-row
               align-items-md-center
               justify-content-between
               gap-3"
    >

        <div class="d-flex align-items-center gap-3">

            <div class="schedule-icon">

                <i class="bi bi-calendar2-week"></i>

            </div>


            <div>

                <span class="eyebrow">

                    <i class="bi bi-clock"></i>

                    Availability

                </span>


                <h1 class="h3 fw-bold mt-2 mb-1">

                    Working Schedule

                </h1>


                <p class="text-secondary mb-0">

                    Manage your recurring working hours and appointment slot duration.

                </p>

            </div>

        </div>


        <div class="text-md-end">

            <div class="fw-bold fs-4">

                <?= count($rows) ?>

            </div>


            <div class="schedule-count">

                Active schedules

            </div>

        </div>

    </div>

</div>


<!-- =====================================================
     ADD SCHEDULE
     ===================================================== -->

<div class="card schedule-form-card p-4 mb-4">

    <div class="mb-4">

        <h2 class="h5 fw-bold mb-1">

            <i class="bi bi-plus-circle me-2"></i>

            Add Working Hours

        </h2>


        <p class="text-secondary small mb-0">

            Set the recurring availability for a specific branch.

        </p>

    </div>


    <?php if (!$branches): ?>

        <div class="alert alert-warning mb-0">

            <i class="bi bi-exclamation-triangle me-2"></i>

            No branch is assigned to your doctor profile yet.
            Please contact the administrator.

        </div>

    <?php else: ?>


        <form
            method="post"
            class="row g-3"
        >

            <?= csrf_field() ?>


            <input
                type="hidden"
                name="action"
                value="add"
            >


            <!-- DAY -->

            <div class="col-sm-6 col-lg-2">

                <label class="form-label fw-semibold">

                    Day

                </label>


                <select
                    class="form-select"
                    name="day"
                    required
                >

                    <?php foreach ($dayLabels as $value => $label): ?>

                        <option
                            value="<?= h($value) ?>"
                        >

                            <?= h($label) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- START -->

            <div class="col-sm-6 col-lg-2">

                <label class="form-label fw-semibold">

                    Start Time

                </label>


                <input
                    class="form-control"
                    type="time"
                    name="start"
                    required
                >

            </div>


            <!-- END -->

            <div class="col-sm-6 col-lg-2">

                <label class="form-label fw-semibold">

                    End Time

                </label>


                <input
                    class="form-control"
                    type="time"
                    name="end"
                    required
                >

            </div>


            <!-- DURATION -->

            <div class="col-sm-6 col-lg-2">

                <label class="form-label fw-semibold">

                    Slot Duration

                </label>


                <div class="input-group">

                    <input
                        class="form-control"
                        type="number"
                        name="duration"
                        value="20"
                        min="5"
                        max="240"
                        required
                    >


                    <span class="input-group-text">

                        min

                    </span>

                </div>

            </div>


            <!-- BRANCH -->

            <div class="col-lg-3">

                <label class="form-label fw-semibold">

                    Clinic / Branch

                </label>


                <select
                    class="form-select"
                    name="branch_id"
                    required
                >

                    <option value="">

                        Select branch

                    </option>


                    <?php foreach ($branches as $b): ?>

                        <option
                            value="<?= (int)$b['branch_id'] ?>"
                        >

                            <?= h(
                                $b['clinic_name']
                                . ' — '
                                . $b['branch_name']
                            ) ?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <!-- BUTTON -->

            <div class="col-lg-1 d-flex align-items-end">

                <button
                    type="submit"
                    class="btn btn-primary w-100"
                >

                    <i class="bi bi-plus-lg"></i>

                </button>

            </div>

        </form>


    <?php endif; ?>

</div>


<!-- =====================================================
     SCHEDULE TABLE
     ===================================================== -->

<div class="card schedule-table-card">

    <div class="p-4 border-bottom">

        <div
            class="d-flex
                   flex-column
                   flex-md-row
                   justify-content-between
                   align-items-md-center
                   gap-2"
        >

            <div>

                <h2 class="h5 fw-bold mb-1">

                    Your Weekly Schedule

                </h2>


                <p class="text-secondary small mb-0">

                    These recurring hours are used to determine appointment availability.

                </p>

            </div>


            <span class="badge text-bg-light">

                <?= count($rows) ?>

                schedule<?= count($rows) !== 1 ? 's' : '' ?>

            </span>

        </div>

    </div>


    <div class="table-responsive">

        <table class="table schedule-table mb-0">

            <thead>

                <tr>

                    <th>Day</th>

                    <th>Working Hours</th>

                    <th>Slot Duration</th>

                    <th>Clinic / Branch</th>

                    <th class="text-end">Action</th>

                </tr>

            </thead>


            <tbody>


            <?php if ($rows): ?>


                <?php foreach ($rows as $r): ?>


                    <tr>


                        <!-- DAY -->

                        <td>

                            <span class="day-badge">

                                <?= h(
                                    $dayLabels[$r['day_of_week']]
                                    ?? ucfirst($r['day_of_week'])
                                ) ?>

                            </span>

                        </td>


                        <!-- HOURS -->

                        <td>

                            <div class="time-box">

                                <?= h(
                                    substr($r['start_time'], 0, 5)
                                ) ?>

                                <span class="text-secondary mx-1">

                                    –

                                </span>

                                <?= h(
                                    substr($r['end_time'], 0, 5)
                                ) ?>

                            </div>

                        </td>


                        <!-- DURATION -->

                        <td>

                            <span class="duration-badge">

                                <i class="bi bi-stopwatch me-1"></i>

                                <?= (int)$r['slot_duration'] ?>

                                min

                            </span>

                        </td>


                        <!-- BRANCH -->

                        <td>

                            <div class="branch-name">

                                <?= h(
                                    $r['branch_name'] ?? '—'
                                ) ?>

                            </div>


                            <?php if (!empty($r['clinic_name'])): ?>

                                <div class="clinic-name">

                                    <?= h(
                                        $r['clinic_name']
                                    ) ?>

                                </div>

                            <?php endif; ?>

                        </td>


                        <!-- ACTION -->

                        <td class="text-end">

                            <div
                                class="d-flex
                                       justify-content-end
                                       gap-2"
                            >


                                <!-- EDIT -->

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editSchedule<?= (int)$r['schedule_id'] ?>"
                                    title="Edit schedule"
                                >

                                    <i class="bi bi-pencil"></i>

                                </button>


                                <!-- DELETE -->

                                <form
                                    method="post"
                                    onsubmit="return confirm('Are you sure you want to delete this schedule?');"
                                >

                                    <?= csrf_field() ?>


                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >


                                    <input
                                        type="hidden"
                                        name="schedule_id"
                                        value="<?= (int)$r['schedule_id'] ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Delete schedule"
                                    >

                                        <i class="bi bi-trash"></i>

                                    </button>

                                </form>

                            </div>

                        </td>

                    </tr>


                    <!-- =================================================
                         EDIT MODAL
                         ================================================= -->

                    <div
                        class="modal fade"
                        id="editSchedule<?= (int)$r['schedule_id'] ?>"
                        tabindex="-1"
                        aria-hidden="true"
                    >

                        <div
                            class="modal-dialog modal-dialog-centered"
                        >

                            <div class="modal-content">


                                <form method="post">


                                    <div class="modal-header">

                                        <div>

                                            <h5 class="modal-title fw-bold">

                                                Edit Schedule

                                            </h5>


                                            <div class="small text-secondary">

                                                Update your recurring working hours.

                                            </div>

                                        </div>


                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                        ></button>

                                    </div>


                                    <div class="modal-body">

                                        <?= csrf_field() ?>


                                        <input
                                            type="hidden"
                                            name="action"
                                            value="update"
                                        >


                                        <input
                                            type="hidden"
                                            name="schedule_id"
                                            value="<?= (int)$r['schedule_id'] ?>"
                                        >


                                        <!-- DAY -->

                                        <div class="mb-3">

                                            <label
                                                class="form-label fw-semibold"
                                            >

                                                Day

                                            </label>


                                            <select
                                                class="form-select"
                                                name="day"
                                                required
                                            >

                                                <?php foreach (
                                                    $dayLabels
                                                    as $value => $label
                                                ): ?>

                                                    <option
                                                        value="<?= h($value) ?>"
                                                        <?= $r['day_of_week'] === $value
                                                            ? 'selected'
                                                            : '' ?>
                                                    >

                                                        <?= h($label) ?>

                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>


                                        <div class="row g-3">


                                            <!-- START -->

                                            <div class="col-6">

                                                <label
                                                    class="form-label fw-semibold"
                                                >

                                                    Start Time

                                                </label>


                                                <input
                                                    class="form-control"
                                                    type="time"
                                                    name="start"
                                                    value="<?= h(
                                                        substr(
                                                            $r['start_time'],
                                                            0,
                                                            5
                                                        )
                                                    ) ?>"
                                                    required
                                                >

                                            </div>


                                            <!-- END -->

                                            <div class="col-6">

                                                <label
                                                    class="form-label fw-semibold"
                                                >

                                                    End Time

                                                </label>


                                                <input
                                                    class="form-control"
                                                    type="time"
                                                    name="end"
                                                    value="<?= h(
                                                        substr(
                                                            $r['end_time'],
                                                            0,
                                                            5
                                                        )
                                                    ) ?>"
                                                    required
                                                >

                                            </div>


                                            <!-- DURATION -->

                                            <div class="col-6">

                                                <label
                                                    class="form-label fw-semibold"
                                                >

                                                    Slot Duration

                                                </label>


                                                <input
                                                    class="form-control"
                                                    type="number"
                                                    name="duration"
                                                    value="<?= (int)$r['slot_duration'] ?>"
                                                    min="5"
                                                    max="240"
                                                    required
                                                >

                                            </div>


                                            <!-- BRANCH -->

                                            <div class="col-6">

                                                <label
                                                    class="form-label fw-semibold"
                                                >

                                                    Branch

                                                </label>


                                                <select
                                                    class="form-select"
                                                    name="branch_id"
                                                    required
                                                >

                                                    <?php foreach (
                                                        $branches
                                                        as $b
                                                    ): ?>

                                                        <option
                                                            value="<?= (int)$b['branch_id'] ?>"
                                                            <?= (int)$r['branch_id']
                                                                === (int)$b['branch_id']
                                                                ? 'selected'
                                                                : '' ?>
                                                        >

                                                            <?= h(
                                                                $b['clinic_name']
                                                                . ' — '
                                                                . $b['branch_name']
                                                            ) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </div>

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

                                            <i class="bi bi-check2 me-1"></i>

                                            Save Changes

                                        </button>

                                    </div>


                                </form>

                            </div>

                        </div>

                    </div>


                <?php endforeach; ?>


            <?php else: ?>


                <tr>

                    <td colspan="5">

                        <div class="empty-schedule">

                            <div class="empty-schedule-icon">

                                <i class="bi bi-calendar-x"></i>

                            </div>


                            <h5 class="fw-bold">

                                No Working Schedule

                            </h5>


                            <p class="text-secondary mb-0">

                                Add your first working schedule above
                                to start accepting appointments.

                            </p>

                        </div>

                    </td>

                </tr>


            <?php endif; ?>


            </tbody>

        </table>

    </div>

</div>


<?php require '../includes/footer.php'; ?>