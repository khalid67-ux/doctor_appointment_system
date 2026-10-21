<?php

$page_title = 'Break Management';
$asset_prefix = '../';

require '../includes/header.php';
require_role('doctor');

$did = get_doctor_id((int)$_SESSION['user_id']);

$breaksCollection = $mongoDb->selectCollection('breaks');

function nextBreakId($mongoDb): int
{
    $lastBreak = $mongoDb->breaks->findOne(
        [],
        [
            'sort' => ['break_id' => -1],
            'projection' => ['break_id' => 1]
        ]
    );

    $maxBreakId = $lastBreak !== null
        ? (int) ($lastBreak['break_id'] ?? 0)
        : 0;

    $mongoDb->counters->updateOne(
        ['_id' => 'break_id'],
        ['$max' => ['sequence_value' => $maxBreakId]],
        ['upsert' => true]
    );

    $counter = $mongoDb->counters->findOneAndUpdate(
        ['_id' => 'break_id'],
        ['$inc' => ['sequence_value' => 1]],
        [
            'upsert' => true,
            'returnDocument' =>
                MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
        ]
    );

    if ($counter === null) {
        throw new RuntimeException('Unable to generate break ID.');
    }

    return (int) $counter['sequence_value'];
}


/* =========================================================
   ADD / UPDATE / DELETE BREAK
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';


    /* =====================================================
       ADD BREAK
    ===================================================== */

    if ($action === 'add') {

        $breakDate = trim($_POST['break_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime   = trim($_POST['end_time'] ?? '');


        /* Validation */

        if (
            !valid_date($breakDate) ||
            !valid_time($startTime) ||
            !valid_time($endTime)
        ) {

            flash('danger', 'Please enter valid break information.');

        } elseif ($breakDate < date('Y-m-d')) {

            flash('danger', 'Break date cannot be in the past.');

        } elseif ($startTime >= $endTime) {

            flash('danger', 'End time must be later than start time.');

        } else {

            /* Check overlapping break */
            $overlap = $breaksCollection->findOne([
                'doctor_id' => (int) $did,
                'break_date' => $breakDate,
                'start_time' => ['$lt' => $endTime],
                'end_time' => ['$gt' => $startTime],
            ]);

            if ($overlap !== null) {
                flash(
                    'danger',
                    'This break overlaps with an existing break.'
                );
            } else {
                $breakId = nextBreakId($mongoDb);

                $breaksCollection->insertOne([
                    'break_id' => $breakId,
                    'doctor_id' => (int) $did,
                    'break_date' => $breakDate,
                    'start_time' => $startTime,
                    'end_time' => $endTime,
                ]);

                audit(
                    (int) $_SESSION['user_id'],
                    'Added doctor break',
                    'break',
                    $breakId
                );

                flash('success', 'Break added successfully.');
            }
        }


        header('Location: breaks.php');
        exit;
    }


    /* =====================================================
       UPDATE BREAK
    ===================================================== */

    if ($action === 'update') {
        $breakId   = (int) ($_POST['break_id'] ?? 0);
        $breakDate = trim($_POST['break_date'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime   = trim($_POST['end_time'] ?? '');

        $owner = $breaksCollection->findOne([
            'break_id' => $breakId,
            'doctor_id' => (int) $did,
        ]);

        if ($owner === null) {
            flash('danger', 'Invalid break selected.');
        } elseif (!valid_date($breakDate)) {
            flash('danger', 'Please enter a valid break date.');
        } elseif ($breakDate < date('Y-m-d')) {
            flash('danger', 'Break date cannot be in the past.');
        } elseif (
            !valid_time($startTime) ||
            !valid_time($endTime)
        ) {
            flash('danger', 'Please enter valid break times.');
        } elseif ($startTime >= $endTime) {
            flash('danger', 'End time must be later than start time.');
        } else {
            $overlap = $breaksCollection->findOne([
                'doctor_id' => (int) $did,
                'break_date' => $breakDate,
                'break_id' => ['$ne' => $breakId],
                'start_time' => ['$lt' => $endTime],
                'end_time' => ['$gt' => $startTime],
            ]);

            if ($overlap !== null) {
                flash(
                    'danger',
                    'This break overlaps with another existing break.'
                );
            } else {
                $result = $breaksCollection->updateOne(
                    [
                        'break_id' => $breakId,
                        'doctor_id' => (int) $did,
                    ],
                    [
                        '$set' => [
                            'break_date' => $breakDate,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                        ],
                    ]
                );

                if ($result->getMatchedCount() === 1) {
                    audit(
                        (int) $_SESSION['user_id'],
                        'Updated doctor break',
                        'break',
                        $breakId
                    );

                    flash('success', 'Break updated successfully.');
                } else {
                    flash('danger', 'Unable to update the selected break.');
                }
            }
        }

        header('Location: breaks.php');
        exit;
    }


    /* =====================================================
       DELETE BREAK
    ===================================================== */

    if ($action === 'delete') {
        $breakId = (int) ($_POST['break_id'] ?? 0);

        $result = $breaksCollection->deleteOne([
            'break_id' => $breakId,
            'doctor_id' => (int) $did,
        ]);

        if ($result->getDeletedCount() === 1) {
            audit(
                (int) $_SESSION['user_id'],
                'Deleted doctor break',
                'break',
                $breakId
            );

            flash('success', 'Break deleted successfully.');
        } else {
            flash('danger', 'Invalid break selected.');
        }

        header('Location: breaks.php');
        exit;
    }
}


/* =========================================================
   GET BREAKS
========================================================= */

$breaks = [];

$breakCursor = $breaksCollection->find(
    ['doctor_id' => (int) $did],
    [
        'sort' => [
            'break_date' => 1,
            'start_time' => 1,
        ],
    ]
);

foreach ($breakCursor as $breakDoc) {
    $breaks[] = $breakDoc->getArrayCopy();
}


/* =========================================================
   COUNT UPCOMING BREAKS
========================================================= */

$upcomingCount = 0;

foreach ($breaks as $break) {

    if ($break['break_date'] >= date('Y-m-d')) {
        $upcomingCount++;
    }
}

?>


<style>

.break-hero {
    background: linear-gradient(
        135deg,
        #eef7ff 0%,
        #f8fbff 100%
    );

    border: 1px solid #dcecf9;

    border-radius: 22px;

    padding: 28px;
}


.break-icon {
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


.break-card {
    border: 0;

    border-radius: 20px;

    box-shadow:
        0 8px 30px rgba(20, 55, 90, .07);
}


.break-table-card {
    border: 0;

    border-radius: 20px;

    overflow: hidden;

    box-shadow:
        0 8px 30px rgba(20, 55, 90, .07);
}


.break-table thead th {
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


.break-table tbody td {
    padding: 16px 15px;

    vertical-align: middle;

    border-color: #edf2f7;
}


.date-badge {
    display: inline-flex;

    align-items: center;

    gap: 7px;

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


.break-duration {
    display: inline-flex;

    align-items: center;

    padding: 6px 10px;

    border-radius: 9px;

    background: #f1f5f9;

    color: #475569;

    font-size: 13px;

    font-weight: 600;
}


.empty-break {
    padding: 55px 20px;

    text-align: center;
}


.empty-break-icon {
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


.modal-content {
    border: 0;

    border-radius: 20px;

    box-shadow:
        0 20px 60px rgba(15, 23, 42, .18);
}


.modal-header {
    border-bottom: 1px solid #edf2f7;
}


.modal-footer {
    border-top: 1px solid #edf2f7;
}

</style>


<!-- =====================================================
     PAGE HEADER
===================================================== -->

<div class="break-hero mb-4">

    <div class="d-flex
                flex-column
                flex-md-row
                align-items-md-center
                justify-content-between
                gap-3">

        <div class="d-flex align-items-center gap-3">

            <div class="break-icon">

                <i class="bi bi-cup-hot"></i>

            </div>


            <div>

                <span class="eyebrow">

                    <i class="bi bi-clock-history"></i>

                    Availability

                </span>


                <h1 class="h3 fw-bold mt-2 mb-1">

                    Break Management

                </h1>


                <p class="text-secondary mb-0">

                    Add breaks when you are temporarily unavailable
                    during your working hours.

                </p>

            </div>

        </div>


        <div class="text-md-end">

            <div class="fw-bold fs-4">

                <?= $upcomingCount ?>

            </div>


            <div class="small text-secondary">

                Upcoming breaks

            </div>

        </div>

    </div>

</div>



<!-- =====================================================
     ADD BREAK
===================================================== -->

<div class="card break-card p-4 mb-4">

    <div class="mb-4">

        <h2 class="h5 fw-bold mb-1">

            <i class="bi bi-plus-circle me-2"></i>

            Add Break

        </h2>


        <p class="text-secondary small mb-0">

            Set a date and time when appointment slots
            should temporarily become unavailable.

        </p>

    </div>


    <form method="post" class="row g-3">

        <?= csrf_field() ?>


        <input
            type="hidden"
            name="action"
            value="add"
        >


        <!-- DATE -->

        <div class="col-md-4">

            <label class="form-label fw-semibold">

                Break Date

            </label>


            <input
                type="date"
                name="break_date"
                class="form-control"
                min="<?= date('Y-m-d') ?>"
                required
            >

        </div>


        <!-- START -->

        <div class="col-md-3">

            <label class="form-label fw-semibold">

                Start Time

            </label>


            <input
                type="time"
                name="start_time"
                class="form-control"
                required
            >

        </div>


        <!-- END -->

        <div class="col-md-3">

            <label class="form-label fw-semibold">

                End Time

            </label>


            <input
                type="time"
                name="end_time"
                class="form-control"
                required
            >

        </div>


        <!-- BUTTON -->

        <div class="col-md-2 d-flex align-items-end">

            <button
                type="submit"
                class="btn btn-primary w-100"
            >

                <i class="bi bi-plus-lg me-1"></i>

                Add Break

            </button>

        </div>

    </form>

</div>



<!-- =====================================================
     BREAK LIST
===================================================== -->

<div class="card break-table-card">

    <div class="p-4 border-bottom">

        <div class="d-flex
                    flex-column
                    flex-md-row
                    justify-content-between
                    align-items-md-center
                    gap-2">

            <div>

                <h2 class="h5 fw-bold mb-1">

                    Break Schedule

                </h2>


                <p class="text-secondary small mb-0">

                    Manage your scheduled unavailable periods.

                </p>

            </div>


            <span class="badge text-bg-light">

                <?= count($breaks) ?>

                break<?= count($breaks) !== 1 ? 's' : '' ?>

            </span>

        </div>

    </div>


    <div class="table-responsive">

        <table class="table break-table mb-0">

            <thead>

                <tr>

                    <th>Date</th>

                    <th>Break Time</th>

                    <th>Duration</th>

                    <th>Status</th>

                    <th class="text-end">

                        Action

                    </th>

                </tr>

            </thead>


            <tbody>


            <?php if ($breaks): ?>


                <?php foreach ($breaks as $b): ?>


                    <?php

                    $start = new DateTime(
                        $b['start_time']
                    );

                    $end = new DateTime(
                        $b['end_time']
                    );

                    $minutes =
                        ($end->getTimestamp() -
                         $start->getTimestamp()) / 60;

                    $isPast =
                        $b['break_date'] < date('Y-m-d');

                    $isToday =
                        $b['break_date'] === date('Y-m-d');

                    ?>


                    <tr>


                        <!-- DATE -->

                        <td>

                            <span class="date-badge">

                                <i class="bi bi-calendar-event"></i>

                                <?= h(
                                    date(
                                        'd M Y',
                                        strtotime($b['break_date'])
                                    )
                                ) ?>

                            </span>

                        </td>


                        <!-- TIME -->

                        <td>

                            <div class="time-box">

                                <?= h(
                                    substr(
                                        $b['start_time'],
                                        0,
                                        5
                                    )
                                ) ?>

                                <span class="text-secondary mx-1">

                                    –

                                </span>

                                <?= h(
                                    substr(
                                        $b['end_time'],
                                        0,
                                        5
                                    )
                                ) ?>

                            </div>

                        </td>


                        <!-- DURATION -->

                        <td>

                            <span class="break-duration">

                                <i class="bi bi-stopwatch me-1"></i>

                                <?= (int)$minutes ?> min

                            </span>

                        </td>


                        <!-- STATUS -->

                        <td>

                            <?php if ($isPast): ?>

                                <span class="badge text-bg-secondary">

                                    Past

                                </span>

                            <?php elseif ($isToday): ?>

                                <span class="badge text-bg-warning">

                                    Today

                                </span>

                            <?php else: ?>

                                <span class="badge text-bg-success">

                                    Upcoming

                                </span>

                            <?php endif; ?>

                        </td>


                        <!-- ACTION -->

                        <td class="text-end">

                            <div class="d-flex
                                        justify-content-end
                                        gap-2">


                                <!-- EDIT -->

                                <button
                                    type="button"
                                    class="btn btn-sm btn-outline-primary"
                                    data-bs-toggle="modal"
                                    data-bs-target="#editBreak<?= (int)$b['break_id'] ?>"
                                    title="Edit break"
                                >

                                    <i class="bi bi-pencil"></i>

                                </button>


                                <!-- DELETE -->

                                <form
                                    method="post"
                                    onsubmit="return confirm('Are you sure you want to delete this break?');"
                                >

                                    <?= csrf_field() ?>


                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >


                                    <input
                                        type="hidden"
                                        name="break_id"
                                        value="<?= (int)$b['break_id'] ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Delete break"
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
                        id="editBreak<?= (int)$b['break_id'] ?>"
                        tabindex="-1"
                        aria-hidden="true"
                    >

                        <div
                            class="modal-dialog
                                   modal-dialog-centered"
                        >

                            <div class="modal-content">

                                <form method="post">


                                    <div class="modal-header">

                                        <div>

                                            <h5 class="modal-title fw-bold">

                                                Edit Break

                                            </h5>


                                            <div class="small text-secondary">

                                                Update your unavailable period.

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
                                            name="break_id"
                                            value="<?= (int)$b['break_id'] ?>"
                                        >


                                        <!-- DATE -->

                                        <div class="mb-3">

                                            <label
                                                class="form-label fw-semibold"
                                            >

                                                Break Date

                                            </label>


                                            <input
                                                type="date"
                                                name="break_date"
                                                class="form-control"
                                                min="<?= date('Y-m-d') ?>"
                                                value="<?= h($b['break_date']) ?>"
                                                required
                                            >

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
                                                    type="time"
                                                    name="start_time"
                                                    class="form-control"
                                                    value="<?= h(
                                                        substr(
                                                            $b['start_time'],
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
                                                    type="time"
                                                    name="end_time"
                                                    class="form-control"
                                                    value="<?= h(
                                                        substr(
                                                            $b['end_time'],
                                                            0,
                                                            5
                                                        )
                                                    ) ?>"
                                                    required
                                                >

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

                        <div class="empty-break">

                            <div class="empty-break-icon">

                                <i class="bi bi-cup-hot"></i>

                            </div>


                            <h5 class="fw-bold">

                                No Breaks Scheduled

                            </h5>


                            <p class="text-secondary mb-0">

                                Add a break above when you need
                                temporary unavailability.

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