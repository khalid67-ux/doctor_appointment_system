<?php

$page_title = 'Clinics & Branches';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

require_role('admin');

$clinicsCollection = $mongoDb->selectCollection('clinics');
$branchesCollection = $mongoDb->selectCollection('branches');
$doctorBranchesCollection = $mongoDb->selectCollection('doctor_branches');
$schedulesCollection = $mongoDb->selectCollection('schedules');
$appointmentsCollection = $mongoDb->selectCollection('appointments');
$countersCollection = $mongoDb->selectCollection('counters');
$applicationsCollection = $mongoDb->selectCollection('clinic_applications');
$clinicAccountsCollection = $mongoDb->selectCollection('clinic_accounts');

function next_clinic_branch_id($countersCollection, string $counterName): int
{
    $result = $countersCollection->findOneAndUpdate(
        ['_id' => $counterName],
        ['$inc' => ['sequence_value' => 1]],
        [
            'upsert' => true,
            'returnDocument' =>
                MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
        ]
    );

    if ($result === null) {
        throw new RuntimeException('Unable to generate a record ID.');
    }

    return (int) $result['sequence_value'];
}

$lastClinic = $clinicsCollection->findOne(
    [],
    ['sort' => ['clinic_id' => -1], 'projection' => ['clinic_id' => 1]]
);
$countersCollection->updateOne(
    ['_id' => 'clinic_id'],
    ['$max' => ['sequence_value' => (int) ($lastClinic['clinic_id'] ?? 0)]],
    ['upsert' => true]
);

$lastBranch = $branchesCollection->findOne(
    [],
    ['sort' => ['branch_id' => -1], 'projection' => ['branch_id' => 1]]
);
$countersCollection->updateOne(
    ['_id' => 'branch_id'],
    ['$max' => ['sequence_value' => (int) ($lastBranch['branch_id'] ?? 0)]],
    ['upsert' => true]
);

