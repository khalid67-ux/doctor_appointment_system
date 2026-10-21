<?php

declare(strict_types=1);

$page_title = 'Select Payment Method';
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

if (time() - (int)($booking['created_at'] ?? 0) > 1800) {
    unset($_SESSION['pending_booking']);
    flash('danger', 'Your booking session has expired.');
    header('Location: doctors.php');
    exit;
}

$doctorDoc = $mongoDb->doctors->findOne([
    'doctor_id' => (int) $booking['doctor_id']
]);

$doctor = null;

if ($doctorDoc) {
    $doctor = [
        'consultation_fee' => (
            $doctorDoc->consultation_fee ?? 0
        ) instanceof MongoDB\BSON\Decimal128
            ? (float) (string) $doctorDoc->consultation_fee
            : (float) ($doctorDoc->consultation_fee ?? 0)
    ];
}

if (!$doctor) {
    unset($_SESSION['pending_booking']);
    flash('danger', 'Doctor not found.');
    header('Location: doctors.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $method = trim((string)($_POST['payment_method'] ?? ''));

    $allowedMethods = ['Cash', 'bKash', 'Card'];

    if (!in_array($method, $allowedMethods, true)) {
        flash('danger', 'Please select a valid payment method.');
    } else {
        $_SESSION['pending_booking']['payment_method'] = $method;

        header('Location: payment-confirmation.php');
        exit;
    }
}

require __DIR__ . '/../includes/header.php';
?>

<style>
.payment-page {
    background: #f4f7fc;
    min-height: 80vh;
    padding: 55px 15px;
}

.payment-card {
    border: 1px solid #e5eaf2 !important;
    border-radius: 18px !important;
    box-shadow: 0 8px 30px rgba(15, 23, 42, 0.07) !important;
    overflow: hidden;
}

.payment-card .card-body {
    padding: 40px !important;
}

.payment-title {
    text-align: center;
    color: #172554;
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 10px;
}

.payment-subtitle {
    text-align: center;
    color: #64748b;
    font-size: 17px;
    margin-bottom: 28px;
}

/* Demo notice */
.demo-notice {
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    border-radius: 10px;
    padding: 16px 18px;
    color: #1e40af;
    font-size: 16px;
    margin-bottom: 22px;
}

.demo-notice i {
    font-size: 20px;
    margin-right: 8px;
}

/* Fee card */
.fee-card {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 28px;
    color: #334155;
    font-size: 17px;
}

.fee-amount {
    color: #1d4ed8;
    font-size: 20px;
    font-weight: 700;
}

/* Payment options */
.payment-label {
    color: #1e293b;
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 13px;
}

.payment-option {
    display: flex;
    align-items: center;
    gap: 15px;
    padding: 17px 18px !important;
    margin-bottom: 13px !important;
    border: 1.5px solid #e2e8f0 !important;
    border-radius: 12px !important;
    background: #fff;
    cursor: pointer;
    transition: all 0.2s ease;
    min-height: 78px;
}

.payment-option:hover {
    border-color: #93c5fd !important;
    background: #f8fbff;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.07);
    transform: translateY(-1px);
}

.payment-option:has(input:checked) {
    border-color: #2563eb !important;
    background: #eff6ff;
    box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.10);
}

.payment-option input[type="radio"] {
    width: 18px;
    height: 18px;
    margin: 0;
    flex-shrink: 0;
    cursor: pointer;
    accent-color: #2563eb;
}

.payment-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 23px;
    flex-shrink: 0;
}

.cash-icon {
    background: #dcfce7;
    color: #15803d;
}

.bkash-icon {
    background: #fce7f3;
    color: #db2777;
}

.card-icon {
    background: #dbeafe;
    color: #2563eb;
}

.payment-info {
    flex: 1;
}

.payment-name {
    display: block;
    font-size: 17px;
    font-weight: 700;
    color: #1e293b;
    margin-bottom: 3px;
}

.payment-description {
    display: block;
    font-size: 14px;
    color: #64748b;
}

.payment-badge {
    font-size: 12px;
    font-weight: 700;
    padding: 5px 9px;
    border-radius: 20px;
    background: #fce7f3;
    color: #be185d;
}

.demo-badge {
    background: #dbeafe;
    color: #1d4ed8;
}

/* Buttons */
.payment-actions {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-top: 30px;
}

.payment-btn {
    border-radius: 9px;
    padding: 12px 22px;
    font-size: 16px;
    font-weight: 600;
    transition: all 0.2s ease;
}

