<?php

$page_title = 'Reports';
$asset_prefix = '../';

require_once '../config/mongodb.php';
require '../includes/header.php';
require_role('admin');


/*
|--------------------------------------------------------------------------
| Date Range
|--------------------------------------------------------------------------
*/

$from_date = $_GET['from'] ?? date('Y-m-01');
$to_date = $_GET['to'] ?? date('Y-m-d');

$from_check = DateTime::createFromFormat('!Y-m-d', $from_date);
$to_check = DateTime::createFromFormat('!Y-m-d', $to_date);

if (!$from_check || $from_check->format('Y-m-d') !== $from_date) {
    $from_date = date('Y-m-01');
}

if (!$to_check || $to_check->format('Y-m-d') !== $to_date) {
    $to_date = date('Y-m-d');
}

if ($from_date > $to_date) {
    [$from_date, $to_date] = [$to_date, $from_date];
}

/*
|--------------------------------------------------------------------------
| MongoDB Collections
|--------------------------------------------------------------------------
*/

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$doctorsCollection = $mongoDb->selectCollection('doctors');
$patientsCollection = $mongoDb->selectCollection('patients');
$usersCollection = $mongoDb->selectCollection('users');
$branchesCollection = $mongoDb->selectCollection('branches');
$clinicsCollection = $mongoDb->selectCollection('clinics');

/*
|--------------------------------------------------------------------------
| Load Appointments for Selected Date Range
|--------------------------------------------------------------------------
|
| Assumption: appointment_date is stored as YYYY-MM-DD string.
|
*/

$appointments = $appointmentsCollection->find(
    [
        'appointment_date' => [
            '$gte' => $from_date,
            '$lte' => $to_date
        ]
    ],
    [
        'sort' => [
            'appointment_date' => -1,
            'start_time' => -1
        ]
    ]
)->toArray();

/*
|--------------------------------------------------------------------------
| Summary Statistics
|--------------------------------------------------------------------------
*/

$statuses = [
    'Requested' => 'requested',
    'Confirmed' => 'confirmed',
    'Checked-in' => 'checked_in',
    'In Progress' => 'in_progress',
    'Completed' => 'completed',
    'Cancelled' => 'cancelled',
    'No-show' => 'no_show'
];

$summary = [
    'total' => count($appointments),
    'requested' => 0,
    'confirmed' => 0,
    'checked_in' => 0,
    'in_progress' => 0,
    'completed' => 0,
    'cancelled' => 0,
    'no_show' => 0
];

$dailyMap = [];
$monthlyMap = [];
$doctorMap = [];
$branchMap = [];
$recent_rows = [];

$doctorCache = [];
$patientCache = [];
$userCache = [];
$branchCache = [];
$clinicCache = [];

$doctorIds = [];
$branchIds = [];

