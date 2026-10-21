<?php

declare(strict_types=1);

$page_title = 'Payment Confirmation';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('patient');

$booking = $_SESSION['pending_booking'] ?? null;

if (
    !is_array($booking)
    || empty($booking['patient_id'])
    || empty($booking['doctor_id'])
    || empty($booking['branch_id'])
    || empty($booking['appointment_date'])
    || empty($booking['start_time'])
    || empty($booking['payment_method'])
) {
    flash('danger', 'Please complete your booking first.');
    header('Location: doctors.php');
    exit;
}

if (time() - (int)($booking['created_at'] ?? 0) > 1800) {
    unset($_SESSION['pending_booking']);
    flash('danger', 'Your booking session has expired.');
    header('Location: doctors.php');
    exit;
}

$pid = (int)$booking['patient_id'];
$did = (int)$booking['doctor_id'];
$branchId = (int)$booking['branch_id'];
$date = (string)$booking['appointment_date'];
$time = (string)$booking['start_time'];
$method = (string)$booking['payment_method'];

$allowedMethods = ['Cash', 'bKash', 'Card'];

if (!in_array($method, $allowedMethods, true)) {
    unset($_SESSION['pending_booking']);
    flash('danger', 'Invalid payment method.');
    header('Location: doctors.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Verify Patient Session
|--------------------------------------------------------------------------
*/

if ($pid !== (int)get_patient_id((int)$_SESSION['user_id'])) {
    unset($_SESSION['pending_booking']);
    flash('danger', 'Invalid patient information.');
    header('Location: doctors.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Doctor Information
|--------------------------------------------------------------------------
*/

$doctorsCollection = $mongoDb->selectCollection('doctors');
$usersCollection = $mongoDb->selectCollection('users');

$doctorDoc = $doctorsCollection->findOne([
    'doctor_id' => $did,
    'verification_status' => 'Approved'
]);

$doctor = null;

if ($doctorDoc) {
    $doctorUser = $usersCollection->findOne([
        'user_id' => (int) ($doctorDoc->user_id ?? 0),
        'status' => 'active'
    ]);

    if ($doctorUser) {
        $doctor = [
            'doctor_id' => $did,
            'consultation_fee' => $doctorDoc->consultation_fee ?? 0,
            'user_id' => (int) $doctorUser->user_id,
            'doctor_name' => (string) ($doctorUser->name ?? 'N/A')
        ];
    }
}

if (!$doctor) {
    unset($_SESSION['pending_booking']);
    flash('danger', 'Doctor is no longer available.');
    header('Location: doctors.php');
    exit;
}

$feeValue = $doctor['consultation_fee'] ?? 0;

$fee = $feeValue instanceof MongoDB\BSON\Decimal128
    ? (float) (string) $feeValue
    : (float) $feeValue;

$appointmentsCollection = $mongoDb->selectCollection('appointments');
$paymentsCollection = $mongoDb->selectCollection('payments');
$countersCollection = $mongoDb->selectCollection('counters');

foreach ([
    'appointment_id' => $appointmentsCollection,
    'payment_id' => $paymentsCollection
] as $counterName => $collection) {
    $latestDocument = $collection->find(
        [],
        [
            'projection' => [$counterName => 1],
            'sort' => [$counterName => -1],
            'limit' => 1
        ]
    )->toArray();

    $maxId = $latestDocument
        ? (int) ($latestDocument[0][$counterName] ?? 0)
        : 0;

    $countersCollection->updateOne(
        ['_id' => $counterName],
        ['$max' => ['sequence_value' => $maxId]],
        ['upsert' => true]
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    verify_csrf();

    try {
        /*
        |--------------------------------------------------------------------------
        | Recheck Slot Availability
        |--------------------------------------------------------------------------
        */

        $existingAppointment = $appointmentsCollection->findOne([
            'doctor_id' => $did,
            'branch_id' => $branchId,
            'appointment_date' => $date,
            'start_time' => $time,
            'status' => [
                '$nin' => ['Cancelled', 'No-show']
            ]
        ]);

        if ($existingAppointment) {
            throw new RuntimeException(
                'This appointment slot has already been booked. Please choose another slot.'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Generate Appointment Reference
        |--------------------------------------------------------------------------
        */

        $ref = 'APT-'
            . date('Ymd')
            . '-'
            . strtoupper(bin2hex(random_bytes(3)));

        /*
        |--------------------------------------------------------------------------
        | Generate Token
        |--------------------------------------------------------------------------
        */

        $tokenNo = null;

        if (function_exists('generate_appointment_token')) {
            $tokenNo = generate_appointment_token($did, $date);
        }

        /*
        |--------------------------------------------------------------------------
        | Create Appointment
        |--------------------------------------------------------------------------
        */

        $appointmentCounter = $countersCollection->findOneAndUpdate(
            ['_id' => 'appointment_id'],
            ['$inc' => ['sequence_value' => 1]],
            [
                'returnDocument' =>
                    MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
        );

        if ($appointmentCounter === null) {
            throw new RuntimeException('Unable to generate appointment ID.');
        }

        $appointmentId = (int) $appointmentCounter['sequence_value'];

        $appointmentsCollection->insertOne([
            'appointment_id' => $appointmentId,
            'appointment_ref' => $ref,
            'patient_id' => $pid,
            'doctor_id' => $did,
            'branch_id' => $branchId,
            'appointment_date' => $date,
            'start_time' => $time,
            'status' => 'Requested',
            'token_no' => $tokenNo,
            'created_at' => date('Y-m-d H:i:s')
        ]);

        /*
        |--------------------------------------------------------------------------
        | Save Demo Payment
        |--------------------------------------------------------------------------
        */

        $transactionRef = 'DEMO-' . strtoupper(bin2hex(random_bytes(5)));

        try {
            $paymentCounter = $countersCollection->findOneAndUpdate(
                ['_id' => 'payment_id'],
                ['$inc' => ['sequence_value' => 1]],
                [
                    'returnDocument' =>
                        MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                ]
            );

            if ($paymentCounter === null) {
                throw new RuntimeException('Unable to generate payment ID.');
            }

            $paymentsCollection->insertOne([
                'payment_id' => (int) $paymentCounter['sequence_value'],
                'appointment_id' => $appointmentId,
                'payment_method' => $method,
                'amount' => $fee,
                'payment_status' => 'Paid',
                'transaction_ref' => $transactionRef,
                'payment_date' => date('Y-m-d H:i:s')
            ]);
        } catch (Throwable $e) {
            try {
                $appointmentsCollection->deleteOne([
                    'appointment_id' => $appointmentId
                ]);
            } catch (Throwable $cleanupError) {
                error_log(
                    'Booking cleanup error for appointment ' .
                    $appointmentId . ': ' . $cleanupError->getMessage()
                );
            }

            throw $e;
        }

        /*
        |--------------------------------------------------------------------------
        | Notifications
        |--------------------------------------------------------------------------
        */

        create_notification(
            (int)$_SESSION['user_id'],
            'appointment',
            'Appointment Requested',
            "Appointment {$ref} requested for {$date} at "
                . substr($time, 0, 5)
                . '.'
        );

        $doctorUser = (int)($doctor['user_id'] ?? 0);

        if ($doctorUser > 0) {
            create_notification(
                $doctorUser,
                'appointment',
                'New Appointment Request',
                "Appointment {$ref} has been requested and needs review."
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Audit Log
        |--------------------------------------------------------------------------
        */

        if (function_exists('audit')) {
            audit(
                (int)$_SESSION['user_id'],
                'Booked appointment',
                'appointment',
                $appointmentId
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Save Success Information
        |--------------------------------------------------------------------------
        */

        $_SESSION['completed_booking'] = [
            'appointment_id' => $appointmentId,
            'appointment_ref' => $ref,
            'payment_method' => $method,
            'amount' => $fee,
            'transaction_ref' => $transactionRef
        ];

        unset($_SESSION['pending_booking']);

        header('Location: booking-success.php');
        exit;

    } catch (Throwable $e) {
        flash('danger', 'Payment or booking failed: ' . $e->getMessage());

        header('Location: payment-confirmation.php');
        exit;
    }
}

require __DIR__ . '/../includes/header.php';
?>

<style>
.payment-confirm-page {
    background: #f4f7fc;
    min-height: 80vh;
    padding: 55px 15px;
}

.confirm-card {
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    box-shadow: 0 8px 30px rgba(15, 23, 42, 0.07) !important;
    overflow: hidden;
}

.confirm-card .card-body {
    padding: 40px !important;
}

.confirm-title {
    color: #172554;
    font-size: 30px;
    font-weight: 700;
    text-align: center;
    margin-bottom: 10px;
}

.confirm-subtitle {
    color: #64748b;
    font-size: 16px;
    text-align: center;
    margin-bottom: 28px;
}

.demo-notice {
    display: flex;
    align-items: center;
    gap: 10px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    padding: 16px 18px;
    color: #1e40af;
    font-size: 15px;
    margin-bottom: 22px;
}

.demo-notice i {
    font-size: 21px;
    flex-shrink: 0;
}

.confirm-section {
    background: #fff;
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    padding: 22px !important;
    margin-bottom: 22px !important;
    transition: box-shadow 0.2s ease;
}

.confirm-section:hover {
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
}

.confirm-section h5 {
    color: #1e3a8a;
    font-size: 19px;
    font-weight: 700;
    padding-bottom: 13px;
    margin-bottom: 16px !important;
    border-bottom: 1px solid #edf2f7;
}

.confirm-section p {
    color: #475569;
    font-size: 16px;
    line-height: 1.8;
}

.confirm-section p strong {
    color: #334155;
    font-weight: 600;
}

.confirm-amount {
    color: #2563eb !important;
    font-size: 19px;
    font-weight: 700;
}

.confirm-actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    margin-top: 28px;
}

.confirm-btn {
    border-radius: 9px;
    padding: 12px 22px;
    font-size: 15px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.confirm-btn-back {
    background: #fff;
    color: #475569;
    border: 1px solid #cbd5e1;
}

.confirm-btn-back:hover {
    background: #f1f5f9;
    color: #1e293b;
}

.confirm-btn-submit {
    margin-left: auto;
    background: #15803d;
    border: 1px solid #15803d;
    color: #fff;
    box-shadow: 0 4px 12px rgba(21, 128, 61, 0.18);
}

.confirm-btn-submit:hover {
    background: #166534;
    border-color: #166534;
    color: #fff;
    transform: translateY(-1px);
}

@media (max-width: 576px) {
    .payment-confirm-page {
        padding: 25px 10px;
    }

    .confirm-card .card-body {
        padding: 23px !important;
    }

    .confirm-title {
        font-size: 25px;
    }

    .confirm-section {
        padding: 17px !important;
    }

    .confirm-btn {
        padding: 11px 15px;
    }
}
</style>

<div class="payment-confirm-page">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7">

                <div class="card confirm-card">
                    <div class="card-body">

                        <h2 class="confirm-title">
                            Payment Confirmation
                        </h2>

                        <p class="confirm-subtitle">
                            Review your payment details before confirming.
                        </p>

                        <div class="demo-notice">
                            <i class="bi bi-shield-check"></i>
                            <div>
                                <strong>Demo Payment:</strong>
                                This is a simulation. No real money will be charged.
                            </div>
                        </div>

                        <!-- Appointment Details -->
                        <div class="confirm-section">

                            <h5>
                                <i class="bi bi-calendar-check me-2"></i>
                                Appointment Details
                            </h5>

                            <p class="mb-2">
                                <strong>Doctor:</strong>
                                <?= h($doctor['doctor_name']) ?>
                            </p>

                            <p class="mb-2">
                                <strong>Date:</strong>
                                <?= h(date('d M Y', strtotime($date))) ?>
                            </p>

                            <p class="mb-0">
                                <strong>Time:</strong>
                                <?= h(date('h:i A', strtotime($time))) ?>
                            </p>

                        </div>

                        <!-- Payment Details -->
                        <div class="confirm-section">

                            <h5>
                                <i class="bi bi-credit-card me-2"></i>
                                Payment Details
                            </h5>

                            <p class="mb-2">
                                <strong>Payment Method:</strong>
                                <?= h($method) ?>
                            </p>

                            <p class="mb-0">
                                <strong>Total Amount:</strong>
                                <span class="confirm-amount">
                                    ৳<?= number_format($fee, 2) ?>
                                </span>
                            </p>

                        </div>

                        <form method="post">
                            <?= csrf_field() ?>

                            <div class="confirm-actions">

                                <a
                                    href="payment-method.php"
                                    class="btn confirm-btn confirm-btn-back"
                                >
                                    <i class="bi bi-arrow-left me-1"></i>
                                    Back
                                </a>

                                <button
                                    type="submit"
                                    class="btn confirm-btn confirm-btn-submit"
                                >
                                    <i class="bi bi-check-circle me-1"></i>
                                    Confirm Demo Payment
                                </button>

                            </div>
                        </form>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';