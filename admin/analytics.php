<?php

$page_title = 'Analytics & Reports';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('admin');


/*
|--------------------------------------------------------------------------
| Date Range
|--------------------------------------------------------------------------
*/

$from_date = $_GET['from'] ?? date('Y-m-01');
$to_date   = $_GET['to'] ?? date('Y-m-d');


/*
|--------------------------------------------------------------------------
| Validate Dates
|--------------------------------------------------------------------------
*/

$from_valid = DateTime::createFromFormat('Y-m-d', $from_date);
$to_valid   = DateTime::createFromFormat('Y-m-d', $to_date);

if (
    !$from_valid ||
    $from_valid->format('Y-m-d') !== $from_date
) {
    $from_date = date('Y-m-01');
}

if (
    !$to_valid ||
    $to_valid->format('Y-m-d') !== $to_date
) {
    $to_date = date('Y-m-d');
}


/*
|--------------------------------------------------------------------------
| MongoDB Collections
|--------------------------------------------------------------------------
*/

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');
$patientsCollection = $mongoDb->selectCollection('patients');
$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');

$dateFilter = [
    '$gte' => $from_date,
    '$lte' => $to_date,
];

/*
|--------------------------------------------------------------------------
| Overall Statistics
|--------------------------------------------------------------------------
*/

$stats = [
    'total' => 0,
    'requested' => 0,
    'confirmed' => 0,
    'checked_in' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'no_show' => 0,
];

$monthlyCounts = [];
$statusCounts = [];
$doctorCounts = [];
$branchCounts = [];
$recentAppointments = [];

$appointmentCursor = $appointmentsCollection->find([
    'appointment_date' => $dateFilter,
]);

foreach ($appointmentCursor as $appointmentDocument) {
    $appointment = $appointmentDocument->getArrayCopy();

    $status = (string) ($appointment['status'] ?? '');
    $appointmentDate = (string) ($appointment['appointment_date'] ?? '');

    $stats['total']++;

    switch ($status) {
        case 'Requested':
            $stats['requested']++;
            break;

        case 'Confirmed':
            $stats['confirmed']++;
            break;

        case 'Checked-in':
            $stats['checked_in']++;
            break;

        case 'In Progress':
            $stats['in_progress']++;
            break;

        case 'Completed':
            $stats['completed']++;
            break;

        case 'Cancelled':
            $stats['cancelled']++;
            break;

        case 'No-show':
            $stats['no_show']++;
            break;
    }

    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;

    $month = substr($appointmentDate, 0, 7);

    if ($month !== '') {
        $monthlyCounts[$month] = ($monthlyCounts[$month] ?? 0) + 1;
    }

    $doctorId = (int) ($appointment['doctor_id'] ?? 0);

    if ($doctorId > 0) {
        $doctorCounts[$doctorId] = ($doctorCounts[$doctorId] ?? 0) + 1;
    }

    $branchId = (int) ($appointment['branch_id'] ?? 0);

    if ($branchId > 0) {
        $branchCounts[$branchId] = ($branchCounts[$branchId] ?? 0) + 1;
    }

    $recentAppointments[] = $appointment;
}

/*
|--------------------------------------------------------------------------
| Rates
|--------------------------------------------------------------------------
*/

$completion_rate = $stats['total'] > 0
    ? round(($stats['completed'] / $stats['total']) * 100, 1)
    : 0;

$cancellation_rate = $stats['total'] > 0
    ? round(($stats['cancelled'] / $stats['total']) * 100, 1)
    : 0;

$no_show_rate = $stats['total'] > 0
    ? round(($stats['no_show'] / $stats['total']) * 100, 1)
    : 0;

/*
|--------------------------------------------------------------------------
| Monthly Appointment Trend
|--------------------------------------------------------------------------
*/

ksort($monthlyCounts);

$monthly = [];

foreach ($monthlyCounts as $month => $total) {
    $monthly[] = [
        'month' => $month,
        'total' => $total,
    ];
}

/*
|--------------------------------------------------------------------------
| Status Distribution
|--------------------------------------------------------------------------
*/

arsort($statusCounts);

$status_rows = [];

foreach ($statusCounts as $status => $total) {
    $status_rows[] = [
        'status' => $status,
        'total' => $total,
    ];
}