.payment-btn-back {
    color: #475569;
    background: #fff;
    border: 1px solid #cbd5e1;
}

.payment-btn-back:hover {
    background: #f1f5f9;
    color: #1e293b;
}

.payment-btn-continue {
    margin-left: auto;
    background: #2563eb;
    border: 1px solid #2563eb;
    color: #fff;
    box-shadow: 0 4px 12px rgba(37, 99, 235, 0.20);
}

.payment-btn-continue:hover {
    background: #1d4ed8;
    border-color: #1d4ed8;
    color: #fff;
    transform: translateY(-1px);
}

.payment-footer-note {
    text-align: center;
    color: #64748b;
    font-size: 14px;
    margin-top: 22px;
    margin-bottom: 0;
}

@media (max-width: 576px) {
    .payment-page {
        padding: 25px 10px;
    }

    .payment-card .card-body {
        padding: 23px !important;
    }

    .payment-title {
        font-size: 27px;
    }

    .payment-option {
        padding: 14px !important;
        gap: 10px;
    }

    .payment-badge {
        font-size: 11px;
    }

    .payment-btn {
        padding: 11px 15px;
    }
}
</style>

<div class="payment-page">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-7">

                <div class="card payment-card">
                    <div class="card-body">

                        <h2 class="payment-title">
                            Select Payment Method
                        </h2>

                        <p class="payment-subtitle">
                            Choose how you want to pay for your appointment.
                        </p>

                        <div class="demo-notice">
                            <i class="bi bi-shield-check"></i>
                            <strong>Demo Payment</strong>
                            <span> — No real money will be charged.</span>
                        </div>

                        <div class="fee-card">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="bi bi-receipt me-2"></i>
                                    Consultation Fee
                                </span>

                                <strong class="fee-amount">
                                    ৳<?= number_format(
                                        (float)$doctor['consultation_fee'],
                                        2
                                    ) ?>
                                </strong>
                            </div>
                        </div>

                        <form method="post">
                            <?= csrf_field() ?>

                            <div class="mb-3">
                                <label class="form-label payment-label">
                                    Choose Payment Method
                                </label>

                                <!-- Cash -->
                                <label class="form-check payment-option" for="cash">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="payment_method"
                                        id="cash"
                                        value="Cash"
                                        required
                                    >

                                    <span class="payment-icon cash-icon">
                                        <i class="bi bi-cash-stack"></i>
                                    </span>

                                    <span class="payment-info">
                                        <span class="payment-name">Cash</span>
                                        <span class="payment-description">
                                            Pay at the clinic
                                        </span>
                                    </span>

                                    <span class="payment-badge">
                                        Offline
                                    </span>
                                </label>

                                <!-- bKash -->
                                <label class="form-check payment-option" for="bkash">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="payment_method"
                                        id="bkash"
                                        value="bKash"
                                        required
                                    >

                                    <span class="payment-icon bkash-icon">
                                        <i class="bi bi-phone"></i>
                                    </span>

                                    <span class="payment-info">
                                        <span class="payment-name">
                                            bKash
                                        </span>
                                        <span class="payment-description">
                                            Mobile banking payment
                                        </span>
                                    </span>

                                    <span class="payment-badge">
                                        Demo
                                    </span>
                                </label>

                                <!-- Card -->
                                <label class="form-check payment-option" for="card">
                                    <input
                                        class="form-check-input"
                                        type="radio"
                                        name="payment_method"
                                        id="card"
                                        value="Card"
                                        required
                                    >

                                    <span class="payment-icon card-icon">
                                        <i class="bi bi-credit-card"></i>
                                    </span>

                                    <span class="payment-info">
                                        <span class="payment-name">
                                            Debit / Credit Card
                                        </span>
                                        <span class="payment-description">
                                            Card payment simulation
                                        </span>
                                    </span>

                                    <span class="payment-badge demo-badge">
                                        Demo
                                    </span>
                                </label>
                            </div>

                            <div class="payment-actions">

                                <a
                                    href="booking-summary.php"
                                    class="btn payment-btn payment-btn-back"
                                >
                                    <i class="bi bi-arrow-left me-1"></i>
                                    Back
                                </a>

                                <button
                                    type="submit"
                                    class="btn payment-btn payment-btn-continue"
                                >
                                    Continue
                                    <i class="bi bi-arrow-right ms-1"></i>
                                </button>

                            </div>
                        </form>

                        <p class="payment-footer-note">
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