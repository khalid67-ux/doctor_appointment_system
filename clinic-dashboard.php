
<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/config/mongodb.php';
require_once __DIR__ . '/includes/functions.php';

$page_title = 'Clinic Dashboard';
$asset_prefix = '';
$clinic_dashboard_nav = true;

if (
    empty($_SESSION['clinic_logged_in']) ||
    empty($_SESSION['clinic_id'])
) {
    header('Location: clinic-login.php');
    exit;
}

$clinicId = (int) $_SESSION['clinic_id'];

$clinics = $mongoDb->selectCollection('clinics');
$branches = $mongoDb->selectCollection('branches');
$counters = $mongoDb->selectCollection('counters');

$clinic = $clinics->findOne(['clinic_id' => $clinicId]);

if (!$clinic) {
    $_SESSION = [];
    session_destroy();
    header('Location: clinic-login.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'update_profile') {
            $name = trim($_POST['name'] ?? '');
            $address = trim($_POST['address'] ?? '');
            $contact = trim($_POST['contact'] ?? '');

            if ($name === '') {
                throw new RuntimeException('Clinic name is required.');
            }

            $clinics->updateOne(
                ['clinic_id' => $clinicId],
                ['$set' => [
                    'name' => $name,
                    'address' => $address,
                    'contact' => $contact
                ]]
            );

            flash('success', 'Clinic profile updated successfully.');
        } elseif ($action === 'save_branch') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);
            $branchName = trim($_POST['branch_name'] ?? '');
            $address = trim($_POST['address'] ?? '');

            if ($branchName === '') {
                throw new RuntimeException('Branch name is required.');
            }

            $duplicate = $branches->findOne([
                'clinic_id' => $clinicId,
                'branch_id' => ['$ne' => $branchId],
                'branch_name' => [
                    '$regex' => '^' . preg_quote($branchName, '/') . '$',
                    '$options' => 'i'
                ]
            ]);

            if ($duplicate !== null) {
                throw new RuntimeException('This branch name already exists.');
            }

            if ($branchId > 0) {
                $result = $branches->updateOne(
                    [
                        'branch_id' => $branchId,
                        'clinic_id' => $clinicId
                    ],
                    ['$set' => [
                        'branch_name' => $branchName,
                        'address' => $address
                    ]]
                );

                if ($result->getMatchedCount() === 0) {
                    throw new RuntimeException('Branch not found.');
                }

                flash('success', 'Branch updated successfully.');
            } else {
                $lastBranch = $branches->findOne(
                    [],
                    [
                        'sort' => ['branch_id' => -1],
                        'projection' => ['branch_id' => 1]
                    ]
                );

                $counters->updateOne(
                    ['_id' => 'branch_id'],
                    ['$max' => [
                        'sequence_value' => (int) ($lastBranch['branch_id'] ?? 0)
                    ]],
                    ['upsert' => true]
                );

                $counter = $counters->findOneAndUpdate(
                    ['_id' => 'branch_id'],
                    ['$inc' => ['sequence_value' => 1]],
                    [
                        'upsert' => true,
                        'returnDocument' =>
                            MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                    ]
                );

                $branches->insertOne([
                    'branch_id' => (int) $counter['sequence_value'],
                    'clinic_id' => $clinicId,
                    'branch_name' => $branchName,
                    'address' => $address,
                    'created_at' => date('Y-m-d H:i:s')
                ]);

                flash('success', 'New branch added successfully.');
            }
        } elseif ($action === 'delete_branch') {
            $branchId = (int) ($_POST['branch_id'] ?? 0);

            $doctorBranches = $mongoDb->selectCollection('doctor_branches');
            $schedules = $mongoDb->selectCollection('schedules');
            $appointments = $mongoDb->selectCollection('appointments');

            if (
                $doctorBranches->countDocuments(['branch_id' => $branchId]) > 0 ||
                $schedules->countDocuments(['branch_id' => $branchId]) > 0 ||
                $appointments->countDocuments(['branch_id' => $branchId]) > 0
            ) {
                throw new RuntimeException(
                    'This branch has related doctors, schedules or appointments and cannot be deleted.'
                );
            }

            $result = $branches->deleteOne([
                'branch_id' => $branchId,
                'clinic_id' => $clinicId
            ]);

            if ($result->getDeletedCount() !== 1) {
                throw new RuntimeException('Branch not found.');
            }

            flash('success', 'Branch deleted successfully.');
        }

    } catch (Throwable $e) {
        error_log('Clinic dashboard error: ' . $e->getMessage());
        flash('danger', $e instanceof RuntimeException
            ? $e->getMessage()
            : 'Unable to complete the request. Please try again.');
    }

    header('Location: clinic-dashboard.php');
    exit;
}

