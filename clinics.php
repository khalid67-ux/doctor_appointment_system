
<?php

$page_title = 'Our Clinics';
$navbar_type = 'clinics';
$asset_prefix = '';

require_once __DIR__ . '/config/mongodb.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';

/*
|--------------------------------------------------------------------------
| MongoDB Collections
|--------------------------------------------------------------------------
*/

$clinicsCollection = $mongoDb->selectCollection('clinics');
$branchesCollection = $mongoDb->selectCollection('branches');
$doctorBranchesCollection = $mongoDb->selectCollection('doctor_branches');

$search = trim((string)($_GET['search'] ?? ''));

$clinics = [];
$totalBranches = 0;
$totalDoctorAssignments = 0;
$databaseError = false;

try {
    /*
    |--------------------------------------------------------------------------
    | Load Clinics and Their Branches
    |--------------------------------------------------------------------------
    */

    $clinicCursor = $clinicsCollection->find(
        [],
        ['sort' => ['name' => 1]]
    );

    foreach ($clinicCursor as $clinicDocument) {
        $clinic = $clinicDocument->getArrayCopy();

        $clinicId = (int)($clinic['clinic_id'] ?? 0);

        $clinic['name'] = (string)($clinic['name'] ?? 'Unnamed Clinic');
        $clinic['address'] = (string)($clinic['address'] ?? '');
        $clinic['contact'] = (string)($clinic['contact'] ?? '');

        $clinic['branches'] = [];
        $clinic['branch_count'] = 0;
        $clinic['doctor_count'] = 0;

        /*
        |----------------------------------------------------------------------
        | Find Branches for This Clinic
        |----------------------------------------------------------------------
        */

        $branchCursor = $branchesCollection->find(
            ['clinic_id' => $clinicId],
            ['sort' => ['branch_name' => 1]]
        );

        foreach ($branchCursor as $branchDocument) {
            $branch = $branchDocument->getArrayCopy();

            $branchId = (int)($branch['branch_id'] ?? 0);

            $branch['branch_name'] =
                (string)($branch['branch_name'] ?? 'Unnamed Branch');

            $branch['address'] =
                (string)($branch['address'] ?? '');

            $branch['contact'] =
                (string)($branch['contact'] ?? '');

            $branch['doctor_count'] = $doctorBranchesCollection
                ->countDocuments(['branch_id' => $branchId]);

            $clinic['branches'][] = $branch;

            $clinic['doctor_count'] += $branch['doctor_count'];
        }

        $clinic['branch_count'] = count($clinic['branches']);

        /*
        |----------------------------------------------------------------------
        | Search by Clinic, Branch, Address or Contact
        |----------------------------------------------------------------------
        */

        $searchText = implode(' ', [
            $clinic['name'],
            $clinic['address'],
            $clinic['contact']
        ]);

        foreach ($clinic['branches'] as $branch) {
            $searchText .= ' ' . implode(' ', [
                $branch['branch_name'],
                $branch['address'],
                $branch['contact']
            ]);
        }

        if (
            $search === '' ||
            stripos($searchText, $search) !== false
        ) {
            $clinics[] = $clinic;
        }
    }

    $totalBranches = $branchesCollection->countDocuments([]);
    $totalDoctorAssignments = $doctorBranchesCollection->countDocuments([]);

} catch (Throwable $e) {
    error_log('Public Clinics Directory Error: ' . $e->getMessage());
    $databaseError = true;
}

/*
|--------------------------------------------------------------------------
| Render Header After PHP Processing
|--------------------------------------------------------------------------
*/

require __DIR__ . '/includes/header.php';
?>

<style>
.clinic-directory {
    max-width: 1240px;
    margin: 0 auto;
    padding-bottom: 45px;
}

.clinic-hero {
    padding: 38px 0 28px;
}

.clinic-eyebrow {
    display: inline-block;
    color: #2563eb;
    font-size: 13px;
    font-weight: 800;
    letter-spacing: 1.3px;
}

.clinic-hero h1 {
    margin-top: 10px;
    font-size: clamp(30px, 4vw, 44px);
    font-weight: 850;
    letter-spacing: -1.4px;
    color: #172033;
}

.clinic-hero p {
    color: #64748b;
    font-size: 17px;
    line-height: 1.7;
}

.clinic-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 15px;
    margin: 25px 0 28px;
}

.clinic-stat {
    padding: 20px;
    border: 1px solid #e2e8f0;
    border-radius: 17px;
    background: #fff;
}

.clinic-stat strong {
    display: block;
    color: #2563eb;
    font-size: 27px;
    font-weight: 800;
}