/*
|--------------------------------------------------------------------------
| Top Doctors
|--------------------------------------------------------------------------
*/

arsort($doctorCounts);

$top_doctors = [];

foreach (array_slice($doctorCounts, 0, 10, true) as $doctorId => $count) {
    $doctorDocument = $doctorsCollection->findOne([
        'doctor_id' => (int) $doctorId,
    ]);

    if ($doctorDocument === null) {
        continue;
    }

    $doctor = $doctorDocument->getArrayCopy();

    $userDocument = $usersCollection->findOne([
        'user_id' => (int) ($doctor['user_id'] ?? 0),
    ]);

    $top_doctors[] = [
        'doctor_id' => (int) $doctorId,
        'doctor_name' => $userDocument['name'] ?? 'Unknown Doctor',
        'appointment_count' => (int) $count,
    ];
}

/*
|--------------------------------------------------------------------------
| Branch Performance
|--------------------------------------------------------------------------
*/

arsort($branchCounts);

$branches = [];

foreach (array_slice($branchCounts, 0, 10, true) as $branchId => $count) {
    $branchDocument = $branchesCollection->findOne([
        'branch_id' => (int) $branchId,
    ]);

    if ($branchDocument === null) {
        continue;
    }

    $branch = $branchDocument->getArrayCopy();

    $clinicDocument = $clinicsCollection->findOne([
        'clinic_id' => (int) ($branch['clinic_id'] ?? 0),
    ]);

    $branches[] = [
        'branch_id' => (int) $branchId,
        'branch_name' => $branch['branch_name'] ?? 'Unknown Branch',
        'clinic_name' => $clinicDocument['name'] ?? 'Unknown Clinic',
        'appointment_count' => (int) $count,
    ];
}

/*
|--------------------------------------------------------------------------
| Recent Appointments
|--------------------------------------------------------------------------
*/

usort($recentAppointments, function ($a, $b) {
    $dateCompare = strcmp(
        (string) ($b['appointment_date'] ?? ''),
        (string) ($a['appointment_date'] ?? '')
    );

    if ($dateCompare !== 0) {
        return $dateCompare;
    }

    return strcmp(
        (string) ($b['start_time'] ?? ''),
        (string) ($a['start_time'] ?? '')
    );
});

$recent = [];

foreach (array_slice($recentAppointments, 0, 10) as $appointment) {
    $patientDocument = $patientsCollection->findOne([
        'patient_id' => (int) ($appointment['patient_id'] ?? 0),
    ]);

    $patient = $patientDocument !== null
        ? $patientDocument->getArrayCopy()
        : [];

    $patientUser = $usersCollection->findOne([
        'user_id' => (int) ($patient['user_id'] ?? 0),
    ]);

    $doctorDocument = $doctorsCollection->findOne([
        'doctor_id' => (int) ($appointment['doctor_id'] ?? 0),
    ]);

    $doctor = $doctorDocument !== null
        ? $doctorDocument->getArrayCopy()
        : [];

    $doctorUser = $usersCollection->findOne([
        'user_id' => (int) ($doctor['user_id'] ?? 0),
    ]);

    $recent[] = [
        'appointment_ref' => $appointment['appointment_ref'] ?? '',
        'appointment_date' => $appointment['appointment_date'] ?? '',
        'start_time' => $appointment['start_time'] ?? '',
        'status' => $appointment['status'] ?? '',
        'patient_name' => $patientUser['name'] ?? 'Unknown Patient',
        'doctor_name' => $doctorUser['name'] ?? 'Unknown Doctor',
    ];
}

require '../includes/header.php';

?>

<!-- Header -->

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

    <div>

        <span class="eyebrow">
            <i class="bi bi-bar-chart-line"></i>
            Administration
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            Analytics & Reports
        </h1>

        <p class="text-secondary mb-0">
            Monitor appointment activity and operational performance.
        </p>

    </div>

</div>


<!-- Date Filter -->