$clinic = $clinics->findOne(['clinic_id' => $clinicId]);

$branchList = $branches->find(
    ['clinic_id' => $clinicId],
    ['sort' => ['branch_id' => 1]]
);

$branchCount = $branches->countDocuments(['clinic_id' => $clinicId]);
$search = trim($_GET['search'] ?? '');

$visibleBranches = [];
foreach ($branchList as $branch) {
    $branchName = (string) ($branch['branch_name'] ?? '');
    $branchAddress = (string) ($branch['address'] ?? '');

    if (
        $search === '' ||
        stripos($branchName, $search) !== false ||
        stripos($branchAddress, $search) !== false
    ) {
        $visibleBranches[] = $branch;
    }
}

$clinicAccount = $mongoDb->selectCollection('clinic_accounts')->findOne([
    'clinic_id' => $clinicId,
    'status' => 'Active'
]);
?>
<?php require_once __DIR__ . '/includes/header.php'; ?>

<style>
    body { background: #f4f7fb; color: #172033; }
    .hero { background: linear-gradient(120deg,#1d4ed8,#38bdf8); color: white; border-radius: 20px; padding: 30px; }
    .stat-card,.panel { background: #fff; border: 1px solid #edf0f6; border-radius: 16px; box-shadow: 0 5px 22px rgba(30,50,90,.04); }
    .stat-card { padding: 22px; height: 100%; }
    .stat-icon { width: 46px; height: 46px; display: grid; place-items: center; border-radius: 13px; background: #eff6ff; color: #2563eb; font-size: 1.3rem; }
    .panel { padding: 24px; }
    .branch-card { border: 1px solid #e7ecf3; border-radius: 13px; padding: 17px; height: 100%; background: #fff; }
    .muted { color: #738096; }
    .form-control { padding: .7rem .85rem; }
    .btn { border-radius: 9px; }

    /* Clinic dashboard hero sizing */
    .hero {
        padding: 30px;
        overflow: hidden;
    }

    .hero h1 {
        font-size: clamp(1.75rem, 2.5vw, 2.5rem);
        line-height: 1.2;
        margin-bottom: 12px;
        overflow-wrap: anywhere;
    }

    .hero p {
        color: #fff;
        font-size: 1rem;
        line-height: 1.6;
        margin-bottom: 0;
    }

    .hero .small {
        color: #fff !important;
    }

    @media (max-width: 767px) {
        .hero {
            padding: 22px;
        }

        .hero h1 {
            font-size: 1.75rem;
        }
    }
</style>

    <?php show_flash(); ?>

    <section class="hero mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="small text-white-50 mb-2">CLINIC MANAGEMENT PORTAL</div>
                <h1 class="h2 fw-bold mb-2">
                    Welcome, <?= h($clinic['name'] ?? 'Clinic') ?>
                </h1>
                <p class="mb-0">Manage your clinic information and branch locations.</p>
            </div>
            <button class="btn btn-light px-4" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="bi bi-pencil-square me-1"></i>Edit Profile
            </button>
        </div>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon mb-3"><i class="bi bi-diagram-3"></i></div>
                <div class="muted small">Total Branches</div>
                <div class="fs-2 fw-bold"><?= number_format($branchCount) ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon mb-3"><i class="bi bi-hospital"></i></div>
                <div class="muted small">Clinic ID</div>
                <div class="fs-2 fw-bold">#<?= h($clinicId) ?></div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon mb-3"><i class="bi bi-patch-check"></i></div>
                <div class="muted small">Account Status</div>
                <div class="fs-4 fw-bold text-success">Active</div>
            </div>
        </div>
        <div class="col-sm-6 col-xl-3">
            <div class="stat-card">
                <div class="stat-icon mb-3"><i class="bi bi-envelope"></i></div>
                <div class="muted small">Registered Email</div>
                <div class="fw-semibold text-break mt-2">
                    <?= h($clinicAccount['email'] ?? $_SESSION['clinic_email'] ?? '') ?>
                </div>
            </div>
        </div>
    </div>

    <section class="panel mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-3">
            <div>
                <h2 class="h5 fw-bold mb-1">Clinic Information</h2>
                <p class="muted small mb-0">Your registered clinic details</p>
            </div>
            <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#profileModal">
                <i class="bi bi-pencil me-1"></i>Edit Details
            </button>
        </div>
        <div class="row g-3">
            <div class="col-md-4">
                <div class="muted small">Clinic Name</div>
                <div class="fw-semibold"><?= h($clinic['name'] ?? '') ?></div>
            </div>
            <div class="col-md-4">
                <div class="muted small">Contact Number</div>
                <div class="fw-semibold"><?= h($clinic['contact'] ?? 'Not provided') ?></div>
            </div>
            <div class="col-md-4">
                <div class="muted small">Address</div>
                <div class="fw-semibold"><?= h($clinic['address'] ?? 'Not provided') ?></div>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
            <div>
                <h2 class="h5 fw-bold mb-1">Branch Management</h2>
                <p class="muted small mb-0">Add, update and manage your clinic branches.</p>
            </div>
            <button class="btn btn-primary px-3" data-bs-toggle="modal" data-bs-target="#branchModal"
                    onclick="resetBranchForm()">
                <i class="bi bi-plus-lg me-1"></i>Add Branch
            </button>
        </div>

        <form method="get" class="row g-2 mb-4">
            <div class="col-md-9">
                <input type="search" name="search" class="form-control"
                       value="<?= h($search) ?>" placeholder="Search by branch name or address...">
            </div>
            <div class="col-md-3 d-grid">
                <button class="btn btn-outline-primary"><i class="bi bi-search me-1"></i>Search Branches</button>
            </div>
        </form>

        <div class="row g-3">
            <?php foreach ($visibleBranches as $branch): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="branch-card">
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="stat-icon mb-3"><i class="bi bi-building"></i></div>
                            <span class="badge text-bg-light border">ID #<?= h($branch['branch_id'] ?? '') ?></span>
                        </div>
                        <h3 class="h6 fw-bold mb-2"><?= h($branch['branch_name'] ?? 'Branch') ?></h3>
                        <p class="muted small mb-3">
                            <i class="bi bi-geo-alt me-1"></i>
                            <?= h($branch['address'] ?? 'No address provided') ?>
                        </p>
                        <div class="d-flex gap-2 mt-auto">
                            <button type="button" class="btn btn-sm btn-outline-primary"
                                onclick='editBranch(
                                    <?= json_encode((int)($branch["branch_id"] ?? 0)) ?>,
                                    <?= json_encode((string)($branch["branch_name"] ?? ""), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
                                    <?= json_encode((string)($branch["address"] ?? ""), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP) ?>
                                )'>
                                <i class="bi bi-pencil me-1"></i>Edit
                            </button>
                            <form method="post" onsubmit="return confirm('Delete this branch? This cannot be undone.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete_branch">
                                <input type="hidden" name="branch_id" value="<?= h($branch['branch_id'] ?? 0) ?>">
                                <button class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-trash me-1"></i>Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$visibleBranches): ?>
                <div class="col-12">
                    <div class="text-center py-5">
                        <i class="bi bi-building display-5 text-secondary"></i>
                        <h3 class="h6 fw-bold mt-3">No branches found</h3>
                        <p class="muted mb-3">
                            <?= $search !== '' ? 'Try another search term.' : 'Add your first branch to get started.' ?>
                        </p>
                        <?php if ($search === ''): ?>
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#branchModal"
                                    onclick="resetBranchForm()">
                                <i class="bi bi-plus-lg me-1"></i>Add First Branch
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </section>
<!-- Edit Clinic Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="modal-header">
                <h5 class="modal-title fw-bold">Edit Clinic Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Clinic Name</label>
                    <input class="form-control" name="name" required value="<?= h($clinic['name'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Address</label>
                    <input class="form-control" name="address" value="<?= h($clinic['address'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label">Contact Number</label>
                    <input class="form-control" name="contact" value="<?= h($clinic['contact'] ?? '') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Changes</button>
            </div>
        </form>
    </div>
</div>

<!-- Add/Edit Branch Modal -->
<div class="modal fade" id="branchModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_branch">
            <input type="hidden" name="branch_id" id="branchId" value="0">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="branchModalTitle">Add New Branch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Branch Name</label>
                    <input class="form-control" name="branch_name" id="branchName"
                           placeholder="e.g. Dhanmondi Branch" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Branch Address</label>
                    <input class="form-control" name="address" id="branchAddress"
                           placeholder="Enter full branch address">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Branch</button>
            </div>
        </form>
    </div>
</div>

<script>
function resetBranchForm() {
    document.getElementById('branchId').value = '0';
    document.getElementById('branchName').value = '';
    document.getElementById('branchAddress').value = '';
    document.getElementById('branchModalTitle').textContent = 'Add New Branch';
}

function editBranch(id, name, address) {
    document.getElementById('branchId').value = id;
    document.getElementById('branchName').value = name;
    document.getElementById('branchAddress').value = address;
    document.getElementById('branchModalTitle').textContent = 'Edit Branch';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('branchModal')).show();
}
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