.clinic-stat span {
    color: #64748b;
    font-size: 14px;
}

.clinic-search {
    display: flex;
    gap: 10px;
    padding: 13px;
    margin-bottom: 28px;
    border: 1px solid #e2e8f0;
    border-radius: 17px;
    background: #fff;
}

.clinic-search input {
    min-width: 0;
    flex: 1;
    padding: 12px 14px;
    border: 1px solid #dbe4f0;
    border-radius: 11px;
    outline: none;
}

.clinic-search input:focus {
    border-color: #60a5fa;
    box-shadow: 0 0 0 3px rgba(37, 99, 235, .09);
}

.clinic-search button {
    padding: 11px 22px;
    border: 0;
    border-radius: 11px;
    background: #2563eb;
    color: #fff;
    font-weight: 700;
}

.clinic-card {
    height: 100%;
    padding: 25px;
    border: 1px solid #e2e8f0;
    border-radius: 21px;
    background: #fff;
    transition: transform .2s ease, box-shadow .2s ease,
                border-color .2s ease;
}

.clinic-card:hover {
    transform: translateY(-4px);
    border-color: #93baff;
    box-shadow: 0 15px 36px rgba(37, 99, 235, .12);
}

.clinic-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 62px;
    height: 62px;
    margin-bottom: 19px;
    border-radius: 18px;
    background: linear-gradient(135deg, #e8f0ff, #e1f8f5);
    color: #2563eb;
    font-size: 29px;
}

.clinic-name {
    margin-bottom: 15px;
    color: #172033;
    font-size: 23px;
    font-weight: 800;
    overflow-wrap: anywhere;
}

.clinic-info {
    display: flex;
    align-items: flex-start;
    gap: 11px;
    margin: 12px 0;
    color: #53647b;
    line-height: 1.65;
    overflow-wrap: anywhere;
}

.clinic-info i {
    margin-top: 4px;
    color: #2563eb;
}

.clinic-info a {
    color: inherit;
    text-decoration: none;
}

.clinic-info a:hover {
    color: #2563eb;
}

.clinic-metrics {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 20px 0;
}

.clinic-metric {
    padding: 8px 11px;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    background: #f8fafc;
    color: #475569;
    font-size: 13px;
    font-weight: 650;
}

.clinic-branches-title {
    margin: 20px 0 8px;
    padding-top: 18px;
    border-top: 1px solid #e8edf5;
    color: #172033;
    font-size: 16px;
    font-weight: 800;
}

.clinic-branch {
    padding: 14px 0;
    border-bottom: 1px solid #edf1f7;
}

.clinic-branch:last-child {
    border-bottom: 0;
}

.clinic-branch-name {
    color: #243249;
    font-weight: 750;
    overflow-wrap: anywhere;
}

.clinic-branch-address {
    margin-top: 5px;
    color: #64748b;
    font-size: 13px;
    line-height: 1.6;
    overflow-wrap: anywhere;
}

.clinic-branch-doctors {
    margin-top: 7px;
    color: #2563eb;
    font-size: 13px;
    font-weight: 650;
}

.clinic-empty {
    padding: 55px 20px;
    border: 1px dashed #cbd5e1;
    border-radius: 20px;
    background: #fff;
    text-align: center;
}

.clinic-empty i {
    color: #94a3b8;
    font-size: 42px;
}

.clinic-empty h3 {
    margin-top: 15px;
    color: #172033;
    font-weight: 800;
}

.clinic-empty p {
    color: #64748b;
}

@media (max-width: 640px) {
    .clinic-stats {
        grid-template-columns: 1fr;
    }

    .clinic-search {
        flex-direction: column;
    }

    .clinic-search button {
        width: 100%;
    }

    .clinic-card {
        padding: 20px;
    }
}
</style>

