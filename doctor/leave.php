<?php

$page_title = 'Leave Management';
$asset_prefix = '../';

require '../includes/header.php';
require_role('doctor');

$did = get_doctor_id((int)$_SESSION['user_id']);

$leaveCollection = $mongoDb->selectCollection('leave_days');
$appointmentsCollection = $mongoDb->selectCollection('appointments');

function nextLeaveId($mongoDb): int
{
    $lastLeave = $mongoDb->leave_days->findOne(
        [],
        [
            'sort' => ['leave_id' => -1],
            'projection' => ['leave_id' => 1]
        ]
    );

    $maxLeaveId = $lastLeave !== null
        ? (int) ($lastLeave['leave_id'] ?? 0)
        : 0;

    $mongoDb->counters->updateOne(
        ['_id' => 'leave_id'],
        ['$max' => ['sequence_value' => $maxLeaveId]],
        ['upsert' => true]
    );

    $counter = $mongoDb->counters->findOneAndUpdate(
        ['_id' => 'leave_id'],
        ['$inc' => ['sequence_value' => 1]],
        [
            'upsert' => true,
            'returnDocument' =>
                MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
        ]
    );

    if ($counter === null) {
        throw new RuntimeException('Unable to generate leave ID.');
    }

    return (int) $counter['sequence_value'];
}


/* =========================================================
   ADD / UPDATE / DELETE LEAVE
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';


    /* =====================================================
       ADD LEAVE
    ===================================================== */

    if ($action === 'add') {
        $leaveDate = trim($_POST['leave_date'] ?? '');
        $reason    = trim($_POST['reason'] ?? '');

        if (!valid_date($leaveDate)) {
            flash('danger', 'Please select a valid leave date.');
        } elseif ($leaveDate < date('Y-m-d')) {
            flash('danger', 'Leave date cannot be in the past.');
        } elseif (strlen($reason) > 255) {
            flash('danger', 'Leave reason must not exceed 255 characters.');
        } else {
            $check = $leaveCollection->findOne([
                'doctor_id' => (int) $did,
                'leave_date' => $leaveDate,
            ]);

            if ($check !== null) {
                flash('danger', 'Leave is already scheduled for this date.');
            } else {
                $appointmentCount = $appointmentsCollection->countDocuments([
                    'doctor_id' => (int) $did,
                    'appointment_date' => $leaveDate,
                    'status' => [
                        '$nin' => ['Cancelled', 'No-show'],
                    ],
                ]);

                if ($appointmentCount > 0) {
                    flash(
                        'danger',
                        'You cannot add leave because active appointments already exist on this date.'
                    );
                } else {
                    $leaveId = nextLeaveId($mongoDb);

                    $leaveCollection->insertOne([
                        'leave_id' => $leaveId,
                        'doctor_id' => (int) $did,
                        'leave_date' => $leaveDate,
                        'reason' => $reason !== '' ? $reason : null,
                    ]);

                    audit(
                        (int) $_SESSION['user_id'],
                        'Added doctor leave',
                        'leave_day',
                        $leaveId
                    );

                    flash('success', 'Leave added successfully.');
                }
            }
        }

        header('Location: leave.php');
        exit;
    }


    /* =====================================================
       UPDATE LEAVE
    ===================================================== */

    if ($action === 'update') {
        $leaveId   = (int) ($_POST['leave_id'] ?? 0);
        $leaveDate = trim($_POST['leave_date'] ?? '');
        $reason    = trim($_POST['reason'] ?? '');

        $owner = $leaveCollection->findOne([
            'leave_id' => $leaveId,
            'doctor_id' => (int) $did,
        ]);

        if ($owner === null) {
            flash('danger', 'Invalid leave selected.');
        } elseif (!valid_date($leaveDate)) {
            flash('danger', 'Please select a valid leave date.');
        } elseif ($leaveDate < date('Y-m-d')) {
            flash('danger', 'Leave date cannot be in the past.');
        } elseif (strlen($reason) > 255) {
            flash('danger', 'Leave reason must not exceed 255 characters.');
        } else {
            $duplicate = $leaveCollection->findOne([
                'doctor_id' => (int) $did,
                'leave_date' => $leaveDate,
                'leave_id' => ['$ne' => $leaveId],
            ]);

            if ($duplicate !== null) {
                flash('danger', 'Another leave already exists for this date.');
            } else {
                $appointmentCount = $appointmentsCollection->countDocuments([
                    'doctor_id' => (int) $did,
                    'appointment_date' => $leaveDate,
                    'status' => [
                        '$nin' => ['Cancelled', 'No-show'],
                    ],
                ]);

                if ($appointmentCount > 0) {
                    flash(
                        'danger',
                        'You cannot move leave to this date because active appointments already exist.'
                    );
                } else {
                    $result = $leaveCollection->updateOne(
                        [
                            'leave_id' => $leaveId,
                            'doctor_id' => (int) $did,
                        ],
                        [
                            '$set' => [
                                'leave_date' => $leaveDate,
                                'reason' => $reason !== '' ? $reason : null,
                            ],
                        ]
                    );

                    if ($result->getMatchedCount() === 1) {
                        audit(
                            (int) $_SESSION['user_id'],
                            'Updated doctor leave',
                            'leave_day',
                            $leaveId
                        );

                        flash('success', 'Leave updated successfully.');
                    } else {
                        flash('danger', 'Unable to update the selected leave.');
                    }
                }
            }
        }

        header('Location: leave.php');
        exit;
    }


    /* =====================================================
       DELETE LEAVE
    ===================================================== */

    if ($action === 'delete') {
        $leaveId = (int) ($_POST['leave_id'] ?? 0);

        $result = $leaveCollection->deleteOne([
            'leave_id' => $leaveId,
            'doctor_id' => (int) $did,
        ]);

        if ($result->getDeletedCount() === 1) {
            audit(
                (int) $_SESSION['user_id'],
                'Deleted doctor leave',
                'leave_day',
                $leaveId
            );

            flash('success', 'Leave deleted successfully.');
        } else {
            flash('danger', 'Invalid leave selected.');
        }

        header('Location: leave.php');
        exit;
    }
}