<div class="card border-0 shadow-sm p-3 mb-4">

    <form method="get" class="row g-3 align-items-end">

        <div class="col-md-4">

            <label class="form-label fw-semibold">
                From Date
            </label>

            <input
                type="date"
                name="from"
                value="<?=h($from_date)?>"
                class="form-control"
            >

        </div>


        <div class="col-md-4">

            <label class="form-label fw-semibold">
                To Date
            </label>

            <input
                type="date"
                name="to"
                value="<?=h($to_date)?>"
                class="form-control"
            >

        </div>


        <div class="col-md-4">

            <button class="btn btn-primary w-100">

                <i class="bi bi-filter me-1"></i>

                Apply Date Range

            </button>

        </div>

    </form>

</div>


<!-- Main Statistics -->

<div class="row g-3 mb-4">

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Total Appointments
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($stats['total'])?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Completed
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($stats['completed'])?>
            </div>

            <small class="text-success">
                <?=$completion_rate?>% completion rate
            </small>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Cancelled
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($stats['cancelled'])?>
            </div>

            <small class="text-warning">
                <?=$cancellation_rate?>% of appointments
            </small>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                No-show
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($stats['no_show'])?>
            </div>

            <small class="text-danger">
                <?=$no_show_rate?>% of appointments
            </small>

        </div>

    </div>

</div>


<!-- Status Cards -->

<div class="row g-3 mb-4">

    <div class="col-md-3">

        <div class="card border-0 shadow-sm p-3">

            <div class="text-secondary small">
                Requested
            </div>

            <div class="fw-bold fs-4">
                <?=number_format($stats['requested'])?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm p-3">

            <div class="text-secondary small">
                Confirmed
            </div>

            <div class="fw-bold fs-4">
                <?=number_format($stats['confirmed'])?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm p-3">

            <div class="text-secondary small">
                Checked-in
            </div>

            <div class="fw-bold fs-4">
                <?=number_format($stats['checked_in'])?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm p-3">

            <div class="text-secondary small">
                In Progress
            </div>

            <div class="fw-bold fs-4">
                <?=number_format($stats['in_progress'])?>
            </div>

        </div>

    </div>

</div>


<div class="row g-4 mb-4">


    <!-- Monthly Trend -->

    <div class="col-lg-7">

        <div class="card border-0 shadow-sm h-100">

            <div class="card-body">

                <h5 class="fw-bold mb-1">
                    Monthly Appointment Trend
                </h5>

                <p class="text-secondary small mb-4">
                    Appointment volume within the selected date range.
                </p>


                <?php if ($monthly): ?>

                    <div class="table-responsive">

                        <table class="table align-middle">

                            <thead>

                                <tr>
                                    <th>Month</th>
                                    <th>Appointments</th>
                                    <th style="width:45%;">
                                        Volume
                                    </th>
                                </tr>

                            </thead>

                            <tbody>

                                <?php

                                $max_monthly = max(
                                    array_column(
                                        $monthly,
                                        'total'
                                    )
                                );

                                foreach ($monthly as $m):

                                    $width = $max_monthly > 0
                                        ? ($m['total'] / $max_monthly) * 100
                                        : 0;

                                ?>

                                    <tr>

                                        <td>
                                            <strong>
                                                <?=h($m['month'])?>
                                            </strong>
                                        </td>

                                        <td>
                                            <?=number_format((int)$m['total'])?>
                                        </td>

                                        <td>

                                            <div
                                                class="progress"
                                                style="height:8px;"
                                            >

                                                <div
                                                    class="progress-bar"
                                                    style="width:<?=$width?>%"
                                                ></div>

                                            </div>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="empty-state p-4 text-center">
                        No appointment data for this period.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>


    <!-- Status Distribution -->

    <div class="col-lg-5">

        <div class="card border-0 shadow-sm h-100">

            <div class="card-body">

                <h5 class="fw-bold mb-1">
                    Status Distribution
                </h5>

                <p class="text-secondary small mb-4">
                    Appointment statuses for the selected period.
                </p>


                <?php if ($status_rows): ?>

                    <?php foreach ($status_rows as $row): ?>

                        <?php

                        $percentage = $stats['total'] > 0
                            ? round(
                                ($row['total'] / $stats['total']) * 100,
                                1
                            )
                            : 0;

                        ?>

                        <div class="mb-3">

                            <div class="d-flex justify-content-between mb-1">

                                <span>
                                    <?=h($row['status'])?>
                                </span>

                                <strong>
                                    <?=number_format((int)$row['total'])?>
                                    (<?=$percentage?>%)
                                </strong>

                            </div>

                            <div
                                class="progress"
                                style="height:8px;"
                            >

                                <div
                                    class="progress-bar"
                                    style="width:<?=$percentage?>%"
                                ></div>

                            </div>

                        </div>

                    <?php endforeach; ?>

                <?php else: ?>

                    <div class="empty-state p-4 text-center">
                        No status data available.
                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>