foreach ($appointments as $appointment) {
    $a = (array)$appointment;

    $status = (string)($a['status'] ?? '');
    $date = (string)($a['appointment_date'] ?? '');
    $doctorId = $a['doctor_id'] ?? null;
    $branchId = $a['branch_id'] ?? null;

    if (isset($statuses[$status])) {
        $summary[$statuses[$status]]++;
    }

    // Daily summary
    if ($date !== '') {
        if (!isset($dailyMap[$date])) {
            $dailyMap[$date] = [
                'day' => $date,
                'appointments' => 0,
                'requested' => 0,
                'confirmed' => 0,
                'checked_in' => 0,
                'in_progress' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'no_show' => 0
            ];
        }

        $dailyMap[$date]['appointments']++;

        if (isset($statuses[$status])) {
            $dailyMap[$date][$statuses[$status]]++;
        }

        // Monthly summary
        $month = substr($date, 0, 7);

        if (!isset($monthlyMap[$month])) {
            $monthlyMap[$month] = [
                'month' => $month,
                'total' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'no_show' => 0
            ];
        }

        $monthlyMap[$month]['total']++;

        if (isset($statuses[$status]) &&
            in_array($status, ['Completed', 'Cancelled', 'No-show'], true)) {
            $monthlyMap[$month][$statuses[$status]]++;
        }
    }

    // Doctor-wise summary
    if ($doctorId !== null) {
        $doctorKey = (string)$doctorId;
        $doctorIds[$doctorKey] = $doctorId;

        if (!isset($doctorMap[$doctorKey])) {
            $doctorMap[$doctorKey] = [
                'doctor_id' => $doctorId,
                'doctor_name' => 'Unknown',
                'total' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'no_show' => 0
            ];
        }

        $doctorMap[$doctorKey]['total']++;

        if ($status === 'Completed') {
            $doctorMap[$doctorKey]['completed']++;
        } elseif ($status === 'Cancelled') {
            $doctorMap[$doctorKey]['cancelled']++;
        } elseif ($status === 'No-show') {
            $doctorMap[$doctorKey]['no_show']++;
        }
    }

    // Branch-wise summary
    if ($branchId !== null) {
        $branchKey = (string)$branchId;
        $branchIds[$branchKey] = $branchId;

        if (!isset($branchMap[$branchKey])) {
            $branchMap[$branchKey] = [
                'branch_id' => $branchId,
                'branch_name' => 'Unknown',
                'clinic_name' => 'Unknown',
                'total' => 0,
                'completed' => 0,
                'cancelled' => 0,
                'no_show' => 0
            ];
        }

        $branchMap[$branchKey]['total']++;

        if ($status === 'Completed') {
            $branchMap[$branchKey]['completed']++;
        } elseif ($status === 'Cancelled') {
            $branchMap[$branchKey]['cancelled']++;
        } elseif ($status === 'No-show') {
            $branchMap[$branchKey]['no_show']++;
        }
    }
}

/*
|--------------------------------------------------------------------------
| Completion / Cancellation / No-show Rates
|--------------------------------------------------------------------------
*/

$total = (int)$summary['total'];
$completed = (int)$summary['completed'];
$cancelled = (int)$summary['cancelled'];
$no_show = (int)$summary['no_show'];

$completion_rate = $total > 0
    ? round(($completed / $total) * 100, 1)
    : 0;

$cancellation_rate = $total > 0
    ? round(($cancelled / $total) * 100, 1)
    : 0;

$no_show_rate = $total > 0
    ? round(($no_show / $total) * 100, 1)
    : 0;

/*
|--------------------------------------------------------------------------
| Load Related Records
|--------------------------------------------------------------------------
*/

foreach ($doctorIds as $key => $doctorId) {
    $doctor = $doctorsCollection->findOne(['doctor_id' => $doctorId]);

    if (!$doctor) {
        // Try string ID if the stored ID type differs.
        $doctor = $doctorsCollection->findOne(['doctor_id' => (string)$doctorId]);
    }

    $doctorCache[$key] = $doctor ? (array)$doctor : [];

    $userId = $doctorCache[$key]['user_id'] ?? null;

    if ($userId !== null) {
        $user = $usersCollection->findOne(['user_id' => $userId]);

        if (!$user) {
            $user = $usersCollection->findOne(['user_id' => (string)$userId]);
        }

        $userCache[(string)$userId] = $user ? (array)$user : [];
    }

    $doctorMap[$key]['doctor_name'] =
        $userCache[(string)$userId]['name'] ?? 'Unknown';
}

foreach ($branchIds as $key => $branchId) {
    $branch = $branchesCollection->findOne(['branch_id' => $branchId]);

    if (!$branch) {
        $branch = $branchesCollection->findOne(['branch_id' => (string)$branchId]);
    }

    $branchCache[$key] = $branch ? (array)$branch : [];

    $branchMap[$key]['branch_name'] =
        $branchCache[$key]['branch_name'] ?? 'Unknown';

    $clinicId = $branchCache[$key]['clinic_id'] ?? null;

    if ($clinicId !== null) {
        $clinic = $clinicsCollection->findOne(['clinic_id' => $clinicId]);

        if (!$clinic) {
            $clinic = $clinicsCollection->findOne(['clinic_id' => (string)$clinicId]);
        }

        $clinicCache[(string)$clinicId] = $clinic ? (array)$clinic : [];
    }

    $branchMap[$key]['clinic_name'] =
        $clinicCache[(string)$clinicId]['name'] ?? 'Unknown';
}

/*
|--------------------------------------------------------------------------
| Finalize Doctor and Branch Reports
|--------------------------------------------------------------------------
*/