/*
|--------------------------------------------------------------------------
| POST Actions
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'review_clinic_application') {
        $applicationId = trim($_POST['application_id'] ?? '');
        $decision = $_POST['decision'] ?? '';

        if (
            $applicationId === '' ||
            !in_array($decision, ['approve', 'reject'], true)
        ) {
            flash('danger', 'Invalid application request.');
            header('Location: clinics.php');
            exit;
        }

        try {
            $application = $applicationsCollection->findOne([
                '_id' => new MongoDB\BSON\ObjectId($applicationId),
                'status' => 'Pending'
            ]);

            if ($application === null) {
                flash('warning', 'Application not found or already reviewed.');
                header('Location: clinics.php');
                exit;
            }

            if ($decision === 'reject') {
                $applicationsCollection->updateOne(
                    [
                        '_id' => $application['_id'],
                        'status' => 'Pending'
                    ],
                    ['$set' => [
                        'status' => 'Rejected',
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'reviewed_by' => (int) $_SESSION['user_id']
                    ]]
                );

                audit(
                    (int) $_SESSION['user_id'],
                    'Rejected clinic application',
                    'clinic_application',
                    $applicationId
                );

                flash('success', 'Clinic application rejected.');
                header('Location: clinics.php');
                exit;
            }

            $name = trim((string) ($application['name'] ?? ''));
            $address = trim((string) ($application['address'] ?? ''));
            $contact = trim((string) ($application['contact'] ?? ''));
            $email = strtolower(trim((string) ($application['email'] ?? '')));
            $passwordHash = (string) ($application['password_hash'] ?? '');

            if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
                || $passwordHash === '') {
                throw new RuntimeException('Application details are incomplete.');
            }

            if ($clinicsCollection->findOne([
                'name' => [
                    '$regex' => '^' . preg_quote($name, '/') . '$',
                    '$options' => 'i'
                ]
            ]) !== null) {
                throw new RuntimeException('A clinic with this name already exists.');
            }

            if ($clinicAccountsCollection->findOne(['email' => $email]) !== null) {
                throw new RuntimeException('This email already has a clinic account.');
            }

            $lock = $applicationsCollection->updateOne(
                [
                    '_id' => $application['_id'],
                    'status' => 'Pending'
                ],
                ['$set' => ['status' => 'Processing']]
            );

            if ($lock->getModifiedCount() !== 1) {
                throw new RuntimeException('This application is already being reviewed.');
            }

            $clinicId = next_clinic_branch_id($countersCollection, 'clinic_id');
            $branchId = next_clinic_branch_id($countersCollection, 'branch_id');

            try {
                $clinicsCollection->insertOne([
                    'clinic_id' => $clinicId,
                    'name' => $name,
                    'address' => $address,
                    'contact' => $contact
                ]);

                $branchesCollection->insertOne([
                    'branch_id' => $branchId,
                    'clinic_id' => $clinicId,
                    'branch_name' => 'Main Branch',
                    'address' => $address
                ]);

                $clinicAccountsCollection->insertOne([
                    'clinic_id' => $clinicId,
                    'email' => $email,
                    'password_hash' => $passwordHash,
                    'status' => 'Active',
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                $applicationsCollection->updateOne(
                    [
                        '_id' => $application['_id'],
                        'status' => 'Processing'
                    ],
                    ['$set' => [
                        'status' => 'Approved',
                        'clinic_id' => $clinicId,
                        'reviewed_at' => date('Y-m-d H:i:s'),
                        'reviewed_by' => (int) $_SESSION['user_id']
                    ]]
                );
            } catch (Throwable $e) {
                $clinicAccountsCollection->deleteOne(['clinic_id' => $clinicId]);
                $branchesCollection->deleteOne(['branch_id' => $branchId]);
                $clinicsCollection->deleteOne(['clinic_id' => $clinicId]);

                $applicationsCollection->updateOne(
                    [
                        '_id' => $application['_id'],
                        'status' => 'Processing'
                    ],
                    ['$set' => ['status' => 'Pending']]
                );

                throw $e;
            }

            audit(
                (int) $_SESSION['user_id'],
                'Approved clinic application',
                'clinic',
                $clinicId
            );

            $mailConfig = require __DIR__ . '/../config/mail.php';

            $mail = new PHPMailer\PHPMailer\PHPMailer(true);

            $mail->isSMTP();
            $mail->Host = $mailConfig['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $mailConfig['username'];
            $mail->Password = $mailConfig['password'];
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = (int) $mailConfig['port'];

            $mail->setFrom(
                $mailConfig['from_email'],
                $mailConfig['from_name']
            );

            $mail->addAddress($email, $name);
            $mail->isHTML(true);
            $mail->Subject = 'Your MediBook Clinic Application Is Approved';

            $mail->Body = '
                <h2>Congratulations!</h2>
                <p>Dear ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ',</p>
                <p>Your clinic registration has been approved by MediBook.</p>
                <p><strong>Clinic:</strong> ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</p>
                <p><strong>Registered email:</strong> ' . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . '</p>
                <p>You can now log in to your clinic account.</p>
                <p>Regards,<br>MediBook Team</p>
            ';

            $mail->AltBody = "Your MediBook clinic application has been approved. Registered email: {$email}";

            $mail->send();

            flash('success', 'Clinic approved and Main Branch created.');
        } catch (Throwable $e) {
            error_log('Clinic application review failed: ' . $e->getMessage());
            flash('danger', $e->getMessage());
        }

        header('Location: clinics.php');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Update Clinic
    |--------------------------------------------------------------------------
    */
    if ($action === 'update_clinic') {

        $clinic_id = (int)($_POST['clinic_id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $address   = trim($_POST['address'] ?? '');
        $contact   = trim($_POST['contact'] ?? '');

        if ($clinic_id <= 0 || $name === '') {
            flash('danger', 'Invalid clinic information.');
            header('Location: clinics.php');
            exit;
        }

        // Duplicate name check excluding current clinic
        $duplicate = $clinicsCollection->findOne([
            'clinic_id' => ['$ne' => $clinic_id],
            'name' => [
                '$regex' => '^' . preg_quote($name, '/') . '$',
                '$options' => 'i',
            ],
        ]);

        if ($duplicate !== null) {
            flash('warning', 'Another clinic already uses this name.');
            header('Location: clinics.php');
            exit;
        }

        $result = $clinicsCollection->updateOne(
            ['clinic_id' => $clinic_id],
            ['$set' => [
                'name' => $name,
                'address' => $address,
                'contact' => $contact,
            ]]
        );

        if ($result->getMatchedCount() === 0) {
            flash('danger', 'Clinic not found.');
            header('Location: clinics.php');
            exit;
        }

        audit(
            (int)$_SESSION['user_id'],
            'Updated clinic',
            'clinic',
            $clinic_id
        );

        flash('success', 'Clinic updated successfully.');

        header('Location: clinics.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Delete Clinic
    |--------------------------------------------------------------------------
    */
    if ($action === 'delete_clinic') {

        $clinic_id = (int)($_POST['clinic_id'] ?? 0);

        if ($clinic_id <= 0) {
            flash('danger', 'Invalid clinic.');
            header('Location: clinics.php');
            exit;
        }

        $branch_count = $branchesCollection->countDocuments([
            'clinic_id' => $clinic_id,
        ]);

        if ($branch_count > 0) {
            flash(
                'warning',
                'This clinic cannot be deleted because it still has branch(es).'
            );

            header('Location: clinics.php');
            exit;
        }

        $result = $clinicsCollection->deleteOne([
            'clinic_id' => $clinic_id,
        ]);

        if ($result->getDeletedCount() === 0) {
            flash('danger', 'Clinic not found.');
            header('Location: clinics.php');
            exit;
        }

        audit(
            (int)$_SESSION['user_id'],
            'Deleted clinic',
            'clinic',
            $clinic_id
        );

        flash('success', 'Clinic deleted successfully.');

        header('Location: clinics.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Update Branch
    |--------------------------------------------------------------------------
    */
    if ($action === 'update_branch') {

        $branch_id = (int)($_POST['branch_id'] ?? 0);
        $clinic_id = (int)($_POST['clinic_id'] ?? 0);
        $name      = trim($_POST['name'] ?? '');
        $address   = trim($_POST['address'] ?? '');

        if (
            $branch_id <= 0 ||
            $clinic_id <= 0 ||
            $name === ''
        ) {
            flash('danger', 'Invalid branch information.');
            header('Location: clinics.php');
            exit;
        }

        $clinic = $clinicsCollection->findOne([
            'clinic_id' => $clinic_id,
        ]);

        if ($clinic === null) {
            flash('danger', 'Selected clinic does not exist.');
            header('Location: clinics.php');
            exit;
        }

        $duplicate = $branchesCollection->findOne([
            'clinic_id' => $clinic_id,
            'branch_id' => ['$ne' => $branch_id],
            'branch_name' => [
                '$regex' => '^' . preg_quote($name, '/') . '$',
                '$options' => 'i',
            ],
        ]);

        if ($duplicate !== null) {
            flash(
                'warning',
                'Another branch with this name already exists in this clinic.'
            );

            header('Location: clinics.php');
            exit;
        }

        $result = $branchesCollection->updateOne(
            ['branch_id' => $branch_id],
            ['$set' => [
                'clinic_id' => $clinic_id,
                'branch_name' => $name,
                'address' => $address,
            ]]
        );

        if ($result->getMatchedCount() === 0) {
            flash('danger', 'Branch not found.');
            header('Location: clinics.php');
            exit;
        }

        audit(
            (int)$_SESSION['user_id'],
            'Updated branch',
            'branch',
            $branch_id
        );

        flash('success', 'Branch updated successfully.');

        header('Location: clinics.php');
        exit;
    }


    /*
    |--------------------------------------------------------------------------
    | Delete Branch
    |--------------------------------------------------------------------------
    */
    if ($action === 'delete_branch') {

        $branch_id = (int)($_POST['branch_id'] ?? 0);

        if ($branch_id <= 0) {
            flash('danger', 'Invalid branch.');
            header('Location: clinics.php');
            exit;
        }

        $doctor_count = $doctorBranchesCollection->countDocuments([
            'branch_id' => $branch_id,
        ]);

        if ($doctor_count > 0) {
            flash(
                'warning',
                'This branch cannot be deleted because doctors are assigned to it.'
            );

            header('Location: clinics.php');
            exit;
        }

        $schedule_count = $schedulesCollection->countDocuments([
            'branch_id' => $branch_id,
        ]);

        if ($schedule_count > 0) {
            flash(
                'warning',
                'This branch cannot be deleted because schedules exist for it.'
            );

            header('Location: clinics.php');
            exit;
        }

        $appointment_count = $appointmentsCollection->countDocuments([
            'branch_id' => $branch_id,
        ]);

        if ($appointment_count > 0) {
            flash(
                'warning',
                'This branch cannot be deleted because appointments exist for it.'
            );

            header('Location: clinics.php');
            exit;
        }

        $result = $branchesCollection->deleteOne([
            'branch_id' => $branch_id,
        ]);

        if ($result->getDeletedCount() === 0) {
            flash('danger', 'Branch not found.');
            header('Location: clinics.php');
            exit;
        }

        audit(
            (int)$_SESSION['user_id'],
            'Deleted branch',
            'branch',
            $branch_id
        );

        flash('success', 'Branch deleted successfully.');

        header('Location: clinics.php');
        exit;
    }
}


/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

$search = trim($_GET['search'] ?? '');

function clinic_branch_matches(string $value, string $search): bool
{
    return $search === '' || stripos($value, $search) !== false;
}

/*
|--------------------------------------------------------------------------
| Clinics
|--------------------------------------------------------------------------
*/

$clinics = [];

$clinicCursor = $clinicsCollection->find(
    [],
    ['sort' => ['name' => 1]]
);

foreach ($clinicCursor as $clinicDocument) {
    $clinic = $clinicDocument->getArrayCopy();
    $clinic_id = (int) ($clinic['clinic_id'] ?? 0);

    $clinic['branch_count'] = $branchesCollection->countDocuments([
        'clinic_id' => $clinic_id,
    ]);

    $searchText = implode(' ', [
        (string) ($clinic['name'] ?? ''),
        (string) ($clinic['address'] ?? ''),
        (string) ($clinic['contact'] ?? ''),
    ]);

    if (clinic_branch_matches($searchText, $search)) {
        $clinics[] = $clinic;
    }
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
    $branch_id = (int) ($branch['branch_id'] ?? 0);
    $clinic_id = (int) ($branch['clinic_id'] ?? 0);

    $clinicDocument = $clinicsCollection->findOne([
        'clinic_id' => $clinic_id,
    ]);

    if ($clinicDocument === null) {
        continue;
    }

    $clinic = $clinicDocument->getArrayCopy();
    $branch['clinic_name'] = $clinic['name'] ?? '';
    $branch['doctor_count'] = $doctorBranchesCollection->countDocuments([
        'branch_id' => $branch_id,
    ]);

    $searchText = implode(' ', [
        (string) ($branch['branch_name'] ?? ''),
        (string) ($branch['address'] ?? ''),
        (string) ($branch['clinic_name'] ?? ''),
    ]);

    if (clinic_branch_matches($searchText, $search)) {
        $branches[] = $branch;
    }
}


/*
|--------------------------------------------------------------------------
| Statistics
|--------------------------------------------------------------------------
*/

$total_clinics = $clinicsCollection->countDocuments([]);
$total_branches = $branchesCollection->countDocuments([]);
$total_assignments = $doctorBranchesCollection->countDocuments([]);

$doctorIds = [];

$assignmentCursor = $doctorBranchesCollection->find([]);

foreach ($assignmentCursor as $assignment) {
    if (isset($assignment['doctor_id'])) {
        $doctorIds[(string) $assignment['doctor_id']] = true;
    }
}

$total_doctors_with_branches = count($doctorIds);

$clinicApplications = $applicationsCollection->find(
    [],
    ['sort' => ['created_at' => -1]]
);

require '../includes/header.php';

?>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

    <div>
        <span class="eyebrow">
            <i class="bi bi-buildings"></i>
            Administration
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            Clinics & Branches
        </h1>

        <p class="text-secondary mb-0">
            Manage healthcare facilities, branches and their operational locations.
        </p>
    </div>

    <a href="doctor-branches.php" class="btn btn-outline-primary">
        <i class="bi bi-diagram-3 me-1"></i>
        Doctor Branch Assignments
    </a>

</div>


<!-- Statistics -->

<div class="row g-3 mb-4">

    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 p-3">
            <div class="text-secondary small">
                Total Clinics
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_clinics)?>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 p-3">
            <div class="text-secondary small">
                Total Branches
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_branches)?>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 p-3">
            <div class="text-secondary small">
                Branch Assignments
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_assignments)?>
            </div>
        </div>
    </div>

    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100 p-3">
            <div class="text-secondary small">
                Doctors with Branch
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total_doctors_with_branches)?>
            </div>
        </div>
    </div>

</div>


<!-- Search -->

<div class="card border-0 shadow-sm p-3 mb-4">

    <form method="get" class="row g-2">

        <div class="col-md-10">

            <div class="input-group">

                <span class="input-group-text bg-white">
                    <i class="bi bi-search"></i>
                </span>

                <input
                    type="text"
                    name="search"
                    value="<?=h($search)?>"
                    class="form-control"
                    placeholder="Search clinics, branches, addresses..."
                >

            </div>

        </div>

        <div class="col-md-2">

            <button class="btn btn-primary w-100">
                Search
            </button>

        </div>

    </form>

</div>


<!-- Clinic Applications -->

<div class="card border-0 shadow-sm mb-4 overflow-hidden">
    <div class="card-header bg-white py-3">
        <h5 class="fw-bold mb-1">Clinic Applications</h5>
        <small class="text-secondary">
            Review clinic registration requests.
        </small>
    </div>

    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead>
                <tr>
                    <th>Clinic</th>
                    <th>Type</th>
                    <th>Address</th>
                    <th>Contact</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>

            <tbody>
            <?php
            $hasApplications = false;

            foreach ($clinicApplications as $app):
                $hasApplications = true;
                $appId = (string) $app['_id'];
                $status = (string) ($app['status'] ?? 'Pending');
            ?>
                <tr>
                    <td><?= h($app['name'] ?? '') ?></td>
                    <td><?= h($app['institution_type'] ?? '') ?></td>
                    <td><?= h($app['address'] ?? '') ?></td>
                    <td><?= h($app['contact'] ?? '') ?></td>
                    <td><?= h($app['email'] ?? '') ?></td>
                    <td>
                        <span class="badge text-bg-<?=
                            $status === 'Approved' ? 'success' :
                            ($status === 'Rejected' ? 'danger' :
                            ($status === 'Processing' ? 'info' : 'warning'))
                        ?>">
                            <?= h($status) ?>
                        </span>
                    </td>
                    <td>
                    <?php if ($status === 'Pending'): ?>
                        <div class="d-flex gap-2">
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"
                                       value="review_clinic_application">
                                <input type="hidden" name="application_id"
                                       value="<?= h($appId) ?>">
                                <input type="hidden" name="decision"
                                       value="approve">
                                <button type="submit"
                                        class="btn btn-sm btn-success"
                                        onclick="return confirm('Approve this clinic application?')">
                                    Approve
                                </button>
                            </form>

                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action"
                                       value="review_clinic_application">
                                <input type="hidden" name="application_id"
                                       value="<?= h($appId) ?>">
                                <input type="hidden" name="decision"
                                       value="reject">
                                <button type="submit"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="return confirm('Reject this clinic application?')">
                                    Reject
                                </button>
                            </form>
                        </div>
                    <?php else: ?>
                        <span class="text-secondary">Reviewed</span>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$hasApplications): ?>
                <tr>
                    <td colspan="7" class="text-center text-secondary py-4">
                        No clinic applications found.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>


