<?php

$page_title = 'Doctor Branch Assignments';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');

$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');
$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');
$doctorBranchesCollection = $mongoDb->selectCollection('doctor_branches');
$schedulesCollection = $mongoDb->selectCollection('schedules');
$appointmentsCollection = $mongoDb->selectCollection('appointments');
$countersCollection = $mongoDb->selectCollection('counters');


/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'assign') {
        $doctor_id = (int) ($_POST['doctor_id'] ?? 0);
        $branch_id = (int) ($_POST['branch_id'] ?? 0);

        if ($doctor_id <= 0 || $branch_id <= 0) {
            flash('danger', 'Please select a valid doctor and branch.');
            header('Location: doctor-branches.php');
            exit;
        }

        $doctor = $doctorsCollection->findOne([
            'doctor_id' => $doctor_id,
            'verification_status' => 'Approved'
        ]);

        if (!$doctor) {
            flash('danger', 'Only approved doctors can be assigned to branches.');
            header('Location: doctor-branches.php');
            exit;
        }

        $doctorUser = $usersCollection->findOne([
            'user_id' => (int) ($doctor['user_id'] ?? 0),
            'status' => 'active'
        ]);

        if (!$doctorUser) {
            flash('danger', 'The doctor account is not active.');
            header('Location: doctor-branches.php');
            exit;
        }

        $branch = $branchesCollection->findOne([
            'branch_id' => $branch_id
        ]);

        if (!$branch) {
            flash('danger', 'Selected branch does not exist.');
            header('Location: doctor-branches.php');
            exit;
        }

        $clinic = $clinicsCollection->findOne([
            'clinic_id' => (int) ($branch['clinic_id'] ?? 0)
        ]);

        if (!$clinic) {
            flash('danger', 'The clinic associated with this branch was not found.');
            header('Location: doctor-branches.php');
            exit;
        }

        $existingAssignment = $doctorBranchesCollection->findOne([
            'doctor_id' => $doctor_id,
            'branch_id' => $branch_id
        ]);

        if ($existingAssignment) {
            flash('warning', 'This doctor is already assigned to this branch.');
            header('Location: doctor-branches.php');
            exit;
        }

        $doctorBranchesCollection->insertOne([
            'doctor_id' => $doctor_id,
            'branch_id' => $branch_id,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        audit(
            (int) $_SESSION['user_id'],
            'Assigned doctor to branch',
            'doctor',
            $doctor_id
        );

        create_notification(
            (int) $doctorUser['user_id'],
            'branch_assignment',
            'Branch Assignment',
            'You have been assigned to ' .
                $clinic['name'] . ' - ' .
                $branch['branch_name'] . '.'
        );

        flash('success', 'Doctor assigned to branch successfully.');
        header('Location: doctor-branches.php');
        exit;
    }

    if ($action === 'remove') {
        $doctor_id = (int) ($_POST['doctor_id'] ?? 0);
        $branch_id = (int) ($_POST['branch_id'] ?? 0);

        if ($doctor_id <= 0 || $branch_id <= 0) {
            flash('danger', 'Invalid doctor or branch.');
            header('Location: doctor-branches.php');
            exit;
        }

        $doctor = $doctorsCollection->findOne([
            'doctor_id' => $doctor_id
        ]);

        if (!$doctor) {
            flash('danger', 'Doctor not found.');
            header('Location: doctor-branches.php');
            exit;
        }

        $doctorUser = $usersCollection->findOne([
            'user_id' => (int) ($doctor['user_id'] ?? 0)
        ]);

        $assignment = $doctorBranchesCollection->findOne([
            'doctor_id' => $doctor_id,
            'branch_id' => $branch_id
        ]);

        if (!$assignment) {
            flash('warning', 'This doctor is not assigned to the selected branch.');
            header('Location: doctor-branches.php');
            exit;
        }

        $branch = $branchesCollection->findOne([
            'branch_id' => $branch_id
        ]);

        $clinic = $branch
            ? $clinicsCollection->findOne([
                'clinic_id' => (int) ($branch['clinic_id'] ?? 0)
            ])
            : null;

        if (!$branch || !$clinic) {
            flash('danger', 'The branch or clinic associated with this assignment was not found.');
            header('Location: doctor-branches.php');
            exit;
        }

        $scheduleCount = $schedulesCollection->countDocuments([
            'doctor_id' => $doctor_id,
            'branch_id' => $branch_id
        ]);

        if ($scheduleCount > 0) {
            flash(
                'warning',
                'This assignment cannot be removed because the doctor has schedules at this branch. Remove the related schedules first.'
            );
            header('Location: doctor-branches.php');
            exit;
        }

        $activeAppointmentCount = $appointmentsCollection->countDocuments([
            'doctor_id' => $doctor_id,
            'branch_id' => $branch_id,
            'status' => [
                '$nin' => ['Completed', 'Cancelled', 'No-show']
            ]
        ]);

        if ($activeAppointmentCount > 0) {
            flash(
                'warning',
                'This assignment cannot be removed because active appointments exist for this branch.'
            );
            header('Location: doctor-branches.php');
            exit;
        }

        $deleteResult = $doctorBranchesCollection->deleteOne([
            'doctor_id' => $doctor_id,
            'branch_id' => $branch_id
        ]);

        if ($deleteResult->getDeletedCount() !== 1) {
            flash('danger', 'Unable to remove doctor from branch.');
            header('Location: doctor-branches.php');
            exit;
        }

        audit(
            (int) $_SESSION['user_id'],
            'Removed doctor from branch',
            'doctor',
            $doctor_id
        );

        if ($doctorUser) {
            create_notification(
                (int) $doctorUser['user_id'],
                'branch_assignment',
                'Branch Assignment Removed',
                'Your assignment to ' .
                    $clinic['name'] . ' - ' .
                    $branch['branch_name'] .
                    ' has been removed.'
            );
        }

        flash('success', 'Doctor removed from branch successfully.');
        header('Location: doctor-branches.php');
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Search / Filter
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');
$clinic_filter = (int) ($_GET['clinic_id'] ?? 0);


/*
|--------------------------------------------------------------------------
| Approved Active Doctors
|--------------------------------------------------------------------------
*/

$doctors = [];

$doctorCursor = $doctorsCollection->find(
    ['verification_status' => 'Approved'],
    ['sort' => ['doctor_id' => 1]]
);

foreach ($doctorCursor as $doctorDocument) {
    $doctor = $doctorDocument->getArrayCopy();

    $user = $usersCollection->findOne([
        'user_id' => (int) ($doctor['user_id'] ?? 0),
        'status' => 'active'
    ]);

    if (!$user) {
        continue;
    }

    $doctors[] = [
        'doctor_id' => $doctor['doctor_id'] ?? 0,
        'name' => $user['name'] ?? '',
        'email' => $user['email'] ?? '',
        'qualification' => $doctor['qualification'] ?? '',
        'experience' => $doctor['experience'] ?? ''
    ];
}

/*
|--------------------------------------------------------------------------
| Branches
|--------------------------------------------------------------------------
*/

$branches = [];

$branchCursor = $branchesCollection->find(
    [],
    ['sort' => ['branch_name' => 1]]
);

foreach ($branchCursor as $branchDocument) {
    $branch = $branchDocument->getArrayCopy();

    $clinic = $clinicsCollection->findOne([
        'clinic_id' => (int) ($branch['clinic_id'] ?? 0)
    ]);

    if (!$clinic) {
        continue;
    }

    $branches[] = [
        'branch_id' => $branch['branch_id'] ?? 0,
        'branch_name' => $branch['branch_name'] ?? '',
        'clinic_id' => $branch['clinic_id'] ?? 0,
        'clinic_name' => $clinic['name'] ?? ''
    ];
}

/*
|--------------------------------------------------------------------------
| Clinic List
|--------------------------------------------------------------------------
*/

$clinic_list = [];

$clinicCursor = $clinicsCollection->find(
    [],
    ['sort' => ['name' => 1]]
);

foreach ($clinicCursor as $clinicDocument) {
    $clinic_list[] = $clinicDocument->getArrayCopy();
}

/*
|--------------------------------------------------------------------------
| Assignment List
|--------------------------------------------------------------------------
*/

$rows = [];

$assignmentCursor = $doctorBranchesCollection->find(
    [],
    ['sort' => ['branch_id' => 1, 'doctor_id' => 1]]
);

foreach ($assignmentCursor as $assignmentDocument) {
    $assignment = $assignmentDocument->getArrayCopy();

    $doctor = $doctorsCollection->findOne([
        'doctor_id' => (int) ($assignment['doctor_id'] ?? 0)
    ]);

    $branch = $branchesCollection->findOne([
        'branch_id' => (int) ($assignment['branch_id'] ?? 0)
    ]);

    if (!$doctor || !$branch) {
        continue;
    }

    $user = $usersCollection->findOne([
        'user_id' => (int) ($doctor['user_id'] ?? 0)
    ]);

    $clinic = $clinicsCollection->findOne([
        'clinic_id' => (int) ($branch['clinic_id'] ?? 0)
    ]);

    if (!$user || !$clinic) {
        continue;
    }

    if ($search !== '') {
        $searchable = [
            $user['name'] ?? '',
            $user['email'] ?? '',
            $clinic['name'] ?? '',
            $branch['branch_name'] ?? ''
        ];

        $matches = false;

        foreach ($searchable as $value) {
            if (stripos((string) $value, $search) !== false) {
                $matches = true;
                break;
            }
        }

        if (!$matches) {
            continue;
        }
    }

    if (
        $clinic_filter > 0 &&
        (int) ($branch['clinic_id'] ?? 0) !== $clinic_filter
    ) {
        continue;
    }

    $rows[] = [
        'doctor_id' => $assignment['doctor_id'] ?? 0,
        'branch_id' => $assignment['branch_id'] ?? 0,
        'doctor_name' => $user['name'] ?? '',
        'doctor_email' => $user['email'] ?? '',
        'clinic_name' => $clinic['name'] ?? '',
        'branch_name' => $branch['branch_name'] ?? '',
        'branch_address' => $branch['address'] ?? ''
    ];
}

usort($rows, function ($a, $b) {
    return [
        strtolower((string) $a['clinic_name']),
        strtolower((string) $a['branch_name']),
        strtolower((string) $a['doctor_name'])
    ] <=> [
        strtolower((string) $b['clinic_name']),
        strtolower((string) $b['branch_name']),
        strtolower((string) $b['doctor_name'])
    ];
});

/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_assignments = $doctorBranchesCollection->countDocuments([]);

$assignedDoctorIds = [];
$assignedBranchIds = [];

$assignmentStatsCursor = $doctorBranchesCollection->find(
    [],
    ['projection' => ['doctor_id' => 1, 'branch_id' => 1]]
);

foreach ($assignmentStatsCursor as $assignment) {
    if (isset($assignment['doctor_id'])) {
        $assignedDoctorIds[(string) $assignment['doctor_id']] = true;
    }

    if (isset($assignment['branch_id'])) {
        $assignedBranchIds[(string) $assignment['branch_id']] = true;
    }
}

$total_doctors = count($assignedDoctorIds);
$total_branches = count($assignedBranchIds);

$unassigned_doctors = 0;

foreach ($doctors as $doctor) {
    if (!isset($assignedDoctorIds[(string) $doctor['doctor_id']])) {
        $unassigned_doctors++;
    }
}

require '../includes/header.php';

?>

<!-- Header -->

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

    <div>

        <span class="eyebrow">
            <i class="bi bi-diagram-3"></i>
            Administration
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            Doctor Branch Assignments
        </h1>

        <p class="text-secondary mb-0">
            Assign approved doctors to clinics and branches.
        </p>

    </div>

    <a
        href="clinics.php"
        class="btn btn-outline-primary"
    >
        <i class="bi bi-buildings me-1"></i>
        Manage Clinics & Branches
    </a>

</div>


<!-- Statistics -->

<div class="row g-3 mb-4">

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Total Assignments
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_assignments)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Doctors Assigned
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_doctors)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Active Branches
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_branches)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Unassigned Doctors
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($unassigned_doctors)?>
            </div>

        </div>

    </div>