$doctor_rows = array_values($doctorMap);
$branch_rows = array_values($branchMap);

usort($doctor_rows, fn($a, $b) => $b['total'] <=> $a['total']);
usort($branch_rows, fn($a, $b) => $b['total'] <=> $a['total']);

$doctor_rows = array_slice($doctor_rows, 0, 20);
$branch_rows = array_slice($branch_rows, 0, 20);

/*
|--------------------------------------------------------------------------
| Daily and Monthly Rows
|--------------------------------------------------------------------------
*/

$daily_rows = array_values($dailyMap);
$monthly_rows = array_values($monthlyMap);

usort($daily_rows, fn($a, $b) => strcmp($b['day'], $a['day']));
usort($monthly_rows, fn($a, $b) => strcmp($b['month'], $a['month']));

/*
|--------------------------------------------------------------------------
| Recent Appointment Report
|--------------------------------------------------------------------------
*/

foreach (array_slice($appointments, 0, 15) as $appointment) {
    $a = (array)$appointment;

    $patientId = $a['patient_id'] ?? null;
    $doctorId = $a['doctor_id'] ?? null;

    $patientName = 'Unknown';
    $doctorName = 'Unknown';

    if ($patientId !== null) {
        $patientKey = (string)$patientId;

        if (!isset($patientCache[$patientKey])) {
            $patient = $patientsCollection->findOne(['patient_id' => $patientId]);

            if (!$patient) {
                $patient = $patientsCollection->findOne([
                    'patient_id' => (string)$patientId
                ]);
            }

            $patientCache[$patientKey] = $patient ? (array)$patient : [];

            $patientUserId = $patientCache[$patientKey]['user_id'] ?? null;

            if ($patientUserId !== null) {
                $patientUser = $usersCollection->findOne([
                    'user_id' => $patientUserId
                ]);

                if (!$patientUser) {
                    $patientUser = $usersCollection->findOne([
                        'user_id' => (string)$patientUserId
                    ]);
                }

                $userCache[(string)$patientUserId] =
                    $patientUser ? (array)$patientUser : [];
            }
        }

        $patientUserId = $patientCache[$patientKey]['user_id'] ?? null;
        $patientName = $userCache[(string)$patientUserId]['name'] ?? 'Unknown';
    }

    if ($doctorId !== null) {
        $doctorKey = (string)$doctorId;
        $doctorName = $doctorMap[$doctorKey]['doctor_name'] ?? 'Unknown';
    }

    $recent_rows[] = [
        'appointment_ref' => $a['appointment_ref'] ?? '',
        'appointment_date' => $a['appointment_date'] ?? '',
        'start_time' => $a['start_time'] ?? '',
        'status' => $a['status'] ?? '',
        'patient_name' => $patientName,
        'doctor_name' => $doctorName
    ];
}
?>

<!-- Page Header -->

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4">

    <div>

        <span class="eyebrow">
            <i class="bi bi-file-earmark-bar-graph"></i>
            Administration
        </span>

        <h1 class="h3 fw-bold mt-2 mb-1">
            Appointment Reports
        </h1>

        <p class="text-secondary mb-0">
            Review appointment activity and operational performance.
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

                Generate Report

            </button>

        </div>

    </form>

</div>


<!-- Summary Cards -->

<div class="row g-3 mb-4">

    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Total Appointments
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($total)?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                Completed
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($completed)?>
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
                <?=number_format($cancelled)?>
            </div>

            <small class="text-warning">
                <?=$cancellation_rate?>% cancellation rate
            </small>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm h-100 p-3">

            <div class="text-secondary small">
                No-show
            </div>

            <div class="fs-3 fw-bold mt-1">
                <?=number_format($no_show)?>
            </div>

            <small class="text-danger">
                <?=$no_show_rate?>% no-show rate
            </small>

        </div>

    </div>

</div>


<!-- Status Summary -->

