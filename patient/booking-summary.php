<?php

declare(strict_types=1);

$page_title = 'Booking Summary';
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
) {
    flash('danger', 'Please select an appointment first.');
    header('Location: doctors.php');
    exit;
}

if (
    time() - (int)($booking['created_at'] ?? 0) > 1800
) {
    unset($_SESSION['pending_booking']);
    flash('danger', 'Your booking session has expired. Please try again.');
    header('Location: doctors.php');
    exit;
}

// Get doctor information from MongoDB
$doctorDoc = $mongoDb->selectCollection('doctors')->findOne([
    'doctor_id' => (int) $booking['doctor_id']
]);

$doctor = null;

if ($doctorDoc) {
    $doctorData = $doctorDoc->getArrayCopy();

    // Get doctor's user information
    $doctorUserDoc = $mongoDb->selectCollection('users')->findOne([
        'user_id' => (int) ($doctorData['user_id'] ?? 0)
    ]);

    $doctorUser = $doctorUserDoc
        ? $doctorUserDoc->getArrayCopy()
        : [];

    // Get specialization
    $specialization = 'General';

    if (!empty($doctorData['specialization_id'])) {
        $specializationDoc = $mongoDb
            ->selectCollection('specializations')
            ->findOne([
                'specialization_id' => (int) $doctorData['specialization_id']
            ]);

        if ($specializationDoc) {
            $specializationData = $specializationDoc->getArrayCopy();
            $specialization = $specializationData['name'] ?? 'General';
        }
    }

    $doctor = [
        'doctor_id' => $doctorData['doctor_id'] ?? null,
        'qualification' => $doctorData['qualification'] ?? 'N/A',
        'consultation_fee' => $doctorData['consultation_fee'] ?? 0,
        'doctor_name' => $doctorUser['name'] ?? 'N/A',
        'specialization' => $specialization
    ];
}

// Get branch and clinic information from MongoDB
$branchDoc = $mongoDb->selectCollection('branches')->findOne([
    'branch_id' => (int) $booking['branch_id']
]);

$branch = null;

if ($branchDoc) {
    $branchData = $branchDoc->getArrayCopy();

    $clinicDoc = $mongoDb->selectCollection('clinics')->findOne([
        'clinic_id' => (int) ($branchData['clinic_id'] ?? 0)
    ]);

    $clinicData = $clinicDoc
        ? $clinicDoc->getArrayCopy()
        : [];

    if ($clinicDoc) {
        $branch = [
            'branch_name' => $branchData['branch_name'] ?? 'N/A',
            'address' => $branchData['address'] ?? 'N/A',
            'clinic_name' => $clinicData['name'] ?? 'N/A'
        ];
    }
}

if (!$doctor || !$branch) {
    unset($_SESSION['pending_booking']);
    flash('danger', 'Booking information could not be found.');
    header('Location: doctors.php');
    exit;
}

$consultationFee = $doctor['consultation_fee'] ?? 0;

$fee = $consultationFee instanceof MongoDB\BSON\Decimal128
    ? (float) (string) $consultationFee
    : (float) $consultationFee;

require __DIR__ . '/../includes/header.php';
?>

<style>
/* Booking Summary Styling */
.booking-summary-page {
    background: #f5f8fc;
    min-height: 80vh;
    padding: 55px 15px;
}

.booking-summary-card {
    border: 1px solid #e2e8f0 !important;
    border-radius: 16px !important;
    overflow: hidden;
    box-shadow: 0 8px 30px rgba(15, 23, 42, 0.06) !important;
}

.booking-summary-card .card-body {
    padding: 38px !important;
}

.booking-title {
    color: #172554;
    font-size: 32px;
    font-weight: 700;
    text-align: center;
    letter-spacing: -0.5px;
}

.booking-subtitle {
    color: #64748b;
    font-size: 17px;
    text-align: center;
    margin-bottom: 30px;
}

.summary-section {
    background: #ffffff;
    border: 1px solid #e2e8f0 !important;
    border-radius: 12px !important;
    padding: 22px !important;
    margin-bottom: 22px !important;
    transition: box-shadow 0.2s ease;
}

.summary-section:hover {
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
}

.summary-section h5 {
    color: #1e3a8a;
    font-size: 20px;
    font-weight: 700;
    padding-bottom: 13px;
    margin-bottom: 18px !important;
    border-bottom: 1px solid #edf2f7;
}

.summary-section p {
    color: #475569;
    font-size: 16px;
    line-height: 1.8;
}

.summary-section p strong {
    color: #334155;
    font-weight: 600;
}

.payment-section {
    background: #f8faff;
    border: 1px solid #cbdcfb !important;
}

.payment-section h5 {
    color: #1d4ed8;
}

