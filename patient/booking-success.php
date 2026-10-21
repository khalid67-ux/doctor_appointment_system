<?php

declare(strict_types=1);

$page_title = 'Booking Successful';
$asset_prefix = '../';

require_once __DIR__ . '/../config/mongodb.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';

require_role('patient');

$booking = $_SESSION['completed_booking'] ?? null;

if (
    !is_array($booking)
    || empty($booking['appointment_id'])
    || empty($booking['appointment_ref'])
) {
    flash('danger', 'No completed booking found.');
    header('Location: appointments.php');
    exit;
}

// Get appointment and payment details from MongoDB
$patientId = (int) get_patient_id((int) $_SESSION['user_id']);

$appointmentDoc = $mongoDb->selectCollection('appointments')->findOne([
    'appointment_id' => (int) $booking['appointment_id'],
    'patient_id' => $patientId
]);

$appointment = null;

if ($appointmentDoc) {
    $appointmentData = $appointmentDoc->getArrayCopy();

    // Get doctor
    $doctorDoc = $mongoDb->selectCollection('doctors')->findOne([
        'doctor_id' => (int) ($appointmentData['doctor_id'] ?? 0)
    ]);

    $doctorData = $doctorDoc
        ? $doctorDoc->getArrayCopy()
        : [];

    // Get doctor's user information
    $doctorUserDoc = null;

    if (!empty($doctorData['user_id'])) {
        $doctorUserDoc = $mongoDb->selectCollection('users')->findOne([
            'user_id' => (int) $doctorData['user_id']
        ]);
    }

    $doctorUserData = $doctorUserDoc
        ? $doctorUserDoc->getArrayCopy()
        : [];

    // Get payment
    $paymentDoc = $mongoDb->selectCollection('payments')->findOne([
        'appointment_id' => (int) $booking['appointment_id']
    ]);

    $paymentData = $paymentDoc
        ? $paymentDoc->getArrayCopy()
        : [];

    // Build the same array structure used by the existing HTML
    if ($paymentDoc) {
        $appointment = [
            'appointment_id' => $appointmentData['appointment_id']
                ?? (int) $booking['appointment_id'],
            'appointment_ref' => $appointmentData['appointment_ref'] ?? '',
            'appointment_date' => $appointmentData['appointment_date'] ?? '',
            'start_time' => $appointmentData['start_time'] ?? '',
            'status' => $appointmentData['status'] ?? 'Unknown',
            'consultation_fee' => $doctorData['consultation_fee'] ?? 0,
            'doctor_name' => $doctorUserData['name'] ?? 'N/A',
            'payment_method' => $paymentData['payment_method'] ?? 'N/A',
            'amount' => $paymentData['amount'] ?? 0,
            'payment_status' => $paymentData['payment_status'] ?? 'Pending',
            'transaction_ref' => $paymentData['transaction_ref'] ?? 'N/A',
            'payment_date' => $paymentData['payment_date'] ?? null
        ];
    }
}

if (!$appointment) {
    flash('danger', 'Booking information not found.');
    header('Location: appointments.php');
    exit;
}

require __DIR__ . '/../includes/header.php';
?>

<div class="container py-5">

    <div class="row justify-content-center">
        <div class="col-lg-8">

            <div class="card shadow-sm border-0 text-center">

                <div class="card-body p-4 p-md-5">

                    <div class="mb-3">
                        <i
                            class="bi bi-check-circle-fill text-success"
                            style="font-size: 65px;"
                        ></i>
                    </div>

                    <h2 class="text-success mb-2">
                        Booking Successful!
                    </h2>

                    <p class="text-muted mb-4">
                        Your appointment has been created successfully.
                    </p>

                    <div class="alert alert-success">
                        Your demo payment has been recorded.
                    </div>

                    <div class="border rounded p-3 text-start mb-4">

                        <h5 class="mb-3 text-center">
                            Appointment Details
                        </h5>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Appointment Reference</span>
                            <strong>
                                <?= h($appointment['appointment_ref']) ?>
                            </strong>
                        </div>

                        <hr>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Doctor</span>
                            <strong>
                                <?= h($appointment['doctor_name']) ?>
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Date</span>
                            <strong>
                                <?= h(date(
                                    'd M Y',
                                    strtotime($appointment['appointment_date'])
                                )) ?>
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Time</span>
                            <strong>
                                <?= h(date(
                                    'h:i A',
                                    strtotime($appointment['start_time'])
                                )) ?>
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Appointment Status</span>
                            <strong>
                                <?= h($appointment['status']) ?>
                            </strong>
                        </div>

                        <hr>

                        <h5 class="mb-3 text-center">
                            Payment Details
                        </h5>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Payment Method</span>
                            <strong>
                                <?= h($appointment['payment_method']) ?>
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Payment Status</span>
                            <strong class="text-success">
                                <?= h($appointment['payment_status']) ?>
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between mb-2">
                            <span>Amount</span>
                            <strong>
                                ৳<?= number_format(
                                    (float)$appointment['amount'],
                                    2
                                ) ?>
                            </strong>
                        </div>

                        <div class="d-flex justify-content-between">
                            <span>Transaction Reference</span>
                            <strong>
                                <?= h($appointment['transaction_ref']) ?>
                            </strong>
                        </div>

                    </div>

                    <div class="d-flex flex-wrap justify-content-center gap-2">

                        <a
                            href="appointments.php"
                            class="btn btn-primary"
                        >
                            <i class="bi bi-calendar-check me-1"></i>
                            My Appointments
                        </a>

                        <a
                            href="booking-receipt.php?id=<?= (int)$appointment['appointment_id'] ?>"
                            class="btn btn-outline-success"
                        >
                            <i class="bi bi-file-earmark-pdf me-1"></i>
                            Download PDF Receipt
                        </a>

                    </div>

                    <p class="text-muted small mt-3 mb-0">
                        This is a demo payment confirmation.
                        No real money was charged.
                    </p>

                </div>
            </div>

        </div>
    </div>

</div>

<?php
require __DIR__ . '/../includes/footer.php';