<div class="row g-3 mb-4">

    <div class="col-md-3">

        <div class="card border-0 shadow-sm p-3">

            <div class="text-secondary small">
                Requested
            </div>

            <div class="fs-4 fw-bold">
                <?=number_format((int)$summary['requested'])?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm p-3">

            <div class="text-secondary small">
                Confirmed
            </div>

            <div class="fs-4 fw-bold">
                <?=number_format((int)$summary['confirmed'])?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm p-3">

            <div class="text-secondary small">
                Checked-in
            </div>

            <div class="fs-4 fw-bold">
                <?=number_format((int)$summary['checked_in'])?>
            </div>

        </div>

    </div>


    <div class="col-md-3">

        <div class="card border-0 shadow-sm p-3">

            <div class="text-secondary small">
                In Progress
            </div>

            <div class="fs-4 fw-bold">
                <?=number_format((int)$summary['in_progress'])?>
            </div>

        </div>

    </div>

</div>


<!-- Daily Report -->

<div class="card border-0 shadow-sm overflow-hidden mb-4">

    <div class="card-header bg-white py-3">

        <h5 class="fw-bold mb-1">
            Daily Appointment Report
        </h5>

        <small class="text-secondary">
            Appointment statistics grouped by date.
        </small>

    </div>


    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>

                    <th>Date</th>
                    <th>Total</th>
                    <th>Requested</th>
                    <th>Confirmed</th>
                    <th>Checked-in</th>
                    <th>In Progress</th>
                    <th>Completed</th>
                    <th>Cancelled</th>
                    <th>No-show</th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($daily_rows as $row): ?>

                    <tr>

                        <td>
                            <strong>
                                <?=h(
                                    date(
                                        'd M Y',
                                        strtotime($row['day'])
                                    )
                                )?>
                            </strong>
                        </td>


                        <td>
                            <strong>
                                <?=number_format(
                                    (int)$row['appointments']
                                )?>
                            </strong>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['requested']
                            )?>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['confirmed']
                            )?>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['checked_in']
                            )?>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['in_progress']
                            )?>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['completed']
                            )?>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['cancelled']
                            )?>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['no_show']
                            )?>
                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$daily_rows): ?>

                    <tr>

                        <td colspan="9">

                            <div class="empty-state p-5 text-center">

                                <i class="bi bi-calendar-x fs-1 d-block mb-3"></i>

                                <h5 class="fw-bold">
                                    No appointment data
                                </h5>

                                <p class="text-secondary mb-0">
                                    No appointments were found for the selected date range.
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<div class="row g-4 mb-4">


    <!-- Doctor Report -->

    <div class="col-lg-6">

        <div class="card border-0 shadow-sm overflow-hidden h-100">

            <div class="card-header bg-white py-3">

                <h5 class="fw-bold mb-1">
                    Doctor-wise Report
                </h5>

                <small class="text-secondary">
                    Appointment volume by doctor.
                </small>

            </div>


            <div class="table-responsive">

                <table class="table align-middle mb-0">

                    <thead>

                        <tr>

                            <th>Doctor</th>
                            <th>Total</th>
                            <th>Completed</th>
                            <th>Cancelled</th>
                            <th>No-show</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($doctor_rows as $row): ?>

                            <tr>

                                <td>
                                    <strong>
                                        Dr. <?=h($row['doctor_name'])?>
                                    </strong>
                                </td>

                                <td>
                                    <?=number_format(
                                        (int)$row['total']
                                    )?>
                                </td>

                                <td>
                                    <?=number_format(
                                        (int)$row['completed']
                                    )?>
                                </td>

                                <td>
                                    <?=number_format(
                                        (int)$row['cancelled']
                                    )?>
                                </td>

                                <td>
                                    <?=number_format(
                                        (int)$row['no_show']
                                    )?>
                                </td>

                            </tr>

                        <?php endforeach; ?>


                        <?php if (!$doctor_rows): ?>

                            <tr>

                                <td colspan="5">

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


    <!-- Branch Report -->

    <div class="col-lg-6">

        <div class="card border-0 shadow-sm overflow-hidden h-100">

            <div class="card-header bg-white py-3">

                <h5 class="fw-bold mb-1">
                    Branch-wise Report
                </h5>

                <small class="text-secondary">
                    Appointment volume by branch.
                </small>

            </div>


            <div class="table-responsive">

                <table class="table align-middle mb-0">

                    <thead>

                        <tr>

                            <th>Clinic</th>
                            <th>Branch</th>
                            <th>Total</th>
                            <th>Completed</th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php foreach ($branch_rows as $row): ?>

                            <tr>

                                <td>
                                    <?=h($row['clinic_name'])?>
                                </td>

                                <td>
                                    <strong>
                                        <?=h($row['branch_name'])?>
                                    </strong>
                                </td>

                                <td>
                                    <?=number_format(
                                        (int)$row['total']
                                    )?>
                                </td>

                                <td>
                                    <?=number_format(
                                        (int)$row['completed']
                                    )?>
                                </td>

                            </tr>

                        <?php endforeach; ?>


                        <?php if (!$branch_rows): ?>

                            <tr>

                                <td colspan="4">

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