.payment-total {
    color: #1d4ed8 !important;
    font-size: 21px !important;
    font-weight: 700;
}

.booking-btn {
    border-radius: 8px;
    padding: 13px 22px;
    font-size: 16px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.booking-btn-primary {
    background: #2563eb;
    border: 1px solid #2563eb;
    color: #fff;
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.18);
}

.booking-btn-primary:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #fff;
    transform: translateY(-1px);
}

.booking-btn-secondary {
    background: #fff;
    border: 1px solid #cbd5e1;
    color: #475569;
}

.booking-btn-secondary:hover {
    background: #f1f5f9;
    border-color: #94a3b8;
    color: #1e293b;
}

.booking-note {
    color: #64748b;
    font-size: 12px;
}

@media (max-width: 576px) {
    .booking-summary-page {
        padding: 25px 10px;
    }

    .booking-summary-card .card-body {
        padding: 22px !important;
    }

    .booking-title {
        font-size: 26px;
    }

    .summary-section {
        padding: 17px !important;
    }

    .booking-btn {
        width: 100%;
    }
}
</style>

<div class="booking-summary-page">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">

                <div class="card booking-summary-card">

                    <div class="card-body">

                        <h2 class="booking-title mb-2">
                            Booking Summary
                        </h2>

                        <p class="booking-subtitle">
                            Please review your appointment details.
                        </p>

                        <!-- Doctor Information -->
                        <div class="summary-section">

                            <h5>
                                <i class="bi bi-person-badge me-2"></i>
                                Doctor Information
                            </h5>

                            <p class="mb-2">
                                <strong>Doctor:</strong>
                                <?= h($doctor['doctor_name']) ?>
                            </p>

                            <p class="mb-2">
                                <strong>Specialization:</strong>
                                <?= h($doctor['specialization'] ?? 'General') ?>
                            </p>

                            <p class="mb-0">
                                <strong>Qualification:</strong>
                                <?= h($doctor['qualification'] ?? 'N/A') ?>
                            </p>

                        </div>

                        <!-- Appointment Details -->
                        <div class="summary-section">

                            <h5>
                                <i class="bi bi-calendar-check me-2"></i>
                                Appointment Details
                            </h5>

                            <p class="mb-2">
                                <strong>Clinic:</strong>
                                <?= h($branch['clinic_name']) ?>
                            </p>

                            <p class="mb-2">
                                <strong>Branch:</strong>
                                <?= h($branch['branch_name']) ?>
                            </p>

                            <p class="mb-2">
                                <strong>Address:</strong>
                                <?= h($branch['address'] ?? 'N/A') ?>
                            </p>

                            <p class="mb-2">
                                <strong>Date:</strong>
                                <?= h(date(
                                    'd M Y',
                                    strtotime($booking['appointment_date'])
                                )) ?>
                            </p>

                            <p class="mb-0">
                                <strong>Time:</strong>
                                <?= h(date(
                                    'h:i A',
                                    strtotime($booking['start_time'])
                                )) ?>
                            </p>

                        </div>

                        <!-- Payment Summary -->
                        <div class="summary-section payment-section">

                            <h5>
                                <i class="bi bi-credit-card me-2"></i>
                                Payment Summary
                            </h5>

                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <span class="text-muted">
                                    Consultation Fee
                                </span>

                                <strong>
                                    ৳<?= number_format($fee, 2) ?>
                                </strong>
                            </div>

                            <hr>

                            <div class="d-flex justify-content-between align-items-center">
                                <strong class="text-dark">
                                    Total Amount
                                </strong>

                                <strong class="payment-total">
                                    ৳<?= number_format($fee, 2) ?>
                                </strong>
                            </div>

                        </div>

                        <!-- Action Buttons -->
                        <div class="d-flex flex-wrap gap-2">

                            <a
                                href="book.php?doctor_id=<?= (int)$booking['doctor_id'] ?>&date=<?= urlencode($booking['appointment_date']) ?>&time=<?= urlencode($booking['start_time']) ?>&branch_id=<?= (int)$booking['branch_id'] ?>"
                                class="btn booking-btn booking-btn-secondary"
                            >
                                <i class="bi bi-arrow-left me-1"></i>
                                Back
                            </a>

                            <a
                                href="payment-method.php"
                                class="btn booking-btn booking-btn-primary ms-auto"
                            >
                                Continue to Payment
                                <i class="bi bi-arrow-right ms-1"></i>
                            </a>

                        </div>

                        <p class="booking-note mt-3 mb-0">
                            <i class="bi bi-info-circle me-1"></i>
                            This is a demo payment system. No real money will be charged.
                        </p>

                    </div>
                </div>

            </div>
        </div>
    </div>
</div>

<?php
require __DIR__ . '/../includes/footer.php';