<div class="clinic-directory">

    <section class="clinic-hero">
        <span class="clinic-eyebrow">
            <i class="bi bi-buildings me-1"></i>
            MEDIBOOK HEALTHCARE DIRECTORY
        </span>

        <h1>Our Clinics & Hospitals</h1>

        <p>
            Discover healthcare facilities, explore their branches
            and find the right location for your healthcare needs.
        </p>

        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mt-4 mb-4">
            <a href="index.php" class="btn btn-outline-primary px-4 py-3">
                <i class="bi bi-arrow-left me-2"></i>
                Back to Home
            </a>

        </div>
    </section>

    <?php if (!$databaseError): ?>

        <section class="clinic-stats">
            <div class="clinic-stat">
                <strong><?= count($clinics) ?></strong>
                <span>Clinics Found</span>
            </div>

            <div class="clinic-stat">
                <strong>
                    <?php
                    $visibleBranches = 0;
                    foreach ($clinics as $item) {
                        $visibleBranches += (int)$item['branch_count'];
                    }
                    echo $visibleBranches;
                    ?>
                </strong>
                <span>Branches in Results</span>
            </div>

            <div class="clinic-stat">
                <strong>
                    <?php
                    $visibleAssignments = 0;
                    foreach ($clinics as $item) {
                        $visibleAssignments += (int)$item['doctor_count'];
                    }
                    echo $visibleAssignments;
                    ?>
                </strong>
                <span>Doctor Assignments</span>
            </div>
        </section>

        <form method="get" action="clinics.php" class="clinic-search">
            <input
                type="search"
                name="search"
                value="<?= h($search) ?>"
                placeholder="Search clinic, branch, address or phone..."
                aria-label="Search clinics"
            >

            <button type="submit">
                <i class="bi bi-search me-2"></i>
                Search
            </button>

            <?php if ($search !== ''): ?>
                <a href="clinics.php"
                   class="btn btn-outline-secondary d-flex align-items-center justify-content-center">
                    Clear
                </a>
            <?php endif; ?>
        </form>

        <?php if (count($clinics) === 0): ?>

            <div class="clinic-empty">
                <i class="bi bi-hospital"></i>
                <h3>No Clinics Found</h3>
                <p>
                    No clinics match your search. Try a different
                    clinic name, address or phone number.
                </p>
                <a href="clinics.php" class="btn btn-primary">
                    View All Clinics
                </a>
            </div>

        <?php else: ?>

            <div class="row g-4">

                <?php foreach ($clinics as $clinic): ?>

                    <div class="col-12 col-md-6 col-xl-4">
                        <article class="clinic-card">

                            <div class="clinic-icon">
                                <i class="bi bi-hospital"></i>
                            </div>

                            <h2 class="clinic-name">
                                <?= h($clinic['name']) ?>
                            </h2>

                            <div class="clinic-info">
                                <i class="bi bi-geo-alt-fill"></i>
                                <span>
                                    <?= h(
                                        $clinic['address'] !== ''
                                            ? $clinic['address']
                                            : 'Address not provided'
                                    ) ?>
                                </span>
                            </div>

                            <div class="clinic-info">
                                <i class="bi bi-telephone-fill"></i>
                                <span>
                                    <?php if ($clinic['contact'] !== ''): ?>
                                        <a href="tel:<?= h($clinic['contact']) ?>">
                                            <?= h($clinic['contact']) ?>
                                        </a>
                                    <?php else: ?>
                                        Contact number not provided
                                    <?php endif; ?>
                                </span>
                            </div>

                            <div class="clinic-metrics">
                                <span class="clinic-metric">
                                    <i class="bi bi-building me-1"></i>
                                    <?= (int)$clinic['branch_count'] ?>
                                    Branches
                                </span>

                                <span class="clinic-metric">
                                    <i class="bi bi-person-badge me-1"></i>
                                    <?= (int)$clinic['doctor_count'] ?>
                                    Doctor Assignments
                                </span>
                            </div>

                            <h3 class="clinic-branches-title">
                                <i class="bi bi-diagram-3 me-2 text-primary"></i>
                                Branch Locations
                            </h3>

                            <?php if (!empty($clinic['branches'])): ?>

                                <?php foreach ($clinic['branches'] as $branch): ?>

                                    <div class="clinic-branch">

                                        <div class="clinic-branch-name">
                                            <?= h($branch['branch_name']) ?>
                                        </div>

                                        <div class="clinic-branch-address">
                                            <i class="bi bi-geo-alt me-1"></i>
                                            <?= h(
                                                $branch['address'] !== ''
                                                    ? $branch['address']
                                                    : 'Address not provided'
                                            ) ?>
                                        </div>

                                        <?php if ($branch['contact'] !== ''): ?>
                                            <div class="clinic-branch-address">
                                                <i class="bi bi-telephone me-1"></i>
                                                <?= h($branch['contact']) ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="clinic-branch-doctors">
                                            <i class="bi bi-person-check me-1"></i>
                                            <?= (int)$branch['doctor_count'] ?>
                                            doctor assignment(s)
                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <p class="text-secondary small mb-0">
                                    No branches have been added for this clinic yet.
                                </p>

                            <?php endif; ?>

                        </article>
                    </div>

                <?php endforeach; ?>

            </div>

        <?php endif; ?>

    <?php else: ?>

        <div class="alert alert-danger" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            Clinic information could not be loaded. Please check the
            MongoDB connection and try again.
        </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