<!-- Monthly Summary -->

<div class="card border-0 shadow-sm overflow-hidden mb-4">

    <div class="card-header bg-white py-3">

        <h5 class="fw-bold mb-1">
            Monthly Summary
        </h5>

        <small class="text-secondary">
            Monthly appointment performance for the selected period.
        </small>

    </div>


    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead>

                <tr>

                    <th>Month</th>
                    <th>Total</th>
                    <th>Completed</th>
                    <th>Cancelled</th>
                    <th>No-show</th>
                    <th>Completion Rate</th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($monthly_rows as $row): ?>

                    <?php

                    $month_total = (int)$row['total'];

                    $month_completed = (int)$row['completed'];

                    $month_rate = $month_total > 0
                        ? round(
                            ($month_completed / $month_total) * 100,
                            1
                        )
                        : 0;

                    ?>

                    <tr>

                        <td>

                            <strong>
                                <?=h(
                                    date(
                                        'F Y',
                                        strtotime(
                                            $row['month'] . '-01'
                                        )
                                    )
                                )?>
                            </strong>

                        </td>


                        <td>
                            <?=number_format($month_total)?>
                        </td>


                        <td>
                            <?=number_format(
                                $month_completed
                            )?>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['cancelled']
                            )?>
                        </td>


                        <td>
                            <?=number_format(
                                (int)$row['no_show']
                            )?>
                        </td>


                        <td>

                            <div class="d-flex align-items-center gap-2">

                                <div
                                    class="progress flex-grow-1"
                                    style="height:7px;"
                                >

                                    <div
                                        class="progress-bar"
                                        style="width:<?=$month_rate?>%"
                                    ></div>

                                </div>

                                <small class="fw-semibold">
                                    <?=$month_rate?>%
                                </small>

                            </div>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$monthly_rows): ?>

                    <tr>

                        <td colspan="6">

                            <div class="empty-state p-4 text-center">
                                No monthly data available.
                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<!-- Recent Appointments -->

<div class="card border-0 shadow-sm overflow-hidden">

    <div class="card-header bg-white py-3">

        <h5 class="fw-bold mb-1">
            Recent Appointments
        </h5>

        <small class="text-secondary">
            Latest appointments in the selected period.
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
                    <th>Time</th>
                    <th>Status</th>

                </tr>

            </thead>


            <tbody>

                <?php foreach ($recent_rows as $row): ?>

                    <tr>

                        <td>
                            <strong>
                                <?=h($row['appointment_ref'])?>
                            </strong>
                        </td>

                        <td>
                            <?=h($row['patient_name'])?>
                        </td>

                        <td>
                            Dr. <?=h($row['doctor_name'])?>
                        </td>

                        <td>
                            <?=h(
                                date(
                                    'd M Y',
                                    strtotime(
                                        $row['appointment_date']
                                    )
                                )
                            )?>
                        </td>

                        <td>
                            <?=h(
                                substr(
                                    $row['start_time'],
                                    0,
                                    5
                                )
                            )?>
                        </td>

                        <td>

                            <span class="status-pill bg-light border">
                                <?=h($row['status'])?>
                            </span>

                        </td>

                    </tr>

                <?php endforeach; ?>


                <?php if (!$recent_rows): ?>

                    <tr>

                        <td colspan="6">

                            <div class="empty-state p-4 text-center">
                                No recent appointments found.
                            </div>

                        </td>

                    </tr>

                <?php endif; ?>

            </tbody>

        </table>

    </div>

</div>


<?php require '../includes/footer.php'; ?>