</div>


<!-- Assignment Form -->

<div class="card border-0 shadow-sm p-4 mb-4">

    <div class="d-flex align-items-center gap-2 mb-3">

        <div class="rounded-circle bg-primary-subtle text-primary p-2">
            <i class="bi bi-person-plus"></i>
        </div>

        <div>

            <h5 class="fw-bold mb-0">
                Assign Doctor to Branch
            </h5>

            <small class="text-secondary">
                Only approved and active doctors are available.
            </small>

        </div>

    </div>


    <?php if ($doctors && $branches): ?>

        <form method="post" class="row g-3">

            <?=csrf_field()?>

            <input
                type="hidden"
                name="action"
                value="assign"
            >


            <div class="col-lg-5">

                <label class="form-label fw-semibold">
                    Doctor
                </label>

                <select
                    class="form-select"
                    name="doctor_id"
                    required
                >

                    <option value="">
                        Select doctor
                    </option>

                    <?php foreach ($doctors as $d): ?>

                        <option value="<?=$d['doctor_id']?>">

                            Dr. <?=h($d['name'])?>

                            —
                            <?=h($d['qualification'] ?: 'Doctor')?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-lg-5">

                <label class="form-label fw-semibold">
                    Branch
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

                        <option value="<?=$b['branch_id']?>">

                            <?=h($b['clinic_name'])?>

                            —

                            <?=h($b['branch_name'])?>

                        </option>

                    <?php endforeach; ?>

                </select>

            </div>


            <div class="col-lg-2 d-flex align-items-end">

                <button class="btn btn-primary w-100">

                    <i class="bi bi-plus-lg me-1"></i>
                    Assign

                </button>

            </div>

        </form>

    <?php elseif (!$doctors): ?>

        <div class="alert alert-warning mb-0">

            <i class="bi bi-person-exclamation me-1"></i>

            No approved active doctors are currently available.

        </div>

    <?php elseif (!$branches): ?>

        <div class="alert alert-warning mb-0">

            <i class="bi bi-building-exclamation me-1"></i>

            No branches are available. Create a branch first.

        </div>

    <?php endif; ?>