/* =========================================================
   FETCH LEAVE LIST
========================================================= */

$leaves = [];

$leaveCursor = $leaveCollection->find(
    ['doctor_id' => (int) $did],
    [
        'sort' => ['leave_date' => 1],
    ]
);

foreach ($leaveCursor as $leaveDocument) {
    $leaves[] = $leaveDocument->getArrayCopy();
}


/* =========================================================
   COUNT UPCOMING LEAVES
========================================================= */

$upcomingCount = 0;

foreach ($leaves as $leave) {

    if ($leave['leave_date'] >= date('Y-m-d')) {
        $upcomingCount++;
    }
}

?>


<style>

/* =========================================================
   LEAVE PAGE
========================================================= */

.leave-hero {

    background: linear-gradient(
        135deg,
        #eef7ff 0%,
        #f8fbff 100%
    );

    border: 1px solid #dcecf9;

    border-radius: 22px;

    padding: 28px;
}


.leave-icon {

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


.leave-card {

    border: 0;

    border-radius: 20px;

    box-shadow:
        0 8px 30px rgba(20, 55, 90, .07);
}


.leave-table-card {

    border: 0;

    border-radius: 20px;

    overflow: hidden;

    box-shadow:
        0 8px 30px rgba(20, 55, 90, .07);
}


.leave-table thead th {

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


.leave-table tbody td {

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


.reason-text {

    color: #475569;

    max-width: 420px;

    white-space: normal;

}


.empty-leave {

    padding: 55px 20px;

    text-align: center;
}


.empty-leave-icon {

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


<!-- =========================================================
     PAGE HEADER
========================================================= -->

<div class="leave-hero mb-4">

    <div class="d-flex
                flex-column
                flex-md-row
                align-items-md-center
                justify-content-between
                gap-3">


        <div class="d-flex align-items-center gap-3">

            <div class="leave-icon">

                <i class="bi bi-calendar-x"></i>

            </div>


            <div>

                <span class="eyebrow">

                    <i class="bi bi-calendar-check"></i>

                    Availability

                </span>


                <h1 class="h3 fw-bold mt-2 mb-1">

                    Leave Management

                </h1>


                <p class="text-secondary mb-0">

                    Manage dates when you will not be available
                    for appointments.

                </p>

            </div>

        </div>


        <div class="text-md-end">

            <div class="fw-bold fs-4">

                <?= $upcomingCount ?>

            </div>


            <div class="small text-secondary">

                Upcoming leaves

            </div>

        </div>

    </div>

</div>



<!-- =========================================================
     ADD LEAVE
========================================================= -->

<div class="card leave-card p-4 mb-4">

    <div class="mb-4">

        <h2 class="h5 fw-bold mb-1">

            <i class="bi bi-plus-circle me-2"></i>

            Add Leave

        </h2>


        <p class="text-secondary small mb-0">

            Add a date when you will be unavailable for
            patient appointments.

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

                Leave Date

            </label>


            <input
                type="date"
                name="leave_date"
                class="form-control"
                min="<?= date('Y-m-d') ?>"
                required
            >

        </div>


        <!-- REASON -->

        <div class="col-md-6">

            <label class="form-label fw-semibold">

                Reason

                <span class="text-secondary fw-normal">

                    (Optional)

                </span>

            </label>


            <input
                type="text"
                name="reason"
                class="form-control"
                maxlength="255"
                placeholder="e.g. Personal leave, conference, vacation"
            >

        </div>


        <!-- BUTTON -->

        <div class="col-md-2 d-flex align-items-end">

            <button
                type="submit"
                class="btn btn-primary w-100"
            >

                <i class="bi bi-plus-lg me-1"></i>

                Add Leave

            </button>

        </div>

    </form>

</div>



<!-- =========================================================
     LEAVE LIST
========================================================= -->

<div class="card leave-table-card">

    <div class="p-4 border-bottom">

        <div class="d-flex
                    flex-column
                    flex-md-row
                    justify-content-between
                    align-items-md-center
                    gap-2">

            <div>

                <h2 class="h5 fw-bold mb-1">

                    Leave Schedule

                </h2>


                <p class="text-secondary small mb-0">

                    View and manage your scheduled leave dates.

                </p>

            </div>


            <span class="badge text-bg-light">

                <?= count($leaves) ?>

                leave<?= count($leaves) !== 1 ? 's' : '' ?>

            </span>

        </div>

    </div>


    <div class="table-responsive">

        <table class="table leave-table mb-0">

            <thead>

                <tr>

                    <th>Leave Date</th>

                    <th>Reason</th>

                    <th>Status</th>

                    <th class="text-end">

                        Action

                    </th>

                </tr>

            </thead>


            <tbody>


            <?php if ($leaves): ?>


                <?php foreach ($leaves as $leave): ?>


                    <?php

                    $isPast =
                        $leave['leave_date'] < date('Y-m-d');

                    $isToday =
                        $leave['leave_date'] === date('Y-m-d');

                    ?>


                    <tr>


                        <!-- DATE -->

                        <td>

                            <span class="date-badge">

                                <i class="bi bi-calendar-event"></i>

                                <?= h(
                                    date(
                                        'd M Y',
                                        strtotime(
                                            $leave['leave_date']
                                        )
                                    )
                                ) ?>

                            </span>

                        </td>


                        <!-- REASON -->

                        <td>

                            <?php if (!empty($leave['reason'])): ?>

                                <span class="reason-text">

                                    <?= h($leave['reason']) ?>

                                </span>

                            <?php else: ?>

                                <span class="text-secondary">

                                    No reason provided

                                </span>

                            <?php endif; ?>

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
                                    data-bs-target="#editLeave<?= (int)$leave['leave_id'] ?>"
                                    title="Edit leave"
                                >

                                    <i class="bi bi-pencil"></i>

                                </button>


                                <!-- DELETE -->

                                <form
                                    method="post"
                                    onsubmit="return confirm('Are you sure you want to delete this leave?');"
                                >

                                    <?= csrf_field() ?>


                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete"
                                    >


                                    <input
                                        type="hidden"
                                        name="leave_id"
                                        value="<?= (int)$leave['leave_id'] ?>"
                                    >


                                    <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        title="Delete leave"
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
                        id="editLeave<?= (int)$leave['leave_id'] ?>"
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

                                                Edit Leave

                                            </h5>


                                            <div class="small text-secondary">

                                                Update your leave information.

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
                                            name="leave_id"
                                            value="<?= (int)$leave['leave_id'] ?>"
                                        >


                                        <!-- DATE -->

                                        <div class="mb-3">

                                            <label
                                                class="form-label fw-semibold"
                                            >

                                                Leave Date

                                            </label>


                                            <input
                                                type="date"
                                                name="leave_date"
                                                class="form-control"
                                                min="<?= date('Y-m-d') ?>"
                                                value="<?= h($leave['leave_date']) ?>"
                                                required
                                            >

                                        </div>



                                        <!-- REASON -->

                                        <div>

                                            <label
                                                class="form-label fw-semibold"
                                            >

                                                Reason

                                            </label>


                                            <input
                                                type="text"
                                                name="reason"
                                                class="form-control"
                                                maxlength="255"
                                                value="<?= h($leave['reason'] ?? '') ?>"
                                                placeholder="Reason for leave"
                                            >

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

                    <td colspan="4">

                        <div class="empty-leave">

                            <div class="empty-leave-icon">

                                <i class="bi bi-calendar-x"></i>

                            </div>


                            <h5 class="fw-bold">

                                No Leave Scheduled

                            </h5>


                            <p class="text-secondary mb-0">

                                Add a leave date above when you
                                will be unavailable.

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