<div class="row g-4 mb-4">


    <!-- Top Doctors -->

    <div class="col-lg-6">

        <div class="card border-0 shadow-sm h-100 overflow-hidden">

            <div class="card-header bg-white py-3">

                <h5 class="fw-bold mb-1">
                    Doctor Appointment Volume
                </h5>

                <small class="text-secondary">
                    Top doctors by appointment count.
                </small>

            </div>


            <div class="table-responsive">

                <table class="table align-middle mb-0">

                    <thead>

                        <tr>
                            <th>Doctor</th>
                            <th class="text-end">
                                Appointments
                            </th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($top_doctors as $doctor): ?>

                            <tr>

                                <td>

                                    <strong>
                                        Dr. <?=h($doctor['doctor_name'])?>
                                    </strong>

                                </td>

                                <td class="text-end">

                                    <span class="badge text-bg-light border">

                                        <?=number_format(
                                            (int)$doctor['appointment_count']
                                        )?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                        <?php if (!$top_doctors): ?>

                            <tr>

                                <td colspan="2">

                                    <div class="empty-state p-4 text-center">
                                        No doctor data available.
                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>


    <!-- Branch Volume -->

    <div class="col-lg-6">

        <div class="card border-0 shadow-sm h-100 overflow-hidden">

            <div class="card-header bg-white py-3">

                <h5 class="fw-bold mb-1">
                    Branch Appointment Volume
                </h5>

                <small class="text-secondary">
                    Appointment count by branch.
                </small>

            </div>


            <div class="table-responsive">

                <table class="table align-middle mb-0">

                    <thead>

                        <tr>
                            <th>Branch</th>
                            <th>Clinic</th>
                            <th class="text-end">
                                Appointments
                            </th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($branches as $branch): ?>

                            <tr>

                                <td>

                                    <strong>
                                        <?=h($branch['branch_name'])?>
                                    </strong>

                                </td>

                                <td>
                                    <?=h($branch['clinic_name'])?>
                                </td>

                                <td class="text-end">

                                    <span class="badge text-bg-light border">

                                        <?=number_format(
                                            (int)$branch['appointment_count']
                                        )?>

                                    </span>

                                </td>

                            </tr>

                        <?php endforeach; ?>


                        <?php if (!$branches): ?>

                            <tr>

                                <td colspan="3">

                                    <div class="empty-state p-4 text-center">
                                        No branch data available.
                                    </div>

                                </td>

                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

</div>


<!-- Recent Appointments -->

<div class="card border-0 shadow-sm overflow-hidden">

    <div class="card-header bg-white py-3">

        <h5 class="fw-bold mb-1">
            Recent Appointments
        </h5>

        <small class="text-secondary">
            Latest appointments within the selected period.
        </small>

    </div>


    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>

                    <th>Reference</th>

                    <th>Patient</th>

                    <th>Doctor</th>

                    <th>Date</th>

                    <th>Status</th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($recent as $appointment): ?>

                    <tr>

                        <td>

                            <strong>
                                <?=h($appointment['appointment_ref'])?>
                            </strong>

                        </td>


                        <td>
                            <?=h($appointment['patient_name'])?>
                        </td>


                        <td>
                            Dr. <?=h($appointment['doctor_name'])?>
                        </td>


                        <td>

                            <?=h($appointment['appointment_date'])?>

                            <br>

                            <small class="text-secondary">

                                <?=h(
                                    substr(
                                        $appointment['start_time'],
                                        0,
                                        5
                                    )
                                )?>

                            </small>

                        </td>


                        <td>

                            <span class="status-pill bg-light border">

                                <?=h($appointment['status'])?>

                            </span>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$recent): ?>

                    <tr>

                        <td colspan="5">

                            <div class="empty-state p-4 text-center">

                                No appointments found for this date range.

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php require '../includes/footer.php'; ?>