<!-- Clinics -->

<div class="card border-0 shadow-sm mb-4 overflow-hidden">

    <div class="card-header bg-white py-3">

        <div class="d-flex justify-content-between align-items-center">

            <div>
                <h5 class="fw-bold mb-1">
                    Clinics
                </h5>

                <small class="text-secondary">
                    <?=count($clinics)?> clinic(s) shown
                </small>
            </div>

        </div>

    </div>

    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>
                    <th>Clinic</th>
                    <th>Address</th>
                    <th>Contact</th>
                    <th>Branches</th>
                    <th class="text-end">Actions</th>
                </tr>

            </thead>

            <tbody>

                <?php foreach ($clinics as $c): ?>

                    <tr>

                        <td>
                            <strong>
                                <?=h($c['name'])?>
                            </strong>
                        </td>

                        <td>
                            <?=h($c['address'] ?: '—')?>
                        </td>

                        <td>
                            <?=h($c['contact'] ?: '—')?>
                        </td>

                        <td>

                            <span class="badge text-bg-light border">
                                <?=number_format((int)$c['branch_count'])?>
                            </span>

                        </td>

                        <td class="text-end">

                            <button
                                class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal"
                                data-bs-target="#editClinic<?=$c['clinic_id']?>"
                            >
                                <i class="bi bi-pencil"></i>
                            </button>

                            <?php if ((int)$c['branch_count'] === 0): ?>

                                <form
                                    method="post"
                                    class="d-inline"
                                    onsubmit="return confirm('Delete this clinic?');"
                                >

                                    <?=csrf_field()?>

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete_clinic"
                                    >

                                    <input
                                        type="hidden"
                                        name="clinic_id"
                                        value="<?=$c['clinic_id']?>"
                                    >

                                    <button
                                        class="btn btn-sm btn-outline-danger"
                                        title="Delete"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>

                                </form>

                            <?php endif; ?>

                        </td>

                    </tr>

                    <!-- Edit Clinic Modal -->

                    <div
                        class="modal fade"
                        id="editClinic<?=$c['clinic_id']?>"
                        tabindex="-1"
                    >

                        <div class="modal-dialog">

                            <div class="modal-content">

                                <form method="post">

                                    <?=csrf_field()?>

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update_clinic"
                                    >

                                    <input
                                        type="hidden"
                                        name="clinic_id"
                                        value="<?=$c['clinic_id']?>"
                                    >

                                    <div class="modal-header">

                                        <h5 class="modal-title">
                                            Edit Clinic
                                        </h5>

                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                        ></button>

                                    </div>

                                    <div class="modal-body">

                                        <div class="mb-3">

                                            <label class="form-label">
                                                Clinic Name
                                            </label>

                                            <input
                                                type="text"
                                                name="name"
                                                class="form-control"
                                                value="<?=h($c['name'])?>"
                                                required
                                            >

                                        </div>

                                        <div class="mb-3">

                                            <label class="form-label">
                                                Address
                                            </label>

                                            <input
                                                type="text"
                                                name="address"
                                                class="form-control"
                                                value="<?=h($c['address'])?>"
                                            >

                                        </div>

                                        <div class="mb-3">

                                            <label class="form-label">
                                                Contact
                                            </label>

                                            <input
                                                type="text"
                                                name="contact"
                                                class="form-control"
                                                value="<?=h($c['contact'])?>"
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

                                        <button class="btn btn-primary">
                                            Save Changes
                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>


                <?php if (!$clinics): ?>

                    <tr>

                        <td colspan="5">

                            <div class="empty-state p-4 text-center">

                                <i class="bi bi-building fs-2 d-block mb-2"></i>

                                No clinics found.

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- Branches -->