</div>


<!-- Search / Filter -->

<div class="card border-0 shadow-sm p-3 mb-4">

    <form method="get" class="row g-2">

        <div class="col-lg-6">

            <div class="input-group">

                <span class="input-group-text bg-white">

                    <i class="bi bi-search"></i>

                </span>

                <input
                    type="text"
                    name="search"
                    value="<?=h($search)?>"
                    class="form-control"
                    placeholder="Search doctor, clinic or branch..."
                >

            </div>

        </div>


        <div class="col-lg-4">

            <select
                name="clinic_id"
                class="form-select"
            >

                <option value="">
                    All Clinics
                </option>

                <?php foreach ($clinic_list as $c): ?>

                    <option
                        value="<?= $c['clinic_id'] ?>"
                        <?= $clinic_filter === (int) $c['clinic_id'] ? 'selected' : '' ?>
                    >
                        <?= h($c['name']) ?>
                    </option>

                <?php endforeach; ?>

            </select>

        </div>


        <div class="col-lg-2">

            <button class="btn btn-primary w-100">
                Filter
            </button>

        </div>

    </form>

</div>


<!-- Assignment Table -->

<div class="card border-0 shadow-sm overflow-hidden">

    <div class="card-header bg-white py-3">

        <div class="d-flex justify-content-between align-items-center">

            <div>

                <h5 class="fw-bold mb-1">
                    Current Assignments
                </h5>

                <small class="text-secondary">
                    <?=count($rows)?> assignment(s) shown
                </small>

            </div>

        </div>

    </div>


    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>

                    <th>Doctor</th>

                    <th>Clinic</th>

                    <th>Branch</th>

                    <th>Address</th>

                    <th class="text-end">
                        Action
                    </th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($rows as $r): ?>

                    <tr>

                        <td>

                            <strong>
                                Dr. <?=h($r['doctor_name'])?>
                            </strong>

                            <br>

                            <small class="text-secondary">
                                <?=h($r['doctor_email'])?>
                            </small>

                        </td>


                        <td>

                            <span class="badge text-bg-light border">

                                <?=h($r['clinic_name'])?>

                            </span>

                        </td>


                        <td>

                            <strong>
                                <?=h($r['branch_name'])?>
                            </strong>

                        </td>


                        <td>

                            <span class="text-secondary">

                                <?=h($r['branch_address'] ?: '—')?>

                            </span>

                        </td>


                        <td class="text-end">

                            <form
                                method="post"
                                class="d-inline"
                                onsubmit="return confirm('Remove this doctor from this branch?');"
                            >

                                <?=csrf_field()?>

                                <input
                                    type="hidden"
                                    name="action"
                                    value="remove"
                                >

                                <input
                                    type="hidden"
                                    name="doctor_id"
                                    value="<?=$r['doctor_id']?>"
                                >

                                <input
                                    type="hidden"
                                    name="branch_id"
                                    value="<?=$r['branch_id']?>"
                                >

                                <button
                                    class="btn btn-sm btn-outline-danger"
                                    title="Remove Assignment"
                                >

                                    <i class="bi bi-person-dash"></i>

                                </button>

                            </form>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$rows): ?>

                    <tr>

                        <td colspan="5">

                            <div class="empty-state p-5 text-center">

                                <i class="bi bi-diagram-3 fs-1 d-block mb-3"></i>

                                <h5 class="fw-bold">
                                    No assignments found
                                </h5>

                                <p class="text-secondary mb-0">

                                    Try changing your search/filter
                                    or assign a doctor to a branch.

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