<div class="card border-0 shadow-sm overflow-hidden">

    <div class="card-header bg-white py-3">

        <h5 class="fw-bold mb-1">
            Branches
        </h5>

        <small class="text-secondary">
            <?=count($branches)?> branch(es) shown
        </small>

    </div>

    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>
                    <th>Clinic</th>
                    <th>Branch</th>
                    <th>Address</th>
                    <th>Doctors</th>
                    <th class="text-end">Actions</th>
                </tr>

            </thead>

            <tbody>

                <?php foreach ($branches as $b): ?>

                    <tr>

                        <td>
                            <span class="badge text-bg-light border">
                                <?=h($b['clinic_name'])?>
                            </span>
                        </td>

                        <td>
                            <strong>
                                <?=h($b['branch_name'])?>
                            </strong>
                        </td>

                        <td>
                            <?=h($b['address'] ?: '—')?>
                        </td>

                        <td>

                            <span class="badge text-bg-light border">
                                <?=number_format((int)$b['doctor_count'])?>
                            </span>

                        </td>

                        <td class="text-end">

                            <button
                                class="btn btn-sm btn-outline-primary"
                                data-bs-toggle="modal"
                                data-bs-target="#editBranch<?=$b['branch_id']?>"
                            >
                                <i class="bi bi-pencil"></i>
                            </button>

                            <?php if ((int)$b['doctor_count'] === 0): ?>

                                <form
                                    method="post"
                                    class="d-inline"
                                    onsubmit="return confirm('Delete this branch?');"
                                >

                                    <?=csrf_field()?>

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="delete_branch"
                                    >

                                    <input
                                        type="hidden"
                                        name="branch_id"
                                        value="<?=$b['branch_id']?>"
                                    >

                                    <button
                                        class="btn btn-sm btn-outline-danger"
                                        title="Delete"
                                    >
                                        <i class="bi bi-trash"></i>
                                    </button>

                                </form>

                            <?php endif; ?>

                        </td>

                    </tr>


                    <!-- Edit Branch Modal -->

                    <div
                        class="modal fade"
                        id="editBranch<?=$b['branch_id']?>"
                        tabindex="-1"
                    >

                        <div class="modal-dialog">

                            <div class="modal-content">

                                <form method="post">

                                    <?=csrf_field()?>

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="update_branch"
                                    >

                                    <input
                                        type="hidden"
                                        name="branch_id"
                                        value="<?=$b['branch_id']?>"
                                    >

                                    <div class="modal-header">

                                        <h5 class="modal-title">
                                            Edit Branch
                                        </h5>

                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                        ></button>

                                    </div>

                                    <div class="modal-body">

                                        <div class="mb-3">

                                            <label class="form-label">
                                                Clinic
                                            </label>

                                            <select
                                                class="form-select"
                                                name="clinic_id"
                                                required
                                            >

                                                <?php foreach ($clinics as $c): ?>

                                                    <option
                                                        value="<?=$c['clinic_id']?>"
                                                        <?=$c['clinic_id'] == $b['clinic_id'] ? 'selected' : ''?>
                                                    >
                                                        <?=h($c['name'])?>
                                                    </option>

                                                <?php endforeach; ?>

                                            </select>

                                        </div>

                                        <div class="mb-3">

                                            <label class="form-label">
                                                Branch Name
                                            </label>

                                            <input
                                                type="text"
                                                name="name"
                                                class="form-control"
                                                value="<?=h($b['branch_name'])?>"
                                                required
                                            >

                                        </div>

                                        <div class="mb-3">

                                            <label class="form-label">
                                                Address
                                            </label>

                                            <input
                                                type="text"
                                                name="address"
                                                class="form-control"
                                                value="<?=h($b['address'])?>"
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

                                        <button class="btn btn-primary">
                                            Save Changes
                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                    </div>

                <?php endforeach; ?>


                <?php if (!$branches): ?>

                    <tr>

                        <td colspan="5">

                            <div class="empty-state p-4 text-center">

                                <i class="bi bi-diagram-3 fs-2 d-block mb-2"></i>

                                No branches found.

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php require '../includes/footer.php'; ?>